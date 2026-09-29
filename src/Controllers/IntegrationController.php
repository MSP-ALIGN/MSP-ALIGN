<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Integrations\Connector;
use Align\Integrations\Registry;
use Align\Settings;
use Align\View;

/** Integrations: one page listing every connected service, and a page per integration (from Registry). */
final class IntegrationController
{
    public static function index(): void
    {
        Auth::requireRole('admin');
        $groups = [];
        foreach (Registry::all() as $c) {
            $groups[$c->category()][] = $c;
        }
        uksort($groups, fn($a, $b) => array_search($a, Registry::CATEGORIES, true) <=> array_search($b, Registry::CATEGORIES, true));
        $last = \Align\DB::one('SELECT id, status, started_at, finished_at FROM sync_runs ORDER BY id DESC LIMIT 1');
        View::render('integrations/index', [
            'title' => 'Integrations',
            'nav' => 'integrations',
            'groups' => $groups,
            'lastRun' => $last,
            'unmapped' => \Align\Providers\Providers::anyRmm() ? (int) \Align\DB::value('SELECT COUNT(*) FROM clients c WHERE c.is_archived = 0 AND c.planning_excluded = 0 AND NOT ' . \Align\Providers\ClientLinks::rmmLinkedSql()) : 0,
        ]);
    }

    private static function connector(string $key): Connector
    {
        $c = Registry::get($key);
        if (!$c) {
            http_response_code(404);
            View::render('error', ['title' => 'Page not found', 'message' => 'There is no integration called that.']);
            exit;
        }
        if ($c->url() !== '/integrations/' . $key) {
            redirect($c->url());
        }
        return $c;
    }

    public static function show(string $key): void
    {
        Auth::requireRole('admin');
        $c = self::connector($key);
        $values = [];
        foreach ($c->fields() as $f) {
            $values[$f['name']] = $f['type'] === 'secret' ? Settings::hasSecret($f['name']) : Settings::get($f['name'], isset($f['default']) ? (string) $f['default'] : null);
        }
        View::render('integrations/show', [
            'title' => $c->name(),
            'nav' => 'integrations',
            'c' => $c,
            'values' => $values,
            'status' => $c->status(),
        ]);
    }

    public static function save(string $key): void
    {
        Auth::requireRole('admin');
        $c = self::connector($key);
        try {
            $changed = $c->save($_POST);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage() . ' Nothing was saved.');
            redirect(setup_return('/integrations/' . $key));
        }
        // The first PSA set up becomes the install's PSA (the source of truth for clients)
        if ($c instanceof \Align\Integrations\PsaConnector && $c->configured() && (string) \Align\Settings::get('psa_provider', '') === '') {
            \Align\Settings::set('psa_provider', $c->key());
            $changed[] = 'psa_provider';
        }
        if ($changed) {
            Audit::log('integration.save', $c->name() . ': ' . implode(', ', $changed));
            $secrets = array_filter($changed, fn($x) => in_array(preg_replace('/ \(cleared\)$/', '', $x), $c->secretNames(), true));
            if ($secrets) {
                \Align\Mail\Notify::security('Integration keys changed', $c->name() . ': ' . implode(', ', $secrets) . ' by ' . (Auth::user()['email'] ?? ''));
            }
        }
        flash('success', $changed ? $c->name() . ' saved.' . ($c->hasTest() ? ' Press Test to check the connection.' : '') : 'No changes.');
        redirect(setup_return('/integrations/' . $key));
    }

    public static function test(string $key): void
    {
        Auth::requireRole('admin');
        $c = self::connector($key);
        try {
            flash('success', $c->name() . ': ' . $c->test());
            Audit::log('integration.test', $c->name() . ': OK');
        } catch (\Throwable $e) {
            flash('error', $c->name() . ' test failed: ' . $e->getMessage());
            Audit::log('integration.test', $c->name() . ': ' . mb_substr($e->getMessage(), 0, 300));
        }
        redirect(setup_return('/integrations/' . $key));
    }
}
