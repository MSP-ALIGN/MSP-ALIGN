<?php
declare(strict_types=1);

namespace Align\System;

/**
 * Minimal ustar reader/writer for backup files. A backup is a plain tar of already-encrypted parts
 * (plus a plaintext manifest), so nothing unencrypted is ever written to disk. Standard tools can
 * open it: tar -xf backup.tar, then age -d each part. No external programs are used here, so the
 * web app can read a backup's manifest and the agent can stream parts straight into age.
 */
final class Tar
{
    /** @return array<string, array{offset:int, size:int, type:string}> members by name; throws on anything unexpected */
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
                if ($sum !== octdec(trim(substr($h, 148, 8), " \0"))) {
                    throw new \RuntimeException('This is not a Mountaineer Align backup (bad tar header).');
                }
                $name = rtrim(substr($h, 0, 100), "\0");
                $prefix = rtrim(substr($h, 345, 155), "\0");
                if ($prefix !== '' && substr($h, 257, 5) === 'ustar') {
                    $name = $prefix . '/' . $name;
                }
                $size = (int) octdec(trim(substr($h, 124, 12), " \0"));
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

    public static function read(string $path, array $member, int $max = 1048576): string
    {
        if ($member['size'] > $max) {
            throw new \RuntimeException('Backup entry is too large.');
        }
        $fh = fopen($path, 'rb');
        fseek($fh, $member['offset']);
        $d = $member['size'] ? (string) fread($fh, $member['size']) : '';
        fclose($fh);
        return $d;
    }

    /** Copies one member's bytes to a stream (for piping into age). */
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

    /** Writes a tar of the given files: [member name => source path]. */
    public static function write(string $out, array $files): void
    {
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
