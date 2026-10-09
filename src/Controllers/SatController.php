<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Sat\Sat;

/**
 * 2.7.0 Security awareness training uploads on a client's overview: upload a Huntress SAT export (its totals are
 * stored, see Sat\Sat) and delete one. 2.7.2: read the client's results from the Curricula API now (Refresh), and
 * open one of its Curricula summary reports.
 *
 * Security assumptions: the router checks CSRF; techs and admins only (who see every client); ClientController::load()
 * refuses a client that doesn't exist. The file is untrusted: size-limited, read as text and parsed into counts, then thrown away (never
 * moved into storage or served). Deleting checks the row belongs to the client in the address. Audited.
 */
final class SatController
{
    /** Reads an uploaded export and stores its totals. */
    public static function upload(int $id): void
    {
        $u = Auth::requireRole('tech');
        $client = ClientController::load($id);
        $f = $_FILES['file'] ?? null;
        if (is_array($f) && in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            flash('error', 'That file is too large for a SAT report (' . (Sat::MAX_BYTES >> 20) . ' MB at most).');
            redirect("/clients/$id#sat");
        }
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            flash('error', 'Choose the CSV file to upload.');
            redirect("/clients/$id#sat");
        }
        if ((int) $f['size'] > Sat::MAX_BYTES) {
            flash('error', 'That file is too large for a SAT report (' . (Sat::MAX_BYTES >> 20) . ' MB at most).');
            redirect("/clients/$id#sat");
        }
        $text = (string) file_get_contents((string) $f['tmp_name']);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252'); // Excel's "CSV" without UTF-8
        }
        try {
            $p = Sat::parse($text, post('campaign_date'));
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect("/clients/$id#sat");
        }
        Sat::save($id, $p, (string) ($f['name'] ?? ''), (int) $u['id']);
        Audit::log('sat.upload', "{$client['name']}: {$p['report']}");
        flash('success', $p['kind'] === 'training'
            ? "Read {$p['learners']} learners: {$p['completed']} completed their training."
            : "Read {$p['sent']} phishing emails: {$p['clicked']} clicked" . ($p['reported'] !== null ? ", {$p['reported']} reported" : '') . '.');
        redirect("/clients/$id#sat");
    }

    /** Reads the client's results from Curricula now (2.7.2; techs and admins). */
    public static function refresh(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        try {
            if (!\Align\Sat\Curricula::refresh($id)) {
                flash('error', 'This client isn\'t linked to a Curricula account. Link it on Client mapping.');
                redirect("/clients/$id#sat");
            }
        } catch (\Throwable $e) {
            flash('error', 'Curricula: ' . $e->getMessage());
            redirect("/clients/$id#sat");
        }
        Audit::log('sat.refresh', $client['name']);
        flash('success', 'Read the training and phishing results from Curricula.');
        redirect("/clients/$id#sat");
    }

    /**
     * Opens one of the client's Curricula summary reports (2.7.2; techs and admins): asks Curricula for the PDF's
     * current link, since the one it gives is temporary, and sends the browser there. Only an https link is followed
     * (Curricula::reportUrl() checks it and that the report is this client's).
     */
    public static function report(int $id, string $rid): void
    {
        Auth::requireRole('tech');
        ClientController::load($id);
        try {
            $url = \Align\Sat\Curricula::reportUrl($id, $rid);
        } catch (\Throwable $e) {
            flash('error', 'Curricula: ' . $e->getMessage());
            redirect("/clients/$id#sat");
        }
        if ($url === null) {
            flash('error', 'That report isn\'t available from Curricula.');
            redirect("/clients/$id#sat");
        }
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $url, true, 302);
        exit;
    }

    /** Deletes one upload's results (uploads only: results read from Curricula are replaced by its next read). */
    public static function delete(int $id, int $sid): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $row = DB::one("SELECT report FROM sat_results WHERE id = ? AND client_id = ? AND source = 'upload'", [$sid, $id]);
        if ($row) {
            DB::run("DELETE FROM sat_results WHERE id = ? AND client_id = ? AND source = 'upload'", [$sid, $id]);
            Audit::log('sat.delete', "{$client['name']}: {$row['report']}");
            flash('success', 'Deleted.');
        }
        redirect("/clients/$id#sat");
    }
}
