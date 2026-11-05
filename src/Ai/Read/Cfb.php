<?php
declare(strict_types=1);

namespace Align\Ai\Read;

/**
 * 2.29.0 A minimal, read-only reader for OLE Compound Files (CFB, [MS-CFB]), the container of Outlook .msg files:
 * the header, the FAT (with extra DIFAT sectors), the directory, and the mini stream with its mini FAT for small
 * streams. 512- and 4096-byte sectors. Only reading: nothing is written.
 *
 * Security assumptions: the file is untrusted. Every sector id is checked to be inside the file, every chain stops
 * at the first repeated or out-of-range sector (so a FAT that loops can't spin), the directory tree is walked with a
 * visited set, and the bytes materialised across all streams are capped at MAX_BYTES. Anything malformed throws
 * InvalidArgumentException with a sentence for people.
 */
final class Cfb
{
    public const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    /** Bytes read out of streams in total (a file can point many entries at the same sectors). */
    private const MAX_BYTES = 25 * 1024 * 1024;
    private const ENDOFCHAIN = 0xFFFFFFFE;
    private const NOSTREAM = 0xFFFFFFFF;

    private string $b;
    private int $sectorSize;
    private int $sectors;
    private int $miniCutoff;
    /** @var list<int> FAT: sector => next sector */
    private array $fat = [];
    /** @var list<int> mini FAT: mini sector => next mini sector */
    private array $miniFat = [];
    private string $mini = '';
    private int $spent = 0;
    /** @var list<array{name:string, type:int, left:int, right:int, child:int, start:int, size:int}> */
    private array $entries = [];

    /** Reads the header, FAT, directory and mini stream of $bytes (untrusted). */
    public function __construct(string $bytes)
    {
        if (strlen($bytes) < 512 || !str_starts_with($bytes, self::SIGNATURE)) {
            throw new \InvalidArgumentException('That file isn\'t a real Outlook message.');
        }
        $this->b = $bytes;
        $h = unpack('vminor/vmajor/vorder/vshift/vminishift', $bytes, 0x18);
        if (!in_array($h['shift'], [9, 12], true) || $h['minishift'] !== 6) {
            throw new \InvalidArgumentException('That Outlook message couldn\'t be read.');
        }
        $this->sectorSize = 1 << $h['shift'];
        $this->sectors = intdiv(max(0, strlen($bytes) - $this->sectorSize), $this->sectorSize) + 1; // a short last sector counts
        $f = unpack('VnumFat/VdirStart/Vtx/Vcutoff/VminiFatStart/VnumMiniFat/VdifatStart/VnumDifat', $bytes, 0x2C);
        $this->miniCutoff = $f['cutoff'] > 0 && $f['cutoff'] <= 1 << 20 ? $f['cutoff'] : 4096;

        // No more FAT sectors than it takes to map every sector of the file: a header claiming more (or listing one
        // sector many times) would otherwise build a FAT far larger than the file
        $maxFat = intdiv($this->sectors, intdiv($this->sectorSize, 4)) + 1;
        // FAT sector ids: 109 in the header, then DIFAT sectors (each ending with the next DIFAT sector's id)
        $fatIds = array_values(unpack('V109', $bytes, 0x4C));
        $next = $f['difatStart'];
        $seen = [];
        $perDifat = intdiv($this->sectorSize, 4) - 1;
        while ($next < self::ENDOFCHAIN && count($seen) < $f['numDifat'] + 1 && $this->valid($next) && !isset($seen[$next]) && count($fatIds) <= $maxFat + 109) {
            $seen[$next] = true;
            $ids = array_values(unpack('V' . ($perDifat + 1), $this->sector($next)));
            $next = array_pop($ids);
            array_push($fatIds, ...$ids);
        }
        $fatIds = array_slice(array_values(array_unique(array_filter($fatIds, fn($id) => $id < self::ENDOFCHAIN))), 0, max(0, min($f['numFat'], $maxFat)));
        foreach ($fatIds as $id) {
            if (!$this->valid($id)) {
                throw new \InvalidArgumentException('That Outlook message couldn\'t be read.');
            }
            array_push($this->fat, ...array_values(unpack('V*', $this->sector($id))));
        }

        // Directory: 128-byte entries in the directory chain
        $dir = $this->chain($f['dirStart']);
        for ($o = 0; $o + 128 <= strlen($dir) && count($this->entries) < 100_000; $o += 128) {
            $e = unpack('vnameLen/Ctype/Ccolor/Vleft/Vright/Vchild', $dir, $o + 0x40);
            $s = unpack('Vstart/Vsize/Vhigh', $dir, $o + 0x74);
            $len = min(64, max(0, $e['nameLen'] - 2)); // the length counts the closing NUL character
            $this->entries[] = [
                'name' => mb_scrub(mb_convert_encoding(substr($dir, $o, $len), 'UTF-8', 'UTF-16LE'), 'UTF-8'),
                'type' => $e['type'], 'left' => $e['left'], 'right' => $e['right'], 'child' => $e['child'],
                // version 3 files may leave junk in the high 32 bits of the size
                'start' => $s['start'], 'size' => $this->sectorSize === 512 ? $s['size'] : ($s['high'] ? PHP_INT_MAX : $s['size']),
            ];
        }
        if (!$this->entries || $this->entries[0]['type'] !== 5) {
            throw new \InvalidArgumentException('That Outlook message couldn\'t be read.');
        }
        // The mini stream lives in the root entry's chain; the mini FAT in its own chain
        $root = $this->entries[0];
        if ($root['size'] > 0 && $root['start'] < self::ENDOFCHAIN) {
            $this->mini = substr($this->chain($root['start'], $root['size']), 0, $root['size']);
        }
        if ($f['numMiniFat'] > 0 && $f['miniFatStart'] < self::ENDOFCHAIN) {
            $mf = $this->chain($f['miniFatStart']);
            $this->miniFat = $mf === '' ? [] : array_values(unpack('V*', substr($mf, 0, strlen($mf) - strlen($mf) % 4)));
        }
    }

    /** Whether a sector id is inside the file. */
    private function valid(int $id): bool
    {
        return $id >= 0 && $id < $this->sectors;
    }

    /** One sector's bytes (a short last sector is padded with NULs). */
    private function sector(int $id): string
    {
        return str_pad(substr($this->b, ($id + 1) * $this->sectorSize, $this->sectorSize), $this->sectorSize, "\0");
    }

    /**
     * The bytes of a FAT chain from $start, at most $max bytes (null: the whole chain). Stops at the end marker, at a
     * repeated or out-of-range sector, or at the MAX_BYTES budget.
     */
    private function chain(int $start, ?int $max = null): string
    {
        $out = '';
        $seen = [];
        $id = $start;
        while ($id < self::ENDOFCHAIN && $this->valid($id) && !isset($seen[$id]) && ($max === null || strlen($out) < $max)) {
            $seen[$id] = true;
            $this->spend($this->sectorSize);
            $out .= $this->sector($id);
            $id = $this->fat[$id] ?? self::ENDOFCHAIN;
        }
        return $out;
    }

    /** Counts bytes read against MAX_BYTES. */
    private function spend(int $n): void
    {
        $this->spent += $n;
        if ($this->spent > self::MAX_BYTES) {
            throw new \InvalidArgumentException('That Outlook message is too large to read.');
        }
    }

    /**
     * Every directory entry: name, type (1 storage, 2 stream, 5 root), left/right sibling and child ids, start
     * sector and size. Entry 0 is the root.
     *
     * @return list<array{name:string, type:int, left:int, right:int, child:int, start:int, size:int}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * The direct children of a storage (or the root, id 0) as name => entry id. The children form a tree through the
     * left and right sibling ids; it is walked with a visited set so a crafted loop ends.
     *
     * @return array<string, int>
     */
    public function children(int $storage): array
    {
        $out = [];
        $stack = [$this->entries[$storage]['child'] ?? self::NOSTREAM];
        $seen = [];
        while ($stack) {
            $id = array_pop($stack);
            if ($id === self::NOSTREAM || !isset($this->entries[$id]) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $e = $this->entries[$id];
            $out[$e['name']] ??= $id;
            $stack[] = $e['left'];
            $stack[] = $e['right'];
        }
        return $out;
    }

    /** A stream's bytes (small streams from the mini stream), or '' for a storage or an empty stream. */
    public function stream(int $id): string
    {
        $e = $this->entries[$id] ?? null;
        if (!$e || $e['type'] !== 2 || $e['size'] <= 0) {
            return '';
        }
        if ($e['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException('That Outlook message is too large to read.');
        }
        if ($e['size'] >= $this->miniCutoff) {
            return substr($this->chain($e['start'], $e['size']), 0, $e['size']);
        }
        // Mini stream: 64-byte sectors chained through the mini FAT
        $out = '';
        $seen = [];
        $sid = $e['start'];
        $count = intdiv(strlen($this->mini), 64);
        while ($sid < self::ENDOFCHAIN && $sid < $count && !isset($seen[$sid]) && strlen($out) < $e['size']) {
            $seen[$sid] = true;
            $this->spend(64);
            $out .= substr($this->mini, $sid * 64, 64);
            $sid = $this->miniFat[$sid] ?? self::ENDOFCHAIN;
        }
        return substr($out, 0, $e['size']);
    }
}
