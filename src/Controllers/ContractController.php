<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Contracts\Contracts;
use Align\Contracts\PdfRender;
use Align\Contracts\Render;
use Align\Contracts\Template;
use Align\DB;
use Align\Mail\Mail;
use Align\Security;
use Align\View;

/**
 * Onboarding → Contracts (2.2): the list, making a contract from a template, filling it in, sending it for
 * signature, countersigning, the signed PDF, and uploading contracts signed elsewhere. Techs and admins.
 *
 * Security assumptions: every handler starts with Auth::requireRole('tech') (viewers get 403); deleting a signed
 * contract also needs an admin. The router has checked the CSRF token of every POST. Contract ids come from the URL
 * and are loaded with load() (404 when unknown); client ids from a form are loaded before use. Every status change
 * is a conditional UPDATE/DELETE from the status it was checked in (here or in Contracts), so parallel requests
 * can't sign, send or delete a contract that changed meanwhile. Files are only read from paths Contracts::pdfPath()
 * and PdfStamp validate; download names go through content_filename().
 */
final class ContractController
{
    public const FILTERS = [
        'open' => 'In progress', 'draft' => 'Drafts', 'signed' => 'Signed', 'closed' => 'Declined, expired or cancelled', 'all' => 'All',
    ];

    /** The contract, or a 404 page and the request ends. */
    private static function load(int $id): array
    {
        $c = Contracts::load($id);
        if (!$c) {
            http_response_code(404);
            View::render('error', ['title' => 'Not found', 'message' => 'That contract doesn\'t exist.']);
            exit;
        }
        return $c;
    }

    /** Active clients, for the client pickers. */
    public static function clients(): array
    {
        return DB::all('SELECT id, name FROM clients WHERE is_archived = 0 ORDER BY name');
    }

    /** The list, filtered by ?show= (one of FILTERS; anything else shows "open"), newest activity first, at most 500. */
    public static function index(): void
    {
        Auth::requireRole('tech');
        $show = isset(self::FILTERS[query('show')]) ? query('show') : 'open';
        Audit::access('contracts', 'list (' . $show . ')'); // signer names and emails across clients (2.2.1)
        $where = match ($show) {
            'open' => "k.status IN ('sent','client_signed')",
            'draft' => "k.status = 'draft'",
            'signed' => "k.status = 'completed' OR k.source = 'uploaded'",
            'closed' => "k.status IN ('declined','expired','void')",
            default => '1=1',
        };
        $rows = DB::all("SELECT k.id, k.source, k.status, k.title, k.client_id, k.lead_company, k.signer_name, k.signer_email, k.sent_at, k.viewed_at,
                k.client_signed_at, k.completed_at, k.signed_on, k.ends_on, k.created_at, k.updated_at, k.declined_at, k.voided_at, k.token_expires_at,
                c.name AS client_name, u.name AS created_by_name, t.name AS template_name
            FROM contracts k LEFT JOIN clients c ON c.id = k.client_id LEFT JOIN users u ON u.id = k.created_by LEFT JOIN contract_templates t ON t.id = k.template_id
            WHERE $where ORDER BY k.updated_at DESC LIMIT 500");
        $counts = DB::one("SELECT SUM(status IN ('sent','client_signed')) AS open, SUM(status = 'draft') AS draft,
            SUM(status = 'completed' OR source = 'uploaded') AS signed, SUM(status IN ('declined','expired','void')) AS closed, COUNT(*) AS `all` FROM contracts");
        View::render('contracts/index', [
            'title' => 'Contracts',
            'nav' => 'contracts',
            'rows' => $rows,
            'show' => $show,
            'counts' => array_map('intval', $counts ?: []),
            'templates' => Template::all(true),
            'clients' => self::clients(),
            'waitingOnYou' => \Align\Workflow\Todo::contractsWaiting(),
        ]);
    }

    /** New contract: from an active template, for a client (loaded, so it must exist) or a new lead's company name. */
    public static function create(): void
    {
        Auth::requireRole('tech');
        $t = Template::load((int) post('template_id'));
        if (!$t || !$t['is_active']) {
            flash('error', 'Choose a contract template.');
            redirect('/contracts');
        }
        $client = (int) post('client_id') && post('for') !== 'lead' ? ClientController::loadRow((int) post('client_id')) : null;
        $lead = trim(post('lead_company'));
        if (!$client && $lead === '') {
            flash('error', 'Choose a client, or type the new client\'s company name.');
            redirect('/contracts');
        }
        $id = Contracts::create($t, $client, $lead);
        Audit::log('contract.created', Contracts::number(['id' => $id]) . ' ' . $t['name'] . ' for ' . ($client['name'] ?? $lead));
        redirect('/contracts/' . $id);
    }

    /**
     * A contract: the prepare form for a draft, else its record. The view is audited (once per 15 minutes). The
     * signing link from "Create link only" is shown once, to the session that made it. The preview is HTML built by
     * Render, which escapes every value.
     */
    public static function show(int $id): void
    {
        $me = Auth::requireRole('tech');
        $c = self::load($id);
        $c['_src'] = "/contracts/$id/source";
        Audit::access('contract', Contracts::number($c));
        $view = [
            'title' => Contracts::number($c) . ' · ' . $c['title'],
            'nav' => 'contracts',
            'contractsJs' => true,
            'c' => $c,
            'events' => Contracts::events($id),
            'mailReady' => Mail::ready(),
            'clients' => self::clients(),
            'link' => $_SESSION['contract_link_once'][$id] ?? null,
            'me' => $me,
            'clientDeleted' => !$c['client_id'] && self::clientDeleted($id),
        ];
        unset($_SESSION['contract_link_once'][$id]);
        if ($c['source'] === 'built' && $c['status'] === 'draft') {
            $view += [
                'problems' => Contracts::readyProblems($c),
                'counts' => $c['client_id'] ? Contracts::autoCounts((int) $c['client_id']) : [],
                'providerFields' => Contracts::fieldsUsed($c['def'], $c['vals'], 'provider'),
                'clientFields' => Contracts::fieldsUsed($c['def'], $c['vals'], 'client'),
                'preview' => Render::html($c, 'preview', $me),
                'templateNewer' => $c['template_id'] && (int) $c['template_current_version'] > (int) $c['template_version'],
            ];
            View::render('contracts/prepare', $view);
            return;
        }
        $view['preview'] = $c['source'] === 'built' ? Render::html($c, 'view', $me) : '';
        View::render('contracts/show', $view);
    }

    /**
     * Applies the prepare form to the draft (party, signer, values): returns [contract columns, values]. Untrusted
     * input, cut to length; a client id that doesn't exist is dropped; an invalid signer email keeps the old one.
     */
    private static function applyForm(array $c): array
    {
        $vals = Contracts::applyPrepare($c, $_POST);
        $clientId = (int) post('client_id') ?: null;
        if ($clientId && !ClientController::loadRow($clientId)) {
            $clientId = null;
        }
        $row = [
            'title' => mb_substr(post('title'), 0, 190) ?: $c['title'],
            'client_id' => $clientId,
            'lead_company' => $clientId ? null : (mb_substr(trim(post('lead_company')), 0, 190) ?: null),
            'lead_address' => $clientId ? null : (mb_substr(trim(str_replace("\r", '', post('lead_address'))), 0, 500) ?: null),
            'lead_phone' => $clientId ? null : (mb_substr(trim(post('lead_phone')), 0, 60) ?: null),
            'signer_name' => mb_substr(trim(post('signer_name')), 0, 190) ?: null,
            'signer_title' => mb_substr(trim(post('signer_title')), 0, 190) ?: null,
            'signer_email' => filter_var(trim(post('signer_email')), FILTER_VALIDATE_EMAIL) ? strtolower(trim(post('signer_email'))) : (trim(post('signer_email')) === '' ? null : $c['signer_email']),
            'verify_code' => isset($_POST['verify_code']) ? 1 : 0,
        ];
        return [$row, $vals];
    }

    /** Saves the prepare form, only while the contract is a draft (conditional UPDATE), then optionally opens Send. */
    public static function save(int $id): void
    {
        Auth::requireRole('tech');
        $c = self::load($id);
        if ($c['status'] !== 'draft') {
            flash('error', 'Only drafts can be changed. Cancel this contract and make a new one to change it.');
            redirect("/contracts/$id");
        }
        [$row, $vals] = self::applyForm($c);
        if ($row['signer_email'] === $c['signer_email'] && trim(post('signer_email')) !== '' && strtolower(trim(post('signer_email'))) !== $c['signer_email']) {
            flash('error', '"' . trim(post('signer_email')) . '" isn\'t a valid email address.');
        }
        $row['vals'] = json_encode($vals, JSON_UNESCAPED_UNICODE);
        // "Sign first" where your signature is on but sending failed (2.2.1): a change to the draft takes your
        // signature off, so it never stands under wording or prices you didn't sign
        $unsigned = false;
        $changed = json_encode($vals) !== json_encode($c['vals'])
            || array_any(array_keys(array_diff_key($row, ['vals' => 1])), fn($k) => (string) ($row[$k] ?? '') !== (string) ($c[$k] ?? ''));
        if ($c['provider_signed_at'] && $changed) {
            $row += ['provider_user_id' => null, 'provider_signature' => null, 'provider_signed_at' => null];
            $unsigned = true;
        }
        if (!DB::update('contracts', $row, ['id' => $id, 'status' => 'draft']) && Contracts::load($id)['status'] !== 'draft') {
            flash('error', 'This contract was sent meanwhile, so your changes weren\'t saved.');
            redirect("/contracts/$id");
        }
        Audit::log('contract.saved', Contracts::number($c) . ' ' . $row['title'] . ' (draft)' . ($unsigned ? '; your signature was taken off' : ''));
        if ($unsigned) {
            flash('warning', 'The draft changed, so your signature was taken off. Sign it again before sending.');
        }
        if (post('then') === 'send') {
            redirect("/contracts/$id#send");
        }
        flash('success', 'Draft saved.');
        redirect("/contracts/$id");
    }

    /** Live preview of the prepare form (unsaved), as HTML for the page. Nothing is saved. */
    public static function preview(int $id): void
    {
        $me = Auth::requireRole('tech');
        $c = self::load($id);
        $c['_src'] = "/contracts/$id/source";
        if ($c['status'] === 'draft') {
            [$row, $vals] = self::applyForm($c);
            $c = array_merge($c, $row, ['vals' => $vals]);
            $c['client_name'] = $row['client_id'] ? DB::value('SELECT name FROM clients WHERE id = ?', [$row['client_id']]) : null;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo Render::html($c, 'preview', $me);
    }

    /**
     * The contract's own PDF (as uploaded for its template), for the page viewer. Techs may see it for a contract
     * (only the template's PDF itself is admins only). Served by PdfStamp::serve with a sandbox CSP.
     */
    public static function source(int $id): void
    {
        Auth::requireRole('tech');
        $c = self::load($id);
        Audit::access('contract.source', Contracts::number($c));
        \Align\Contracts\PdfStamp::serve($c['def']['pdf'] ?? null);
    }

    /**
     * The PDF: the signed copy, the uploaded file, or (drafts and unsigned) a draft copy. Every view is audited
     * (2.2.1: the draft copy, which holds the client's details too, wasn't). Inline by default; ?download=1 makes it
     * an attachment with a sandbox CSP. The stored name is checked by Contracts::pdfPath; an uploaded file's own name
     * is only used, cleaned, for the download name.
     */
    public static function pdf(int $id): void
    {
        Auth::requireRole('tech');
        $c = self::load($id);
        $path = Contracts::pdfPath($c);
        if ($path) {
            $bytes = (string) file_get_contents($path);
            $name = $c['source'] === 'uploaded' ? ($c['pdf_name'] ?: 'contract.pdf') : Contracts::fileName($c);
            Audit::access('contract.pdf', Contracts::number($c));
        } elseif ($c['source'] === 'built') {
            $bytes = PdfRender::build($c, false);
            $name = 'DRAFT ' . Contracts::fileName($c);
            Audit::access('contract.pdf', Contracts::number($c) . ' (draft copy)');
        } else {
            http_response_code(404);
            echo 'The file is missing.';
            return;
        }
        header('Content-Type: application/pdf');
        header('X-Content-Type-Options: nosniff');
        if (query('download')) {
            header("Content-Security-Policy: default-src 'none'; sandbox"); // (a sandbox stops browsers showing a PDF inline)
        }
        header('Content-Disposition: ' . (query('download') ? 'attachment' : 'inline') . '; ' . content_filename($name));
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
    }

    /**
     * Sends the contract for signature (or makes the link only), from draft, sent (a new link) or expired. A "sign
     * first" template takes the staff signature here, before sending. Contracts::send only moves it from the status
     * it was loaded in. The link from "Create link only" is kept in the session to show once.
     */
    public static function send(int $id): void
    {
        $u = Auth::requireRole('tech');
        $c = self::load($id);
        if (!in_array($c['status'], ['draft', 'sent', 'expired'], true) || $c['source'] !== 'built') {
            flash('error', 'This contract can\'t be sent.');
            redirect("/contracts/$id");
        }
        if ($p = Contracts::readyProblems($c)) {
            flash('error', 'Before sending: ' . implode(' ', $p));
            redirect("/contracts/$id");
        }
        $linkOnly = post('action') === 'link';
        if (!$linkOnly && !Mail::ready()) {
            flash('error', 'Email isn\'t set up (Integrations → Email). Use "Create link only" and send it from your own email.');
            redirect("/contracts/$id");
        }
        // "Sign first" templates: the provider's signature goes on before it's sent
        if ($c['def']['signing']['countersign'] === 'before' && !$c['provider_signed_at']) {
            if (!isset($_POST['consent'])) {
                flash('error', 'Tick the box to agree to sign electronically.');
                redirect("/contracts/$id#send");
            }
            $sig = Contracts::signature(post('sig_kind'), post('sig_typed'), post('sig_png'), post('sig_name') ?: (string) $u['name']);
            if (is_string($sig)) {
                flash('error', $sig);
                redirect("/contracts/$id#send");
            }
            if (!Contracts::providerSign($c, $sig, post('sig_title'))) {
                flash('error', 'This contract changed meanwhile. Check it and try again.');
                redirect("/contracts/$id");
            }
            $c = self::load($id);
        }
        $subject = mb_substr(post('subject'), 0, 255) ?: ($c['def']['signing']['subject'] ?: Contracts::DEFAULT_SUBJECT);
        $message = mb_substr(post('message'), 0, 6000) ?: ($c['def']['signing']['message'] ?: Contracts::DEFAULT_MESSAGE);
        $sent = Contracts::send($c, $subject, $message, $linkOnly, isset($_POST['cc_me']));
        if ($sent === null) {
            flash('error', 'This contract changed meanwhile (it was sent, signed or cancelled a moment ago), so nothing was sent.');
            redirect("/contracts/$id");
        }
        if ($linkOnly) {
            $_SESSION['contract_link_once'][$id] = $sent['url'];
            flash('success', 'Signing link created. Copy it below; it\'s shown only once. Any earlier link no longer works.');
        } else {
            $q = $sent['mail'] ? DB::one('SELECT status, last_error FROM mail_queue WHERE id = ?', [$sent['mail']]) : null;
            flash($q && $q['status'] === 'failed' ? 'warning' : 'success', $q && $q['status'] === 'failed'
                ? 'Sending failed (' . mb_substr((string) $q['last_error'], 0, 160) . '). It\'s queued and will be retried.' : 'Sent to ' . $c['signer_email'] . '.');
        }
        redirect("/contracts/$id");
    }

    /** Emails the signer a reminder with the same link (Contracts::remind checks the status and claims the send). */
    public static function remind(int $id): void
    {
        Auth::requireRole('tech');
        $c = self::load($id);
        if (Contracts::remind($c)) {
            flash('success', 'Reminder sent to ' . $c['signer_email'] . ' with the same signing link.');
        } else {
            flash('error', 'A reminder can only be sent while the contract waits for the client, its link hasn\'t expired and email is set up.');
        }
        redirect("/contracts/$id");
    }

    /**
     * Staff countersignature once the client has signed: needs the consent box and a valid signature, and
     * completes the contract only from client_signed (Contracts::providerSign).
     */
    public static function countersign(int $id): void
    {
        $u = Auth::requireRole('tech');
        $c = self::load($id);
        if ($c['status'] !== 'client_signed') {
            flash('error', 'This contract isn\'t waiting for your signature.');
            redirect("/contracts/$id");
        }
        if (!isset($_POST['consent'])) {
            flash('error', 'Tick the box to agree to sign electronically.');
            redirect("/contracts/$id#countersign");
        }
        $sig = Contracts::signature(post('sig_kind'), post('sig_typed'), post('sig_png'), post('sig_name') ?: (string) $u['name']);
        if (is_string($sig)) {
            flash('error', $sig);
            redirect("/contracts/$id#countersign");
        }
        if (!Contracts::providerSign($c, $sig, post('sig_title'))) {
            flash('error', 'This contract was already signed or cancelled meanwhile.');
            redirect("/contracts/$id");
        }
        $done = Contracts::load($id);
        $made = $done && Contracts::pdfPath($done);
        flash('success', 'Signed. The contract is complete' . ($made ? (Mail::ready() ? ' and the signed copy has been emailed to ' . $c['signer_email'] : ' and the signed PDF is saved with it') : '; the signed PDF will be ready shortly') . '.');
        redirect("/contracts/$id");
    }

    /** Cancels a contract out for signature (its link stops working). Contracts::void only acts from those statuses. */
    public static function void(int $id): void
    {
        Auth::requireRole('tech');
        $c = self::load($id);
        if (Contracts::void($c, post('reason'))) {
            flash('success', 'Contract cancelled. Its signing link no longer works.');
        } else {
            flash('error', 'Only a contract that\'s out for signature can be cancelled. Signed and draft contracts are left as they are.');
        }
        redirect("/contracts/$id");
    }

    /**
     * Deleting a contract. A draft, or one cancelled, declined or expired: any tech. A signed one (waiting for your
     * countersignature, complete, or uploaded): an admin, who types its number to confirm; the audit log keeps what
     * was deleted, with the signed PDF's fingerprint. One out for signature is cancelled first.
     */
    public static function delete(int $id): void
    {
        Auth::requireRole('tech');
        $c = self::load($id);
        if ($c['status'] === 'sent') {
            flash('error', 'This contract is out for signature. Cancel it first (its signing link stops working), then delete it.');
            redirect("/contracts/$id");
        }
        $signed = self::signed($c);
        if ($signed && !Auth::can('admin')) {
            flash('error', 'Only an admin can delete a signed contract.');
            redirect("/contracts/$id");
        }
        if ($signed && strtoupper(trim(post('confirm'))) !== Contracts::number($c)) {
            flash('error', 'Type the contract number (' . Contracts::number($c) . ') to delete it.');
            redirect("/contracts/$id");
        }
        // Only in the state it was checked in: one sent or signed a moment ago stays
        if (!DB::run('DELETE FROM contracts WHERE id = ? AND status = ? AND source = ?', [$id, $c['status'], $c['source']])->rowCount()) {
            flash('error', 'This contract changed meanwhile, so it wasn\'t deleted.');
            redirect("/contracts/$id");
        }
        if ($p = Contracts::pdfPath($c)) {
            @unlink($p);
        }
        if (!empty($c['def']['pdf']['file'])) {
            \Align\Contracts\PdfStamp::removeIfUnused($c['def']['pdf']['file']);
        }
        Audit::log($signed ? 'contract.deleted_signed' : 'contract.deleted', Contracts::number($c) . ' ' . $c['title'] . ' (' . Contracts::party($c) . ', '
            . Contracts::status($c)[0] . ($c['signer_name'] ? ', signer ' . $c['signer_name'] : '') . ($c['pdf_hash'] ? ', PDF SHA-256 ' . $c['pdf_hash'] : '') . ')');
        flash('success', 'Contract ' . Contracts::number($c) . ' deleted.');
        redirect('/contracts' . match (true) {
            $c['status'] === 'draft' => '?show=draft',
            $c['status'] === 'completed' || $c['source'] === 'uploaded' => '?show=signed',
            $c['status'] === 'client_signed' => '',
            default => '?show=closed',
        });
    }

    /**
     * Signed by the client (or uploaded signed): deleting it needs an admin and the typed number. Also true for a
     * contract the client signed and staff then cancelled (2.2.1): before, Cancel then Delete let a tech remove a
     * client's signature, consent and signing trail as if it had never been signed.
     */
    public static function signed(array $c): bool
    {
        return $c['source'] === 'uploaded' || !empty($c['client_signed_at']) || in_array($c['status'], ['client_signed', 'completed'], true);
    }

    /**
     * Signed contract for a lead: make it a client (or link an existing one). Only once it's signed (or uploaded) and
     * only while it has no client, so a signed record can't be moved to another client.
     * A contract whose client was deleted also has no client: only an admin may link it again (to put back a client
     * deleted by mistake), so a tech can't attach one company's signed contract to another (2.2.1).
     */
    public static function client(int $id): void
    {
        Auth::requireRole('tech');
        $c = self::load($id);
        if ($c['client_id'] || ($c['status'] !== 'completed' && $c['source'] !== 'uploaded')) {
            flash('error', 'Only a signed contract that isn\'t linked to a client yet can be made a client\'s.');
            redirect("/contracts/$id");
        }
        if (self::clientDeleted($id) && !Auth::can('admin')) {
            flash('error', 'This contract\'s client was deleted. Only an admin can link it to a client again.');
            redirect("/contracts/$id");
        }
        if ((int) post('client_id')) {
            $cl = ClientController::load((int) post('client_id'));
            if (!DB::run('UPDATE contracts SET client_id = ? WHERE id = ? AND client_id IS NULL', [$cl['id'], $id])->rowCount()) {
                flash('error', 'This contract was linked to a client meanwhile.');
                redirect("/contracts/$id");
            }
            Contracts::event($id, 'linked', $cl['name']);
            Audit::log('contract.linked', Contracts::number($c) . ' → ' . $cl['name']);
            flash('success', 'Linked to ' . $cl['name'] . '.');
            redirect("/contracts/$id");
        }
        try {
            [$cid, $existed] = Contracts::createClient($c);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect("/contracts/$id");
        }
        flash('success', ($existed ? 'Linked to the client ' . DB::value('SELECT name FROM clients WHERE id = ?', [$cid]) . ', who was already in Align.' : 'Client created.')
            . ' Next: send them the welcome email from their Onboarding page.');
        redirect("/clients/$cid/onboarding");
    }

    /** Whether the contract's client was deleted (the contract keeps the name, and a "client_deleted" event). */
    public static function clientDeleted(int $id): bool
    {
        return (bool) DB::value("SELECT 1 FROM contract_events WHERE contract_id = ? AND event = 'client_deleted' LIMIT 1", [$id]);
    }

    /** Details kept with a signed or uploaded contract: dates (real calendar dates only) and notes. */
    public static function details(int $id): void
    {
        Auth::requireRole('tech');
        $c = self::load($id);
        if ($c['status'] !== 'completed' && $c['source'] !== 'uploaded') {
            flash('error', 'Dates and notes are kept once the contract is signed.');
            redirect("/contracts/$id");
        }
        $d = fn(string $k) => Contracts::date(post($k));
        $row = ['starts_on' => $d('starts_on'), 'ends_on' => $d('ends_on'), 'notes' => mb_substr(trim(post('notes')), 0, 4000) ?: null];
        if ($c['source'] === 'uploaded') {
            $row += ['title' => mb_substr(post('title'), 0, 190) ?: $c['title'], 'signed_on' => $d('signed_on') ?: $c['signed_on']];
        }
        DB::update('contracts', $row, ['id' => $id]);
        Audit::log('contract.details', Contracts::number($c));
        flash('success', 'Saved.');
        redirect("/contracts/$id");
    }

    /**
     * A contract signed somewhere else (DocuSeal, paper): the PDF and its dates. Contracts::storeUpload checks it's a
     * real upload, up to 25 MB, that starts as a PDF, and keeps it under a random name. The redirect target is a
     * same-site path only (Security::safePath).
     */
    public static function upload(): void
    {
        $u = Auth::requireRole('tech');
        $back = Security::safePath(post('back'), '/contracts?show=signed');
        $client = (int) post('client_id') ? ClientController::loadRow((int) post('client_id')) : null;
        $lead = mb_substr(trim(post('lead_company')), 0, 190);
        if (!$client && $lead === '') {
            flash('error', 'Choose the client (or type the company name).');
            redirect($back);
        }
        try {
            $f = Contracts::storeUpload($_FILES['file'] ?? []);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }
        $d = fn(string $k) => Contracts::date(post($k));
        $id = (int) DB::insert('contracts', [
            'source' => 'uploaded', 'status' => 'completed',
            'title' => mb_substr(post('title'), 0, 190) ?: preg_replace('/\.pdf$/i', '', $f['name']),
            'client_id' => $client['id'] ?? null, 'lead_company' => $client ? null : $lead,
            'pdf_file' => $f['file'], 'pdf_name' => $f['name'], 'pdf_hash' => $f['hash'],
            'signed_on' => $d('signed_on'), 'starts_on' => $d('starts_on'), 'ends_on' => $d('ends_on'),
            'completed_at' => date('Y-m-d H:i:s'), 'notes' => mb_substr(trim(post('notes')), 0, 4000) ?: null, 'created_by' => $u['id'],
        ]);
        Contracts::event($id, 'uploaded', $f['name'] . ' (SHA-256 ' . $f['hash'] . ')');
        Audit::log('contract.uploaded', Contracts::number(['id' => $id]) . ' ' . $f['name'] . ' for ' . ($client['name'] ?? $lead));
        flash('success', 'Signed contract uploaded.');
        redirect("/contracts/$id");
    }

    /**
     * Is this PDF one of ours, unchanged? Compares its SHA-256 with the signed copies Align keeps. The file is read
     * from PHP's upload and not kept. Its name is only shown (escaped) and written to the audit log.
     */
    public static function verify(): void
    {
        Auth::requireRole('tech');
        $result = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $f = $_FILES['file'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name']) || filesize($f['tmp_name']) > Contracts::MAX_UPLOAD) {
                flash('error', in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($f && is_file((string) $f['tmp_name']) && filesize($f['tmp_name']) > Contracts::MAX_UPLOAD)
                    ? 'The PDF is larger than 25 MB.' : 'Choose a PDF to check.');
                redirect('/contracts/verify');
            }
            $hash = hash_file('sha256', $f['tmp_name']);
            $row = DB::one('SELECT id FROM contracts WHERE pdf_hash = ?', [$hash]);
            $result = ['hash' => $hash, 'name' => (string) $f['name'], 'contract' => $row ? Contracts::load((int) $row['id']) : null];
            Audit::log('contract.verify', $f['name'] . ': ' . ($row ? 'matches ' . Contracts::number($result['contract']) : 'no match'));
        }
        View::render('contracts/verify', ['title' => 'Check a signed contract', 'nav' => 'contracts', 'result' => $result]);
    }
}
