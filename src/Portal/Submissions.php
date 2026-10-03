<?php
declare(strict_types=1);

namespace Align\Portal;

use Align\Audit;
use Align\Budget\Budget;
use Align\DB;
use Align\Licensing\Licenses;
use Align\Mail\Mailer;
use Align\Mail\Notifications as N;
use Align\Mail\Template as T;

/**
 * Licenses and budget items a client suggests from the portal (1.39). They wait here until staff accept
 * them, as a real license or budget line (edited first if needed), or decline them with a note. Until then
 * nothing changes in the plan or the budget; the client sees the suggestion as waiting.
 *
 * Security assumptions: everything in a suggestion is client-written text, stored as given (cleaned) and escaped
 * wherever it is shown. Every lookup takes the client id from the caller (the portal user's own, or the staff
 * page's client), never from the suggestion. Status changes only happen from 'pending', so parallel decisions
 * can't both land.
 */
final class Submissions
{
    public const KINDS = ['license' => 'License', 'budget' => 'Budget item'];
    public const STATUS = ['pending' => ['Waiting for review', 'warning'], 'accepted' => ['Added', 'success'], 'declined' => ['Declined', 'secondary'], 'withdrawn' => ['Withdrawn', 'light border']];
    /** Budget categories a client can pick (managed services are the IT provider's own line). */
    public const CLIENT_BUDGET_CATEGORIES = ['connectivity', 'telecom', 'cloud', 'other'];

    /** Whether suggestions are switched on (Client portal users page) and portal user $pu may send them (can_budget + can_submit). */
    public static function allowed(array $pu): bool
    {
        return \Align\Settings::get('portal_submissions', '1') === '1' && !empty($pu['can_budget']) && !empty($pu['can_submit']);
    }

    /**
     * The client's form, checked. Returns [data, errors]. Only plain values are kept; staff see and can
     * change every field before anything is added. $in is the raw, untrusted $_POST; unknown keys are ignored.
     */
    public static function fromPost(string $kind, array $in): array
    {
        // Only text counts: a field sent as a list (name[]=…) is treated as empty. Invalid UTF-8 becomes "?" (2.2.1):
        // it made json_encode() fail, so the suggestion was saved with no details, or the insert failed.
        $in = array_map(fn($v) => is_string($v) ? mb_scrub($v, 'UTF-8') : '', $in);
        // Names are one line (they become email subjects and audit entries): control characters and line breaks
        // turn into spaces (2.2.1). ASCII control bytes never occur inside a UTF-8 character, so no /u is needed.
        $s = fn(string $k, int $len) => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $in[$k] ?? '') ?? ''), 0, $len);
        // Notes keep their line breaks and tabs only
        $text = fn(string $k, int $len) => mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace(["\r\n", "\r"], "\n", $in[$k] ?? '')) ?? ''), 0, $len);
        // D: without it "2026-01-31\n" passed and the line break was kept (2.2.1)
        $date = fn(string $k) => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $in[$k] ?? '', $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $in[$k] : null;
        $money = function (string $k) use ($in): ?float {
            $v = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($in[$k] ?? ''));
            return $v !== '' && is_numeric($v) && (float) $v >= 0 && (float) $v < 100000000 ? round((float) $v, 2) : null;
        };
        $errors = [];
        $name = $s('name', 255);
        if ($name === '') {
            $errors[] = $kind === 'license' ? 'Enter the product name.' : 'Enter what the cost is for.';
        }
        if ($kind === 'license') {
            $seats = trim($in['seats'] ?? '');
            $d = [
                'name' => $name, 'vendor' => $s('vendor', 190) ?: null,
                'category' => isset(Licenses::CATEGORIES[$in['category'] ?? '']) ? (string) $in['category'] : 'other',
                'license_type' => isset(Licenses::TYPES[$in['license_type'] ?? '']) ? (string) $in['license_type'] : 'user',
                'seats' => ctype_digit($seats) && (int) $seats <= 1000000 ? (int) $seats : null,
                'pricing' => ($in['pricing'] ?? '') === 'flat' ? 'flat' : 'per_seat',
                'unit_price' => $money('unit_price'),
                'billing_cycle' => isset(Licenses::CYCLES[$in['billing_cycle'] ?? '']) ? (string) $in['billing_cycle'] : 'monthly',
                'expire_date' => $date('expire_date'),
                'notes' => $text('notes', 2000) ?: null,
            ];
            if (trim($in['unit_price'] ?? '') !== '' && $d['unit_price'] === null) {
                $errors[] = 'The price must be a number.';
            }
        } else {
            $d = [
                'name' => $name, 'vendor' => $s('vendor', 190) ?: null,
                'category' => in_array($in['category'] ?? '', self::CLIENT_BUDGET_CATEGORIES, true) ? (string) $in['category'] : 'other',
                'amount' => $money('amount'),
                'frequency' => isset(Budget::FREQUENCIES[$in['frequency'] ?? '']) ? (string) $in['frequency'] : 'monthly',
                'start_date' => $date('start_date'),
                'notes' => $text('notes', 2000) ?: null,
            ];
            if ($d['amount'] === null) {
                $errors[] = 'Enter the amount (a number, 0 if you don\'t know it yet).';
            }
        }
        return [$d, $errors];
    }

    /**
     * Saves a checked suggestion (from fromPost()) for portal user $pu's own client, logs it and tells staff.
     * $kind must be a KINDS key. The caller checked allowed() and the rate limit.
     */
    public static function create(array $pu, string $kind, array $data): int
    {
        $id = (int) DB::insert('portal_submissions', ['client_id' => (int) $pu['client_id'], 'kind' => $kind, 'title' => $data['name'],
            'data' => json_encode($data), 'portal_user_id' => (int) $pu['id'], 'submitted_by_name' => mb_substr((string) $pu['name'], 0, 190)]);
        Audit::log('portal.submission', "{$pu['client_name']}: " . self::KINDS[$kind] . " \"{$data['name']}\"");
        $path = '/clients/' . (int) $pu['client_id'] . ($kind === 'license' ? '/licenses' : '/budget') . '#client-submissions';
        \Align\Mail\Notify::portalActivity((int) $pu['client_id'], (string) $pu['client_name'], (string) $pu['name'],
            'suggested ' . ($kind === 'license' ? 'a license' : 'a budget item') . ': ' . $data['name'] . ' (waiting for your review)', $path);
        return $id;
    }

    /** A row with its JSON data decoded (an empty array when it can't be read). */
    private static function decode(array $r): array
    {
        $r['data'] = json_decode((string) $r['data'], true) ?: [];
        return $r;
    }

    /** Waiting suggestions for a client (staff pages, which checked the role), optionally of one kind. */
    public static function pending(int $clientId, ?string $kind = null): array
    {
        return array_map([self::class, 'decode'], DB::all("SELECT * FROM portal_submissions WHERE client_id = ? AND status = 'pending'" . ($kind ? ' AND kind = ?' : '') . ' ORDER BY id',
            $kind ? [$clientId, $kind] : [$clientId]));
    }

    /** What the client sees: everything waiting, plus what was decided in the last 90 days. $clientId is the portal user's own. */
    public static function forClient(int $clientId, string $kind): array
    {
        return array_map([self::class, 'decode'], DB::all("SELECT * FROM portal_submissions WHERE client_id = ? AND kind = ?
            AND (status = 'pending' OR COALESCE(decided_at, created_at) >= NOW() - INTERVAL 90 DAY) ORDER BY status = 'pending' DESC, id DESC LIMIT 50", [$clientId, $kind]));
    }

    /** A waiting suggestion of this client, or null ($id is untrusted; another client's id matches nothing). */
    public static function find(int $id, int $clientId): ?array
    {
        $r = DB::one("SELECT * FROM portal_submissions WHERE id = ? AND client_id = ? AND status = 'pending'", [$id, $clientId]);
        return $r ? self::decode($r) : null;
    }

    /** Values for the staff Add license / Add budget line form; the form escapes them and staff review them before saving. */
    public static function prefill(array $s): array
    {
        $d = $s['data'];
        $note = 'Suggested by ' . $s['submitted_by_name'] . ' in the client portal on ' . fmt_date($s['created_at']) . '.' . (!empty($d['notes']) ? "\n" . $d['notes'] : '');
        return $s['kind'] === 'license'
            ? ['name' => $d['name'] ?? '', 'vendor' => $d['vendor'] ?? null, 'category' => $d['category'] ?? 'other', 'license_type' => $d['license_type'] ?? 'user',
                'seats' => $d['seats'] ?? null, 'pricing' => $d['pricing'] ?? 'per_seat', 'unit_price' => $d['unit_price'] ?? null, 'billing_cycle' => $d['billing_cycle'] ?? 'monthly',
                'expire_date' => $d['expire_date'] ?? null, 'align_notes' => $note, 'submission_id' => (int) $s['id']]
            : ['name' => $d['name'] ?? '', 'vendor' => $d['vendor'] ?? null, 'category' => $d['category'] ?? 'other', 'amount' => $d['amount'] ?? 0,
                'frequency' => $d['frequency'] ?? 'monthly', 'start_date' => $d['start_date'] ?? null, 'notes' => $note, 'submission_id' => (int) $s['id']];
    }

    /**
     * Staff accepted it: marks the suggestion as added (linked to the new license or budget line) and tells the
     * client. Called inside the transaction that creates the item; false when someone else already decided it.
     */
    public static function accept(int $id, int $clientId, string $kind, int $itemId, ?int $userId): bool
    {
        $n = DB::run("UPDATE portal_submissions SET status = 'accepted', decided_at = NOW(), decided_by = ?, item_id = ? WHERE id = ? AND client_id = ? AND kind = ? AND status = 'pending'",
            [$userId, $itemId, $id, $clientId, $kind])->rowCount();
        if ($n) {
            self::tellClientSafely($id);
        }
        return $n === 1;
    }

    /**
     * Staff declined it with an optional note the client sees (cut to 2000 characters). Returns the suggestion, or
     * null when it isn't this client's or was already decided. The caller checked the staff role.
     */
    public static function decline(int $id, int $clientId, string $note, ?int $userId): ?array
    {
        $s = self::find($id, $clientId);
        if (!$s || DB::run("UPDATE portal_submissions SET status = 'declined', decided_at = NOW(), decided_by = ?, decision_note = ? WHERE id = ? AND status = 'pending'",
            [$userId, mb_substr($note, 0, 2000) ?: null, $id])->rowCount() !== 1) {
            return null;
        }
        self::tellClientSafely($id);
        return $s;
    }

    /**
     * The person who sent a suggestion takes it back while it's still waiting (works even with suggestions switched
     * off). $pu is the signed-in portal user: only their own suggestion of their own client matches.
     */
    public static function withdraw(int $id, array $pu): ?array
    {
        $s = DB::one("SELECT * FROM portal_submissions WHERE id = ? AND client_id = ? AND portal_user_id = ? AND status = 'pending'", [$id, $pu['client_id'], $pu['id']]);
        if (!$s || DB::run("UPDATE portal_submissions SET status = 'withdrawn', decided_at = NOW() WHERE id = ? AND status = 'pending'", [$id])->rowCount() !== 1) {
            return null;
        }
        return $s;
    }

    /** The email is a courtesy: a problem building or queuing it must never undo the decision. */
    private static function tellClientSafely(int $id): void
    {
        try {
            self::tellClient($id);
        } catch (\Throwable $e) {
            error_log('[msp-align] suggestion email: ' . $e->getMessage());
        }
    }

    /**
     * Emails the person who suggested it when it's decided (Settings → Notifications: "Portal suggestions"); not when
     * they were disabled or deleted. The title is the client's own text: the template escapes it and the mail layer
     * keeps line breaks out of the subject.
     */
    private static function tellClient(int $id): void
    {
        if (!N::enabled('client_submission_decided')) {
            return;
        }
        $s = DB::one('SELECT s.*, p.email, p.name, p.is_active, c.name AS client_name FROM portal_submissions s LEFT JOIN portal_users p ON p.id = s.portal_user_id
            JOIN clients c ON c.id = s.client_id WHERE s.id = ?', [$id]);
        if (!$s || !$s['email'] || !$s['is_active']) {
            return;
        }
        $company = \Align\Settings::get('company_name') ?: 'Your IT provider';
        $what = $s['kind'] === 'license' ? 'license' : 'budget item';
        $added = $s['status'] === 'accepted';
        $blocks = [T::p('Hi ' . $s['name'] . ','),
            T::p($added ? "$company added the $what you suggested, \"{$s['title']}\", to your " . ($s['kind'] === 'license' ? 'licensing' : 'technology budget') . '.'
                : "$company reviewed the $what you suggested, \"{$s['title']}\", and didn't add it."),
        ];
        if (!$added && $s['decision_note']) {
            $blocks[] = T::facts(['Note' => $s['decision_note']]);
        }
        $blocks[] = T::button('Open the client portal', N::url($s['kind'] === 'license' ? '/portal/licensing' : '/portal/budget'));
        Mailer::queue('client_submission_decided', [['address' => $s['email'], 'name' => $s['name']]],
            ($added ? 'Added: ' : 'Reviewed: ') . $s['title'], T::render($added ? 'Your suggestion was added' : 'Your suggestion was reviewed', $blocks, 'Sent by ' . $company . ' through MSP-ALIGN.'),
            ['client_id' => (int) $s['client_id'], 'created_by' => null, 'dedupe' => 'sub:' . $id]);
    }
}
