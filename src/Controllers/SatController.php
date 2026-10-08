<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Sat\Sat;

/**
 * 2.7.0 Security awareness training uploads on a client's overview: upload a Huntress SAT export (its totals are
 * stored, see Sat\Sat) and delete one.
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

    /** Deletes one upload's results. */
    public static function delete(int $id, int $sid): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $row = DB::one('SELECT report FROM sat_results WHERE id = ? AND client_id = ?', [$sid, $id]);
        if ($row) {
            DB::run('DELETE FROM sat_results WHERE id = ? AND client_id = ?', [$sid, $id]);
            Audit::log('sat.delete', "{$client['name']}: {$row['report']}");
            flash('success', 'Deleted.');
        }
        redirect("/clients/$id#sat");
    }
}
