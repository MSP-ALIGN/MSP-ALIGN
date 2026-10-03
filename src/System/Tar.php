<?php
declare(strict_types=1);

namespace Align\System;

/**
 * Minimal ustar reader/writer for backup files. A backup is a plain tar of already-encrypted parts
 * (plus a plaintext manifest), so nothing unencrypted is ever written to disk. Standard tools can
 * open it: tar -xf backup.tar, then age -d each part. No external programs are used here, so the
 * web app can read a backup's manifest and the agent can stream parts straight into age.
 *
 * Security assumptions: members() parses files an admin uploaded, which may be crafted: it accepts only plain files
 * with short lower-case names (no paths, links or devices), at most $maxMembers, each inside the file, with a valid
 * header checksum and octal numbers, so nothing it returns can point outside the archive. read() and copyTo() take
 * only members returned by members() for the same file. write() is for the agent's own parts (fixed names).
 */
final class Tar
{
    /** A ustar number field (octal digits, padded with spaces or NULs), or null when it holds anything else. */
    private static function octal(string $field): ?int
    {
        $f = trim($field, " \0");
        return preg_match('/^[0-7]{1,12}$/D', $f) ? (int) octdec($f) : null;
    }

    /**
     * The archive's members by name: array<string, array{offset:int, size:int, type:string}>. Throws RuntimeException
     * (a message for the admin) on anything unexpected: a bad checksum or number field (2.2.1: octdec() skipped
     * non-octal characters, so "1x2" was read as 10 with a deprecation notice), a member that isn't a plain file or
     * runs past the end, a name that isn't a plain lower-case name, a repeated name, or too many members.
     */
    public static function members(string $path, int $maxMembers = 20): array
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            throw new \RuntimeException('Cannot open the backup file.');
        }
        $out = [];
        $total = (int) filesize($path);
        try {
            $pos = 0;
            while ($pos + 512 <= $total) {
                fseek($fh, $pos);
                $h = fread($fh, 512);
                if ($h === false || strlen($h) < 512) {
                    throw new \RuntimeException('The backup file is truncated.');
                }
                if (trim($h, "\0") === '') {
                    break; // end-of-archive blocks
                }
                $sum = 0;
                for ($i = 0; $i < 512; $i++) {
                    $sum += ($i >= 148 && $i < 156) ? 32 : ord($h[$i]);
                }
                if ($sum !== self::octal(substr($h, 148, 8))) {
                    throw new \RuntimeException('This is not an MSP-ALIGN backup (bad tar header).');
                }
                $name = rtrim(substr($h, 0, 100), "\0");
                $prefix = rtrim(substr($h, 345, 155), "\0");
                if ($prefix !== '' && substr($h, 257, 5) === 'ustar') {
                    $name = $prefix . '/' . $name;
                }
                $size = self::octal(substr($h, 124, 12));
                if ($size === null) {
                    throw new \RuntimeException('This is not an MSP-ALIGN backup (bad tar header).');
                }
                $type = substr($h, 156, 1);
                if ($type === "\0") {
                    $type = '0';
                }
                if ($size < 0 || $pos + 512 + $size > $total) {
                    throw new \RuntimeException('The backup file is truncated.');
                }
                if ($type !== '0' || !preg_match('/^[a-z0-9][a-z0-9.-]{0,60}$/', $name) || isset($out[$name])) {
                    throw new \RuntimeException('The backup file contains an unexpected entry (' . mb_substr(preg_replace('/[^\x20-\x7e]/', '?', $name), 0, 60) . ').');
                }
                $out[$name] = ['offset' => $pos + 512, 'size' => $size, 'type' => $type];
                if (count($out) > $maxMembers) {
                    throw new \RuntimeException('The backup file has too many entries.');
                }
                $pos += 512 + (int) (ceil($size / 512) * 512);
            }
        } finally {
            fclose($fh);
        }
        return $out;
    }

    /** One member's bytes, at most $max of them (larger members throw). $member must come from members($path). */
    public static function read(string $path, array $member, int $max = 1048576): string
    {
        if ($member['size'] > $max) {
            throw new \RuntimeException('Backup entry is too large.');
        }
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            throw new \RuntimeException('Cannot open the backup file.');
        }
        fseek($fh, $member['offset']);
        $d = $member['size'] ? (string) fread($fh, $member['size']) : '';
        fclose($fh);
        return $d;
    }

    /** Copies one member's bytes to a stream (for piping into age). $member must come from members($path). */
    public static function copyTo(string $path, array $member, $dest): void
    {
        $fh = fopen($path, 'rb');
        fseek($fh, $member['offset']);
        $left = $member['size'];
        while ($left > 0) {
            $chunk = fread($fh, (int) min(1048576, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $left -= strlen($chunk);
            for ($w = 0; $w < strlen($chunk);) {
                $n = @fwrite($dest, substr($chunk, $w));
                if (!$n) {
                    fclose($fh);
                    throw new \RuntimeException('Stream closed early.');
                }
                $w += $n;
            }
        }
        fclose($fh);
    }

    /**
     * Writes a tar of the given files: [member name => source path]. Names must be short plain names (the agent's
     * fixed part names); the output file must not exist yet (opened with x, so a planted file or link fails).
     */
    public static function write(string $out, array $files): void
    {
        // The header has 11 octal digits for the size (up to 8 GiB) and 100 bytes for the name: anything bigger made
        // a header longer than 512 bytes, so a corrupt backup (2.2.1). Checked before anything is written.
        foreach ($files as $name => $src) {
            if (strlen((string) $name) > 99) {
                throw new \RuntimeException("Cannot add $name to the backup: its name is longer than 99 characters.");
            }
            if ((int) filesize($src) > 077777777777) {
                throw new \RuntimeException("Cannot add $name to the backup: it is larger than 8 GB.");
            }
        }
        $fh = fopen($out, 'xb');
        if (!$fh) {
            throw new \RuntimeException("Cannot create $out");
        }
        foreach ($files as $name => $src) {
            $size = (int) filesize($src);
            $h = str_pad($name, 100, "\0") . str_pad('0000640', 7, '0', STR_PAD_LEFT) . "\0" . "0000000\0" . "0000000\0"
                . str_pad(decoct($size), 11, '0', STR_PAD_LEFT) . "\0" . str_pad(decoct(time()), 11, '0', STR_PAD_LEFT) . "\0"
                . '        ' . '0' . str_repeat("\0", 100) . "ustar\0" . '00' . str_pad('root', 32, "\0") . str_pad('root', 32, "\0")
                . str_repeat("\0", 16) . str_repeat("\0", 155);
            $h = str_pad($h, 512, "\0");
            $sum = 0;
            for ($i = 0; $i < 512; $i++) {
                $sum += ord($h[$i]);
            }
            $h = substr_replace($h, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
            fwrite($fh, $h);
            $in = fopen($src, 'rb');
            stream_copy_to_stream($in, $fh);
            fclose($in);
            if ($size % 512) {
                fwrite($fh, str_repeat("\0", 512 - $size % 512));
            }
        }
        fwrite($fh, str_repeat("\0", 1024));
        fclose($fh);
    }
}
