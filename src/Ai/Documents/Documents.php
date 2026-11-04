<?php
declare(strict_types=1);

namespace Align\Ai\Documents;

use Align\Ai\Read\Reader;
use Align\Auth;
use Align\DB;

/**
 * 2.29.0 Documents sent for AI to read on a client's Licensing or Vendors page: the upload (at most 5 files of 10 MB:
 * PDF, Word, Excel, CSV, text, or an email as .eml or Outlook .msg with its attachments), reading them (Read\Reader),
 * asking AI (Extract), and the row the review page works from (ai_documents). The uploaded files are kept in the
 * upload folder under ai/ (random names, never a name from the request) only until the review is applied or
 * discarded; reviews left for 14 days are discarded and their files deleted (cleanup()).
 *
 * Security assumptions: callers check the role (techs and admins) and that AI and this feature are on. Uploads are
 * untrusted: each must be a real PHP upload with an extension from TYPES whose content Reader accepts; the stored
 * name is random and only ever looked up from the row's own list (path()), so a file name from the request never
 * reaches the file system. A row is always read together with its client id, so one client's review can't be opened
 * from another's page. What AI found is stored only after Extract::clean.
 */
final class Documents
{
    /** Extensions taken. */
    public const TYPES = ['pdf', 'docx', 'xlsx', 'csv', 'txt', 'eml', 'msg'];

    /** The accept= list for the file input. */
    public const ACCEPT = '.pdf,.docx,.xlsx,.csv,.txt,.eml,.msg';

    /** Most files in one upload, and the largest one. */
    public const MAX_FILES = 5;
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Days a review waits before it is discarded. */
    private const KEEP_DAYS = 14;

    /** Where uploaded files wait for their review, inside the upload folder. */
    public static function dir(): string
    {
        return \Align\Branding::uploadDir() . '/ai';
    }

    /**
     * Checks and stores the posted files ($_FILES entry with multiple files), reads them and asks AI. Returns the new
     * review's id. Throws \InvalidArgumentException (a sentence for people) or Ai\AiException; nothing is kept then.
     */
    public static function create(array $client, array $upload): int
    {
        $stored = self::store($upload);
        try {
            $parts = [];
            $notes = [];
            foreach ($stored as $f) {
                foreach (Reader::read(self::dir() . '/' . $f['stored'], $f['name']) as $p) {
                    $parts[] = $p;
                    if ($p['note'] !== null) {
                        $notes[] = $p['note'];
                    }
                }
            }
            $result = Extract::run($parts, (string) $client['name']);
            if (!$result['vendors'] && !$result['lines']) {
                throw new \InvalidArgumentException('AI found no vendor or products in ' . (count($stored) === 1 ? 'that file' : 'those files') . ', so there is nothing to review.');
            }
            $u = Auth::user();
            return DB::insert('ai_documents', ['client_id' => (int) $client['id'], 'files' => json_encode($stored, JSON_UNESCAPED_UNICODE),
                'result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'notes' => $notes ? json_encode(array_values(array_unique($notes)), JSON_UNESCAPED_UNICODE) : null,
                'created_by' => $u['id'] ?? null, 'created_name' => (string) ($u['name'] ?? '')]);
        } catch (\Throwable $e) {
            self::forget($stored);
            throw $e;
        }
    }

    /**
     * Moves the uploaded files into dir() under random names. Returns [['name' (cleaned original name), 'type' (its
     * extension), 'stored', 'size']]. Throws \InvalidArgumentException with a sentence for people.
     */
    private static function store(array $upload): array
    {
        $files = [];
        foreach ((array) ($upload['name'] ?? []) as $i => $name) {
            if (($upload['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = ['name' => (string) $name, 'tmp' => (string) ($upload['tmp_name'][$i] ?? ''), 'error' => (int) ($upload['error'][$i] ?? UPLOAD_ERR_NO_FILE)];
        }
        if (!$files) {
            throw new \InvalidArgumentException('Choose a file to read.');
        }
        if (count($files) > self::MAX_FILES) {
            throw new \InvalidArgumentException('Send at most ' . self::MAX_FILES . ' files at a time.');
        }
        foreach ($files as $f) {
            if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp'])) {
                throw new \InvalidArgumentException(in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'A file is larger than the server accepts.' : 'A file didn\'t upload. Try again.');
            }
            $size = (int) filesize($f['tmp']);
            if ($size === 0 || $size > self::MAX_BYTES) {
                throw new \InvalidArgumentException($size === 0 ? 'A file is empty.' : 'Each file can be at most ' . (self::MAX_BYTES >> 20) . ' MB.');
            }
            if (!in_array(self::ext($f['name']), self::TYPES, true)) {
                throw new \InvalidArgumentException('Send PDF, Word (.docx), Excel (.xlsx), CSV, text or email (.eml, .msg) files.');
            }
        }
        if (!is_dir(self::dir()) && !mkdir(self::dir(), 0750, true) && !is_dir(self::dir())) {
            throw new \RuntimeException('Could not create the AI upload folder.');
        }
        $out = [];
        try {
            foreach ($files as $f) {
                $ext = self::ext($f['name']);
                $stored = 'ai-' . bin2hex(random_bytes(12)) . '.' . $ext;
                if (!move_uploaded_file($f['tmp'], self::dir() . '/' . $stored)) {
                    throw new \RuntimeException('Could not save the file.');
                }
                @chmod(self::dir() . '/' . $stored, 0640);
                $out[] = ['name' => self::cleanName($f['name'], $ext), 'type' => $ext, 'stored' => $stored, 'size' => (int) filesize(self::dir() . '/' . $stored)];
            }
        } catch (\Throwable $e) {
            self::forget($out);
            throw $e;
        }
        return $out;
    }

    /** A file name's extension, lower case ('' for none). */
    private static function ext(string $name): string
    {
        return strtolower(pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_EXTENSION));
    }

    /** A file name for people: the base name only, letters, digits and . _ - ( ) & , + and spaces, at most 190 characters. */
    public static function cleanName(string $name, string $ext): string
    {
        $n = trim(preg_replace('/[^\w .()&,+-]/u', '', mb_scrub(basename(str_replace('\\', '/', $name)), 'UTF-8')) ?? '');
        return mb_substr($n !== '' ? $n : "file.$ext", 0, 190);
    }

    /** A stored file's full path, or null when the name isn't one of ours or it's gone. */
    public static function path(string $stored): ?string
    {
        if (!preg_match('/^ai-[0-9a-f]{24}\.(pdf|docx|xlsx|csv|txt|eml|msg)$/', $stored)) {
            return null;
        }
        $p = self::dir() . '/' . $stored;
        return is_file($p) ? $p : null;
    }

    /** Deletes stored files (rows of store()); missing ones are skipped. */
    public static function forget(array $files): void
    {
        foreach ($files as $f) {
            if (is_array($f) && is_string($f['stored'] ?? null) && ($p = self::path($f['stored'])) !== null) {
                @unlink($p);
            }
        }
    }

    /** A review of client $clientId with its files, result and notes decoded and its client's name, or null. */
    public static function load(int $id, int $clientId): ?array
    {
        $r = DB::one('SELECT d.*, c.name AS client_name FROM ai_documents d JOIN clients c ON c.id = d.client_id WHERE d.id = ? AND d.client_id = ?', [$id, $clientId]);
        if (!$r) {
            return null;
        }
        foreach (['files', 'result', 'notes'] as $k) {
            $r[$k] = is_string($r[$k]) ? (json_decode($r[$k], true) ?: []) : [];
        }
        $r['result'] += ['document' => [], 'vendors' => [], 'lines' => []];
        return $r;
    }

    /** A client's reviews still waiting, newest first. */
    public static function waiting(int $clientId): array
    {
        return DB::all("SELECT id, created_name, created_at, files FROM ai_documents WHERE client_id = ? AND status = 'review' ORDER BY id DESC LIMIT 20", [$clientId]);
    }

    /** Discards a waiting review and deletes its files. False when it wasn't waiting (applied or discarded meanwhile). */
    public static function discard(array $doc): bool
    {
        $n = DB::run("UPDATE ai_documents SET status = 'discarded', done_by = ?, done_at = NOW() WHERE id = ? AND status = 'review'", [Auth::id(), $doc['id']])->rowCount();
        if ($n) {
            self::forget($doc['files']);
        }
        return $n > 0;
    }

    /**
     * Discards reviews left for KEEP_DAYS days, and deletes files in dir() older than a day that no waiting review
     * lists (a review whose client was deleted, or an upload stopped half-way). Cheap enough to run on each upload.
     */
    public static function cleanup(): void
    {
        foreach (DB::all("SELECT id, files FROM ai_documents WHERE status = 'review' AND created_at < NOW() - INTERVAL " . self::KEEP_DAYS . ' DAY') as $r) {
            DB::run("UPDATE ai_documents SET status = 'discarded', done_at = NOW(), summary = 'Discarded after " . self::KEEP_DAYS . " days without a review.' WHERE id = ? AND status = 'review'", [$r['id']]);
            self::forget(json_decode((string) $r['files'], true) ?: []);
        }
        if (!is_dir(self::dir())) {
            return;
        }
        $keep = [];
        foreach (DB::all("SELECT files FROM ai_documents WHERE status = 'review'") as $r) {
            foreach (json_decode((string) $r['files'], true) ?: [] as $f) {
                $keep[(string) ($f['stored'] ?? '')] = true;
            }
        }
        foreach (glob(self::dir() . '/ai-*') ?: [] as $p) {
            if (!isset($keep[basename($p)]) && filemtime($p) < time() - 86400) {
                @unlink($p);
            }
        }
    }
}
