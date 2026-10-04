<?php
declare(strict_types=1);

namespace Align\Docs;

use Align\Auth;
use Align\DB;
use Align\Settings;

/**
 * Document data helpers: categories, loading, template placeholders, version snapshots and presence.
 * Security assumptions: callers check the role (DocumentController) or the portal client and sharing
 * (PortalController). Nothing here sanitizes HTML: bodies passed in are already cleaned by Html::clean.
 */
final class Documents
{
    public const CATEGORIES = [
        'wisp' => ['WISP', 'fa-shield-halved', 'danger'],
        'policy' => ['Policy', 'fa-scale-balanced', 'primary'],
        'procedure' => ['Procedure / SOP', 'fa-list-ol', 'info'],
        'plan' => ['Plan (IR / DR / BCP)', 'fa-life-ring', 'warning'],
        'assessment' => ['Assessment / record', 'fa-clipboard-list', 'purple'],
        'network' => ['Network & systems', 'fa-network-wired', 'teal'],
        'notes' => ['Notes', 'fa-note-sticky', 'secondary'],
        'other' => ['Other', 'fa-file-lines', 'dark'],
    ];

    /** Categories shared in the client portal by default when a document is created. */
    public const PORTAL_DEFAULT = ['wisp', 'policy', 'procedure', 'plan'];

    public const STATUSES = [
        'draft' => ['Draft', 'secondary'],
        'active' => ['Active', 'success'],
        'archived' => ['Archived', 'dark'],
    ];

    /** Autosaves create a history entry at most this often (per editor). */
    private const SNAPSHOT_EVERY = 600;

    /** [label, icon, color] for a category; unknown ones show as Other. */
    public static function category(string $c): array
    {
        return self::CATEGORIES[$c] ?? self::CATEGORIES['other'];
    }

    /** A document with its client and editor names, or null. No access check: callers do that. */
    public static function load(int $id): ?array
    {
        return DB::one('SELECT d.*, c.name AS client_name, u.name AS updated_by_name, cu.name AS created_by_name
            FROM documents d LEFT JOIN clients c ON c.id = d.client_id
            LEFT JOIN users u ON u.id = d.updated_by LEFT JOIN users cu ON cu.id = d.created_by WHERE d.id = ?', [$id]);
    }

    /** Placeholder values for templates (plain text; fill() escapes them). $client null gives the [bracket] defaults. */
    public static function placeholders(?array $client): array
    {
        $vcio = $client && $client['vcio_user_id'] ? DB::value('SELECT name FROM users WHERE id = ?', [$client['vcio_user_id']]) : null;
        return [
            'client_name' => $client['name'] ?? '[Client name]',
            'client_address' => $client['address'] ?? '[Address]',
            'client_contact' => $client['contact_name'] ?? '[Primary contact]',
            'client_contact_email' => $client['contact_email'] ?? '[contact email]',
            'client_phone' => ($client['main_phone'] ?? null) ?: ($client['contact_phone'] ?? '[phone]'),
            'client_website' => $client['website'] ?? '[website]',
            'vcio_name' => $vcio ?: (Auth::user()['name'] ?? '[vCIO]'),
            'company_name' => Settings::get('company_name') ?: 'Your company',
            'company_phone' => Settings::get('company_phone') ?: '[our phone]',
            'company_email' => Settings::get('company_email') ?: '[our email]',
            'company_website' => Settings::get('company_website') ?: '[our website]',
            'today' => \Align\Fmt::date(time(), 'long'),
            'year' => date('Y'),
        ];
    }

    /**
     * Replaces {{placeholders}} in template HTML with the client's details, HTML-escaped.
     * Escaping covers text and quoted attributes only (a value can still land in an href), so the result must go
     * through Html::clean before it is stored.
     */
    public static function fill(string $html, ?array $client): string
    {
        $vals = self::placeholders($client);
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($vals) {
            return isset($vals[$m[1]]) ? htmlspecialchars($vals[$m[1]], ENT_QUOTES, 'UTF-8') : $m[0];
        }, $html) ?? $html;
    }

    /**
     * Adds a history entry with the document's current title and body. $kind is one of the document_versions kinds.
     * The caller holds the row (or has just written it) so the entry matches what was saved.
     */
    public static function snapshot(array $doc, string $kind, ?string $note = null): void
    {
        DB::insert('document_versions', [
            'document_id' => $doc['id'],
            'version' => $doc['version'],
            'title' => $doc['title'],
            'body_html' => $doc['body_html'],
            'note' => $note ? mb_substr($note, 0, 255) : null,
            'kind' => $kind,
            'saved_by' => Auth::id(),
        ]);
    }

    /** Autosaves snapshot when the last snapshot is old, or a different person saved it. */
    public static function snapshotDue(int $docId): bool
    {
        $last = DB::one('SELECT saved_by, saved_at FROM document_versions WHERE document_id = ? ORDER BY id DESC LIMIT 1', [$docId]);
        return !$last || (int) $last['saved_by'] !== (int) Auth::id() || strtotime($last['saved_at']) < time() - self::SNAPSHOT_EVERY;
    }

    /** Documents a client has (for evidence pickers). The caller has checked access to the client. */
    public static function forClient(int $clientId): array
    {
        return DB::all("SELECT id, title, category, status FROM documents WHERE client_id = ? AND status <> 'archived' ORDER BY title", [$clientId]);
    }

    /** Other people currently viewing/editing a document (seen in the last 45 seconds); clears entries older than 2 minutes. */
    public static function presence(int $docId): array
    {
        DB::run('DELETE FROM document_presence WHERE last_seen < ?', [date('Y-m-d H:i:s', time() - 120)]);
        return DB::all('SELECT p.user_id, p.editing, p.last_seen, u.name, u.avatar_file FROM document_presence p JOIN users u ON u.id = p.user_id
            WHERE p.document_id = ? AND p.user_id <> ? AND p.last_seen >= ?', [$docId, (int) Auth::id(), date('Y-m-d H:i:s', time() - 45)]);
    }
}
