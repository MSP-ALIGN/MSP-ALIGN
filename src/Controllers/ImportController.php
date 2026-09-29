<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Import\CsvImport;
use Align\View;

/** Clients and contacts from a CSV file: upload, check what would change, then import (see CsvImport). */
final class ImportController
{
    private const KINDS = ['clients' => 'Clients', 'contacts' => 'Contacts'];

    private static function kind(string $k): string
    {
        return isset(self::KINDS[$k]) ? $k : 'clients';
    }

    /** Where a checked file waits for the Import button (the web server's own data folder, not public). */
    private static function stash(string $token): string
    {
        $dir = \Align\System\Agent::dataDir() . '/imports';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        // Old stashes (a preview nobody imported) go after a day
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        return $dir . '/' . $token . '.json';
    }

    public static function index(): void
    {
        Auth::requireRole('tech');
        View::render('clients/import', ['title' => 'Import clients and contacts', 'nav' => 'clients', 'kind' => self::kind(query('kind', 'clients')), 'plan' => null]);
    }

    public static function template(string $kind): void
    {
        Auth::requireRole('tech');
        $kind = self::kind($kind);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="msp-align-' . $kind . '-template.csv"');
        $out = fopen('php://output', 'w');
        foreach (CsvImport::TEMPLATES[$kind] as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        fclose($out);
    }

    public static function preview(): void
    {
        Auth::requireRole('tech');
        $kind = self::kind(post('kind'));
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            flash('error', ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'The file is too large.' : 'Choose a CSV file to import.');
            redirect('/clients/import?kind=' . $kind);
        }
        try {
            [$headers, $rows] = CsvImport::read($f['tmp_name']);
            [$map, $unused] = CsvImport::mapHeaders($headers, $kind === 'clients' ? CsvImport::CLIENT_COLUMNS : CsvImport::CONTACT_COLUMNS);
            $plan = $kind === 'clients' ? CsvImport::planClients($rows, $map) : CsvImport::planContacts($rows, $map);
        } catch (\RuntimeException $e) {
            flash('error', 'That file can\'t be imported: ' . $e->getMessage());
            redirect('/clients/import?kind=' . $kind);
        } catch (\Throwable $e) {
            error_log('[msp-align] import: ' . $e->getMessage());
            flash('error', 'That file can\'t be imported. Check it is a CSV file (UTF-8 or Windows text) and try again.');
            redirect('/clients/import?kind=' . $kind);
        }
        $token = bin2hex(random_bytes(16));
        $json = json_encode(['kind' => $kind, 'user' => Auth::id(), 'file' => mb_substr(basename((string) $f['name']), 0, 120), 'plan' => $plan], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || file_put_contents(self::stash($token), $json) === false) {
            flash('error', 'The checked file couldn\'t be kept for importing. Try again.');
            redirect('/clients/import?kind=' . $kind);
        }
        $cols = $kind === 'clients' ? CsvImport::CLIENT_COLUMNS : CsvImport::CONTACT_COLUMNS;
        View::render('clients/import', [
            'title' => 'Import ' . strtolower(self::KINDS[$kind]), 'nav' => 'clients', 'kind' => $kind, 'plan' => $plan, 'token' => $token,
            'fileName' => (string) $f['name'], 'used' => array_map(fn($field) => $cols[$field][0], array_keys($map)), 'unused' => $unused,
        ]);
    }

    public static function run(): void
    {
        Auth::requireRole('tech');
        $token = post('token');
        $path = preg_match('/^[a-f0-9]{32}$/', $token) ? self::stash($token) : '';
        $data = $path !== '' && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($data) || ($data['user'] ?? null) !== Auth::id()) {
            flash('error', 'That import has expired. Upload the file again.');
            redirect('/clients/import');
        }
        @unlink($path);
        $kind = self::kind((string) $data['kind']);
        [$added, $updated] = CsvImport::apply($kind, (array) $data['plan'], Auth::id());
        Audit::log('import.' . $kind, ($data['file'] ?? 'CSV') . ": $added added, $updated updated");
        $noun = $kind === 'clients' ? 'client' : 'contact';
        flash('success', "Imported $added new $noun" . ($added === 1 ? '' : 's') . " and updated $updated.");
        redirect($kind === 'clients' ? '/clients' : '/contacts');
    }
}
