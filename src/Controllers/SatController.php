<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Sat\Curricula;
use Align\Sat\KnowBe4;
use Align\Sat\Sat;

/**
 * 2.7.0 Security awareness training uploads (2.16.0: on the client's Connectors page): upload a Huntress SAT export (its totals are
 * stored, see Sat\Sat) and delete one. 2.7.2: read the client's results from the Curricula API now (Refresh), and
 * open one of its Curricula summary reports. 2.14.0: save or remove the client's KnowBe4 token (admins), and Refresh
 * reads KnowBe4 for a client that has one.
 *
 * Security assumptions: the router checks CSRF; techs and admins only (who see every client), and admins only for
 * saving or removing a KnowBe4 token (the token is never shown back or logged); ClientController::load()
 * refuses a client that doesn't exist. The file is untrusted: size-limited, read as text and parsed into counts, then thrown away (never
 * moved into storage or served). Deleting checks the row belongs to the client in the address. Audited.
 */
final class SatController
{
    /**
     * What the training card (overview) and its setup (Connectors) show: ['history', 'checks', 'source', 'knowbe4',
     * 'curricula', 'reports'], or null when no training provider is set up and the client has no results (no card).
     * The client's KnowBe4 console takes the place of Curricula. Moved here from ClientController in 2.16.0.
     */
    public static function data(int $id): ?array
    {
        if (!\Align\Huntress\Api::configured() && !Curricula::configured() && !KnowBe4::enabled() && !KnowBe4::connected($id) && !Sat::any($id)) {
            return null;
        }
        $kb4 = KnowBe4::forClient($id);
        $cur = $kb4 ? null : Curricula::account($id);
        return ['history' => Sat::history($id), 'checks' => Sat::checks($id), 'source' => Sat::source($id), 'knowbe4' => $kb4, 'curricula' => $cur,
            'reports' => $cur ? Curricula::reports((string) $cur['account_id']) : []];
    }

    /** Reads an uploaded export and stores its totals. */
    public static function upload(int $id): void
    {
        $u = Auth::requireRole('tech');
        $client = ClientController::load($id);
        $f = $_FILES['file'] ?? null;
        if (is_array($f) && in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            flash('error', 'That file is too large for a SAT report (' . (Sat::MAX_BYTES >> 20) . ' MB at most).');
            redirect("/clients/$id/connectors#sat"); // 2.16.0: the training setup is on Connectors
        }
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            flash('error', 'Choose the CSV file to upload.');
            redirect("/clients/$id/connectors#sat");
        }
        if ((int) $f['size'] > Sat::MAX_BYTES) {
            flash('error', 'That file is too large for a SAT report (' . (Sat::MAX_BYTES >> 20) . ' MB at most).');
            redirect("/clients/$id/connectors#sat");
        }
        $text = (string) file_get_contents((string) $f['tmp_name']);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252'); // Excel's "CSV" without UTF-8
        }
        try {
            $p = Sat::parse($text, post('campaign_date'));
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect("/clients/$id/connectors#sat");
        }
        Sat::save($id, $p, (string) ($f['name'] ?? ''), (int) $u['id']);
        Audit::log('sat.upload', "{$client['name']}: {$p['report']}");
        flash('success', $p['kind'] === 'training'
            ? "Read {$p['learners']} learners: {$p['completed']} completed their training."
            : "Read {$p['sent']} phishing emails: {$p['clicked']} clicked" . ($p['reported'] !== null ? ", {$p['reported']} reported" : '') . '.');
        redirect("/clients/$id/connectors#sat");
    }

    /** Reads the client's results from Curricula now (2.7.2; techs and admins). 2.14.0: from KnowBe4 when the client has a token. */
    public static function refresh(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        if (\Align\Sat\KnowBe4::connected($id)) {
            if (\Align\Sync\SyncRunner::isRunning()) { // two readers on one token could pass KnowBe4's per-minute limit
                flash('info', 'A sync is running and reads KnowBe4 too. Try again when it has finished.');
                redirect("/clients/$id/connectors#sat");
            }
            @set_time_limit(300); // a few requests, spaced for KnowBe4's limit
            try {
                \Align\Sat\KnowBe4::refresh($id);
            } catch (\Throwable $e) {
                flash('error', 'KnowBe4: ' . safe_error($e));
                redirect("/clients/$id/connectors#sat");
            }
            Audit::log('sat.refresh', $client['name'] . ' (KnowBe4)');
            flash('success', 'Read the training and phishing results from KnowBe4.');
            redirect("/clients/$id/connectors#sat");
        }
        try {
            if (!\Align\Sat\Curricula::refresh($id)) {
                flash('error', 'This client isn\'t linked to a Curricula account. Link it on Client mapping.');
                redirect("/clients/$id/connectors#sat");
            }
        } catch (\Throwable $e) {
            flash('error', 'Curricula: ' . safe_error($e));
            redirect("/clients/$id/connectors#sat");
        }
        Audit::log('sat.refresh', $client['name']);
        flash('success', 'Read the training and phishing results from Curricula.');
        redirect("/clients/$id/connectors#sat");
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
            flash('error', 'Curricula: ' . safe_error($e));
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

    /**
     * 2.14.0 Saves the client's KnowBe4 region and Reporting API token (admins only: it is a key to the client's
     * training data). The token is checked with one read before it's kept (encrypted); a refused one isn't saved. The
     * change is audited (never the token) and raises a security alert, like an integration key. Results are read now.
     */
    public static function knowbe4Save(int $id): void
    {
        $u = Auth::requireRole('admin');
        $client = ClientController::load($id);
        if (!\Align\Sat\KnowBe4::enabled() && !\Align\Sat\KnowBe4::connected($id)) {
            flash('error', 'Switch KnowBe4 on under Integrations → KnowBe4 first.');
            redirect("/clients/$id/connectors#sat");
        }
        try {
            $name = \Align\Sat\KnowBe4::save($id, post('region'), is_string($_POST['token'] ?? null) ? $_POST['token'] : '', (int) $u['id']);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage() . ' Nothing was saved.');
            redirect("/clients/$id/connectors#sat");
        } catch (\Throwable $e) {
            flash('error', 'KnowBe4: ' . safe_error($e) . ' Nothing was saved.');
            redirect("/clients/$id/connectors#sat");
        }
        Audit::log('sat.knowbe4', "{$client['name']}: token saved for KnowBe4 account $name");
        \Align\Mail\Notify::security('KnowBe4 token saved', "{$client['name']}: KnowBe4 account $name by " . ($u['email'] ?? ''));
        if (\Align\Sync\SyncRunner::isRunning()) {
            flash('success', "Connected to KnowBe4 account $name. Its results are read by the sync that's running now, or the next one.");
            redirect("/clients/$id/connectors#sat");
        }
        @set_time_limit(300);
        try {
            \Align\Sat\KnowBe4::refresh($id);
            flash('success', "Connected to KnowBe4 account $name and read its results.");
        } catch (\Throwable $e) {
            flash('warning', "Connected to KnowBe4 account $name, but reading its results failed: " . safe_error($e) . ' The next sync tries again.');
        }
        redirect("/clients/$id/connectors#sat");
    }

    /** 2.14.0 Removes the client's KnowBe4 token and the results read with it (admins only; audited). */
    public static function knowbe4Remove(int $id): void
    {
        $u = Auth::requireRole('admin');
        $client = ClientController::load($id);
        if (\Align\Sat\KnowBe4::connected($id)) {
            \Align\Sat\KnowBe4::remove($id);
            Audit::log('sat.knowbe4', "{$client['name']}: KnowBe4 token removed");
            \Align\Mail\Notify::security('KnowBe4 token removed', "{$client['name']}: by " . ($u['email'] ?? ''));
            flash('success', 'KnowBe4 removed for this client.');
        }
        redirect("/clients/$id/connectors#sat");
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
        redirect("/clients/$id/connectors#sat");
    }
}
