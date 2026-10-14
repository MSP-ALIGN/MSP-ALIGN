<?php
declare(strict_types=1);

namespace Align\Docs;

use Align\DB;

/**
 * 2.20.0 Uploaded files kept as documents: PDF, Word (.docx, .doc), Excel (.xlsx, .xls), CSV, PNG and JPEG.
 *
 * Security assumptions: callers check the role (techs upload; any staff role reads; the portal only its own client's
 * active, shared documents). An upload is untrusted: it must be a real PHP upload of at most MAX bytes whose
 * extension is on TYPES and whose content matches it (PDF header, ZIP with the Word or Excel part, the Office
 * binary header, a decodable PNG or JPEG, text without NUL bytes for CSV). It is stored under a new random name in
 * the upload folder (never a name from the request) and never run or parsed here, apart from Docx when someone
 * imports it. It is sent with its fixed type, nosniff and a sandbox CSP; only PDFs and images are sent inline (PDFs
 * are painted by PDF.js without scripts, images shown in <img>); everything else is a download. Without PHP's zip
 * extension a .docx or .xlsx is checked by its ZIP header only. CSV must be UTF-8 or another 8-bit text (an
 * Excel "Unicode text" export, UTF-16, is refused).
 */
final class Files
{
    /** Largest file accepted (also the web server's limit on the upload URLs, see install.sh). */
    public const MAX = 25 * 1024 * 1024;

    /** Extension => [content type, label, Font Awesome icon, shown in the page (true) or downloaded]. */
    public const TYPES = [
        'pdf' => ['application/pdf', 'PDF', 'fa-file-pdf', true],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Word', 'fa-file-word', false],
        'doc' => ['application/msword', 'Word', 'fa-file-word', false],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Excel', 'fa-file-excel', false],
        'xls' => ['application/vnd.ms-excel', 'Excel', 'fa-file-excel', false],
        'csv' => ['text/csv; charset=utf-8', 'CSV', 'fa-file-csv', false],
        'png' => ['image/png', 'PNG image', 'fa-file-image', true],
        'jpg' => ['image/jpeg', 'JPEG image', 'fa-file-image', true],
    ];

    /** The accept= list for file inputs. */
    public const ACCEPT = '.pdf,.docx,.doc,.xlsx,.xls,.csv,.png,.jpg,.jpeg';

    /** Where document files are kept, inside the upload folder. */
    public static function dir(): string
    {
        return \Align\Branding::uploadDir() . '/documents';
    }

    /**
     * Checks and stores an upload ($_FILES entry): ['file_name', 'file_orig', 'file_type', 'file_size', 'file_hash'].
     * Throws InvalidArgumentException with a sentence for the person.
     */
    public static function store(array $file): array
    {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new \InvalidArgumentException(in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'That file is larger than the server accepts (25 MB).' : 'Choose a file to upload.');
        }
        $size = (int) filesize($file['tmp_name']);
        if ($size > self::MAX) {
            throw new \InvalidArgumentException('That file is larger than 25 MB.');
        }
        if ($size === 0) {
            throw new \InvalidArgumentException('That file is empty.');
        }
        $raw = mb_scrub(basename(str_replace('\\', '/', (string) ($file['name'] ?? ''))), 'UTF-8'); // a name that isn't UTF-8 is cleaned, not refused
        $orig = trim(preg_replace('/[^\w .()&,+-]/u', '', $raw) ?? '');
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        if (!isset(self::TYPES[$ext])) {
            throw new \InvalidArgumentException('Upload a PDF, Word, Excel, CSV, PNG or JPEG file.');
        }
        if (!self::matches($file['tmp_name'], $ext)) {
            throw new \InvalidArgumentException('That file isn\'t a real ' . self::TYPES[$ext][1] . ' file (its content doesn\'t match .' . $ext . ').');
        }
        if (!is_dir(self::dir()) && !mkdir(self::dir(), 0750, true) && !is_dir(self::dir())) {
            throw new \RuntimeException('Could not create the documents folder.');
        }
        $name = 'doc-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], self::dir() . '/' . $name)) {
            throw new \RuntimeException('Could not save the file.');
        }
        @chmod(self::dir() . '/' . $name, 0640);
        return ['file_name' => $name, 'file_orig' => mb_substr($orig ?: 'file.' . $ext, 0, 190), 'file_type' => $ext,
            'file_size' => $size, 'file_hash' => hash_file('sha256', self::dir() . '/' . $name)];
    }

    /** Whether the file's content is what its extension says (see the class comment). */
    public static function matches(string $path, string $ext): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);
        switch ($ext) {
            case 'pdf':
                return str_starts_with($head, '%PDF-');
            case 'doc':
            case 'xls':
                return $head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
            case 'docx':
            case 'xlsx':
                if (!str_starts_with($head, "PK\x03\x04")) {
                    return false;
                }
                if (!class_exists(\ZipArchive::class)) {
                    return true; // the ZIP header is all that can be checked without the zip extension
                }
                $z = new \ZipArchive();
                if ($z->open($path, \ZipArchive::RDONLY) !== true) {
                    return false;
                }
                $ok = $z->locateName($ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml') !== false;
                $z->close();
                return $ok;
            case 'csv':
                $sample = (string) file_get_contents($path, false, null, 0, 65536);
                return !str_contains($sample, "\0");
            case 'png':
            case 'jpg':
                $info = @getimagesize($path);
                return $info !== false && $info[2] === ($ext === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG);
        }
        return false;
    }

    /** The full path of a stored file, or null for a name that isn't one of ours or a file that's gone. */
    public static function path(?string $name): ?string
    {
        if (!$name || !preg_match('/^doc-[a-f0-9]{16}\.(pdf|docx|doc|xlsx|xls|csv|png|jpg)$/', $name)) {
            return null;
        }
        $p = self::dir() . '/' . $name;
        return is_file($p) ? $p : null;
    }

    /**
     * Sends a stored file ($row: file_name, file_orig, file_type) and stops: inline for PDFs and images unless
     * $download, otherwise as a download; 404 when it's missing.
     */
    public static function send(array $row, bool $download): never
    {
        $path = self::path($row['file_name'] ?? null);
        $t = self::TYPES[$row['file_type'] ?? ''] ?? null;
        if (!$path || !$t) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'The file is missing.';
            exit;
        }
        $inline = $t[3] && !$download;
        header('Content-Type: ' . $t[0]);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; ' . content_filename((string) ($row['file_orig'] ?: 'document.' . $row['file_type'])));
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /** A size for people: 512 KB, 2.4 MB. */
    public static function size(?int $bytes): string
    {
        $b = (int) $bytes;
        return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
    }

    /** Every stored file name of the given clients' documents and their history (to forget once they're deleted). */
    public static function ofClients(array $clientIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $clientIds)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array_column(DB::all("SELECT file_name FROM documents WHERE client_id IN ($in) AND file_name IS NOT NULL
            UNION SELECT v.file_name FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE d.client_id IN ($in) AND v.file_name IS NOT NULL", [...$ids, ...$ids]), 'file_name');
    }

    /**
     * Deletes stored files whose rows are gone (a deleted document, client, or a save that failed): the given names
     * that no remaining document or version uses. A file that can't be removed is logged.
     */
    public static function forget(array $names): void
    {
        foreach (array_unique(array_filter($names)) as $n) {
            if (DB::value('SELECT 1 FROM documents WHERE file_name = ? UNION SELECT 1 FROM document_versions WHERE file_name = ? LIMIT 1', [$n, $n])) {
                continue;
            }
            if (($p = self::path($n)) && !@unlink($p)) {
                error_log('MSP Align: could not delete the document file ' . $n);
            }
        }
    }
}
