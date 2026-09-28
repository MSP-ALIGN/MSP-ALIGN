<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Api\Keys;
use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Settings;
use Align\View;

/** Settings -> API: switch the API on, create and manage keys, see recent requests, read the docs. */
final class ApiSettingsController
{
    public static function index(): void
    {
        Auth::requireRole('admin');
        $keys = array_map([Keys::class, 'decode'], DB::all('SELECT k.*, u.name AS created_by_name,
                (SELECT COUNT(*) FROM api_requests r WHERE r.key_id = k.id AND r.created_at >= ?) AS requests_24h,
                (SELECT COUNT(*) FROM api_requests r WHERE r.key_id = k.id AND r.created_at >= ? AND r.status >= 400) AS errors_24h
            FROM api_keys k LEFT JOIN users u ON u.id = k.created_by ORDER BY k.revoked_at IS NOT NULL, k.name', [date('Y-m-d H:i:s', time() - 86400), date('Y-m-d H:i:s', time() - 86400)]));
        $new = $_SESSION['api_new_token'] ?? null;
        unset($_SESSION['api_new_token']);
        View::render('settings/api', [
            'title' => 'API',
            'nav' => 'settings',
            'enabled' => Keys::enabled(),
            'keys' => $keys,
            'new' => $new,
            'clients' => DB::all('SELECT id, name FROM clients WHERE is_archived = 0 ORDER BY name'),
            'requests' => DB::all('SELECT r.*, k.name AS key_name FROM api_requests r LEFT JOIN api_keys k ON k.id = r.key_id ORDER BY r.id DESC LIMIT 50'),
            'stats' => DB::one('SELECT COUNT(*) AS n, SUM(status >= 400) AS errors, SUM(status = 429) AS limited, ROUND(AVG(ms)) AS avg_ms FROM api_requests WHERE created_at >= ?', [date('Y-m-d H:i:s', time() - 86400)]),
            'baseUrl' => rtrim((string) \Align\Config::get('base_url', ''), '/') . '/api/v1',
        ]);
    }

    public static function toggle(): void
    {
        Auth::requireRole('admin');
        $on = post('enabled') === '1';
        Settings::set('api_enabled', $on ? '1' : '0');
        Audit::log('api.' . ($on ? 'enable' : 'disable'), 'REST API turned ' . ($on ? 'on' : 'off'));
        flash('success', $on ? 'The API is on. Create a key to start using it.' : 'The API is off. Keys are kept but every request is refused until you turn it back on.');
        redirect('/settings/api');
    }

    /** Validated key settings from the form. Returns [fields, error]. */
    private static function fields(): array
    {
        $name = trim(post('name'));
        if ($name === '') {
            return [null, 'Give the key a name, like "n8n billing workflow".'];
        }
        $preset = post('preset');
        $scopes = isset(Keys::PRESETS[$preset]) ? Keys::preset($preset) : Keys::normalize((array) ($_POST['scopes'] ?? []));
        if (!$scopes) {
            return [null, 'Pick at least one permission.'];
        }
        $clients = null;
        if (post('client_scope') === 'some') {
            $valid = array_flip(array_map('intval', array_column(DB::all('SELECT id FROM clients'), 'id')));
            $clients = array_values(array_filter(array_map('intval', (array) ($_POST['client_ids'] ?? [])), fn($i) => isset($valid[$i])));
            if (!$clients) {
                return [null, 'Pick the clients this key may use, or choose All clients.'];
            }
        }
        $exp = post('expires');
        $expiresAt = match (true) {
            $exp === 'never' => null,
            ctype_digit($exp) && (int) $exp > 0 && (int) $exp <= 1095 => date('Y-m-d 23:59:59', strtotime('+' . (int) $exp . ' days')),
            $exp === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', post('expires_on')) && post('expires_on') > date('Y-m-d') => post('expires_on') . ' 23:59:59',
            default => false,
        };
        if ($expiresAt === false) {
            return [null, 'Pick when the key expires (a date in the future), or Never.'];
        }
        $rate = (int) post('rate_limit');
        if ($rate < 1 || $rate > Keys::MAX_RATE) {
            return [null, 'Rate limit must be between 1 and ' . Keys::MAX_RATE . ' requests per minute.'];
        }
        return [['name' => mb_substr($name, 0, 120), 'scopes' => $scopes, 'clients' => $clients, 'expires_at' => $expiresAt, 'rate_limit' => $rate,
            'notes' => mb_substr(trim(post('notes')), 0, 500) ?: null], null];
    }

    public static function create(): void
    {
        Auth::requireRole('admin');
        [$f, $err] = self::fields();
        if ($err) {
            flash('error', $err);
            redirect('/settings/api?new=1');
        }
        [$id, $token] = Keys::create($f['name'], $f['scopes'], $f['clients'], $f['expires_at'], $f['rate_limit'], $f['notes'], Auth::id());
        Audit::log('api.key_create', "{$f['name']} (#$id): " . implode(' ', $f['scopes']) . ($f['clients'] ? '; clients ' . implode(',', $f['clients']) : '; all clients')
            . ($f['expires_at'] ? '; expires ' . substr($f['expires_at'], 0, 10) : '; no expiry'));
        $_SESSION['api_new_token'] = ['id' => $id, 'name' => $f['name'], 'token' => $token];
        redirect('/settings/api');
    }

    public static function edit(int $id): void
    {
        Auth::requireRole('admin');
        $k = DB::one('SELECT k.*, u.name AS created_by_name FROM api_keys k LEFT JOIN users u ON u.id = k.created_by WHERE k.id = ?', [$id]);
        if (!$k) {
            redirect('/settings/api');
        }
        View::render('settings/api_key', [
            'title' => 'API key · ' . $k['name'],
            'nav' => 'settings',
            'apiKey' => Keys::decode($k),
            'clients' => DB::all('SELECT id, name FROM clients WHERE is_archived = 0 ORDER BY name'),
            'requests' => DB::all('SELECT * FROM api_requests WHERE key_id = ? ORDER BY id DESC LIMIT 100', [$id]),
        ]);
    }

    public static function update(int $id): void
    {
        Auth::requireRole('admin');
        $k = DB::one('SELECT * FROM api_keys WHERE id = ?', [$id]);
        if (!$k) {
            redirect('/settings/api');
        }
        if (post('action') === 'revoke') {
            if (!$k['revoked_at']) {
                DB::run('UPDATE api_keys SET revoked_at = NOW() WHERE id = ?', [$id]);
                Audit::log('api.key_revoke', "{$k['name']} (#$id)");
            }
            flash('success', "Revoked {$k['name']}. Anything using it stops working now.");
            redirect('/settings/api');
        }
        if (post('action') === 'delete' && $k['revoked_at']) {
            DB::run('DELETE FROM api_keys WHERE id = ?', [$id]);
            Audit::log('api.key_delete', "{$k['name']} (#$id)");
            flash('success', "Deleted {$k['name']}. Its request history stays in the audit log.");
            redirect('/settings/api');
        }
        if ($k['revoked_at']) {
            flash('error', 'A revoked key can\'t be changed. Create a new one.');
            redirect("/settings/api/keys/$id");
        }
        if (post('expires') === 'keep') {
            $_POST['expires'] = $k['expires_at'] ? 'date' : 'never';
            $_POST['expires_on'] = $k['expires_at'] ? substr($k['expires_at'], 0, 10) : '';
        }
        [$f, $err] = self::fields();
        if ($err) {
            flash('error', $err);
            redirect("/settings/api/keys/$id");
        }
        DB::run('UPDATE api_keys SET name = ?, scopes = ?, client_ids = ?, expires_at = ?, rate_limit = ?, notes = ? WHERE id = ?', [
            $f['name'], json_encode($f['scopes']), $f['clients'] === null ? null : json_encode($f['clients']), $f['expires_at'], $f['rate_limit'], $f['notes'], $id,
        ]);
        Audit::log('api.key_update', "{$f['name']} (#$id): " . implode(' ', $f['scopes']) . ($f['clients'] ? '; clients ' . implode(',', $f['clients']) : '; all clients')
            . ($f['expires_at'] ? '; expires ' . substr($f['expires_at'], 0, 10) : '; no expiry') . "; {$f['rate_limit']}/min");
        flash('success', "Saved {$f['name']}. Changes apply to its next request.");
        redirect("/settings/api/keys/$id");
    }

    /** Human-readable reference generated from the OpenAPI spec. */
    public static function docs(): void
    {
        Auth::requireRole('admin');
        View::render('settings/api_docs', [
            'title' => 'API reference',
            'nav' => 'settings',
            'spec' => \Align\Api\Spec::build(),
            'routes' => \Align\Api\Routes::all(),
            'baseUrl' => rtrim((string) \Align\Config::get('base_url', ''), '/') . '/api/v1',
            'enabled' => Keys::enabled(),
        ]);
    }

    /** Downloads the OpenAPI spec (works even while the API is off, so it can be imported first). */
    public static function openapi(): void
    {
        Auth::requireRole('admin');
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/', '-', strtolower(\Align\Branding::name())) . '-openapi.json"');
        echo json_encode(\Align\Api\Spec::build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
