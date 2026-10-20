<?php
declare(strict_types=1);

namespace Align\Reports;

use Align\Contacts\Contacts;
use Align\DB;

/**
 * 2.24.0 Contact review report: a client's current contacts with their details, roles, client portal access and
 * licenses, laid out for the client to check and send back (ReportController::renderContacts, the CSV export, and the
 * client portal's copy).
 *
 * Security assumptions: callers check access to the client first (a staff role, or the portal's can_documents for the
 * user's own client) and pass only that client's id; every query is scoped to it. Align's own contact notes and the
 * PSA's notes are never included. Licenses are only read when the caller asks (the portal asks only for users who may
 * see licensing). Values are returned as plain text; the view escapes them and the CSV export neutralizes formulas.
 */
final class ContactsReport
{
    /** Role columns shown to the client, in order (Contacts::ROLES labels; "Meeting invitee" is an internal setting). */
    public const ROLES = ['is_primary', 'decision_maker', 'is_billing', 'is_technical', 'is_important'];

    /**
     * The report's data for one client: 'contacts' (active contacts, primary and decision makers first, each with
     * 'roles' (labels), 'portal' (null, 'Active', 'Invited' or 'Invite expired') and 'licenses' (names; empty when not asked for)) and
     * 'portalOnly' (active portal users whose email matches no active contact: name, email, status).
     */
    public static function data(int $clientId, bool $licenses = true): array
    {
        $portal = [];
        foreach (DB::all('SELECT name, email, totp_enabled, last_login_at, invite_expires_at FROM portal_users WHERE client_id = ? AND is_active = 1 ORDER BY name', [$clientId]) as $u) {
            $portal[mb_strtolower(trim((string) $u['email']))] = ['name' => (string) $u['name'], 'email' => (string) $u['email'],
                'status' => match (true) {
                    $u['totp_enabled'] || $u['last_login_at'] => 'Active',
                    $u['invite_expires_at'] !== null && strtotime((string) $u['invite_expires_at']) < time() => 'Invite expired',
                    default => 'Invited',
                }];
        }
        $lic = $licenses ? \Align\Licensing\Assignments::byContact($clientId) : [];
        $rows = [];
        $seen = [];
        foreach (Contacts::load($clientId) as $k) {
            $email = mb_strtolower(trim((string) $k['email']));
            $p = $email !== '' ? ($portal[$email] ?? null) : null;
            if ($p) {
                $seen[$email] = true;
            }
            $names = array_values(array_unique(array_map(fn($a) => (string) $a['license_name'], $lic[(int) $k['id']] ?? [])));
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
            $rows[] = [
                'name' => (string) $k['name'],
                'title' => (string) ($k['title'] ?? ''),
                'department' => (string) ($k['department'] ?? ''),
                'email' => (string) ($k['email'] ?? ''),
                'phone' => Contacts::phone($k),
                'mobile' => (string) ($k['mobile'] ?? ''),
                'location' => (string) ($k['location'] ?? ''),
                'roles' => array_values(array_map(fn($c) => Contacts::ROLES[$c][0], array_filter(self::ROLES, fn($c) => !empty($k[$c])))),
                'portal' => $p['status'] ?? null,
                'licenses' => $names,
            ];
        }
        $portalOnly = array_values(array_diff_key($portal, $seen));
        return ['contacts' => $rows, 'portalOnly' => $portalOnly];
    }

    /**
     * Sends the contacts as a CSV download with blank "Still here?" and "Changes" columns for the client to fill in.
     * Every cell goes through Security::csvCell (names and titles come from the PSA). $fname must already be a safe file
     * name (the caller builds it from letters, digits and the date).
     */
    public static function csv(array $data, string $fname, bool $licenses): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        $out = fopen('php://output', 'w');
        $roleLabels = array_map(fn($c) => Contacts::ROLES[$c][0], self::ROLES);
        fputcsv($out, ['Name', 'Title', 'Department', 'Email', 'Phone', 'Mobile', 'Location', ...$roleLabels, 'Client portal',
            ...($licenses ? ['Licenses'] : []), 'Still here? (Yes/No)', 'Changes'], escape: '');
        foreach ($data['contacts'] as $r) {
            fputcsv($out, array_map([\Align\Security::class, 'csvCell'], [$r['name'], $r['title'], $r['department'], $r['email'], $r['phone'],
                $r['mobile'], $r['location'], ...array_map(fn($l) => in_array($l, $r['roles'], true) ? 'Yes' : '', $roleLabels),
                $r['portal'] ?? '', ...($licenses ? [implode('; ', $r['licenses'])] : []), '', '']), escape: '');
        }
        fclose($out);
    }
}
