<?php
declare(strict_types=1);

namespace Align\Integrations;

use Align\Providers\Psa\PsaProvider;
use Align\Settings;

/**
 * An integration that supplies the PSA data area. Subclasses give the connection fields and the
 * provider; the behavior settings shared by every PSA (two-way asset sync, asset import, SLAs,
 * warranty write-back) come from here and only show when the provider can do them.
 *
 * Security assumptions: as Connector (admin-only form, secrets encrypted). Settings that make Align write into the
 * PSA are offered only when the provider can do them and Staging doesn't block them; show.php asks before turning
 * them on.
 */
abstract class PsaConnector extends Connector
{
    /** The provider, ready to call. $interactive: a user waits, so fail fast (no retries). Throws when not set up. */
    abstract public function provider(bool $interactive = false): PsaProvider;

    /** What this PSA can do (keys of PsaProvider::CAPABILITIES). */
    abstract public function capabilities(): array;

    /** The connection fields (URL, keys). */
    abstract protected function connectionFields(): array;

    /** Group on the Integrations page. */
    public function category(): string
    {
        return 'PSA & documentation';
    }

    /** Data areas this connector supplies. */
    public function areas(): array
    {
        return ['psa'];
    }

    /** Whether this PSA can do $capability and Staging (a test server) doesn't block it. */
    public function can(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true) && !\Align\Staging::blocks($capability);
    }

    /** "Two-way" when two-way asset sync is possible and on, else "Read only". */
    public function direction(): string
    {
        return $this->can('assets.write') && \Align\Sync\PsaAssetSync::twoWay() ? 'Two-way' : 'Read only';
    }

    /** Sync steps whose results show as this integration's status. */
    public function syncSteps(): array
    {
        return [$this->name(), 'Managed-services', 'Write warranty'];
    }

    /** The connection fields plus the shared behavior settings this PSA can do. Final so every PSA offers the same. */
    final public function fields(): array
    {
        $n = $this->name();
        $f = $this->connectionFields();
        if ($this->can('assets.write')) {
            $contacts = $this->can('contacts.write');
            $f[] = ['name' => 'psa_two_way', 'label' => $contacts ? 'Two-way sync (devices and contacts)' : 'Two-way device sync', 'type' => 'switch', 'default' => '1',
                'help' => "Edits in Align go to $n straight away; $n edits come in every 2 minutes. If both sides change the same field, the newest edit wins and the other is kept in the device's sync history. "
                    . ($contacts ? "Contacts added, edited" . ($this->can('contacts.archive') ? ', archived or restored' : '') . " in Align are made in $n too (the Primary flag and location stay managed in $n). " : '')
                    . 'Off: Align only reads from ' . $n . '. Nothing is ever deleted by sync.'];
        }
        if ($this->can('assets.create')) {
            $f[] = ['name' => 'psa_create_assets', 'label' => "Create devices added in Align as $n assets (for clients linked to $n)", 'type' => 'switch', 'default' => '1'];
        }
        if ($this->can('assets')) {
            $f[] = ['name' => 'psa_import_types', 'label' => "Bring in $n assets that your RMM doesn't manage", 'type' => 'checkboxes', 'options' => \Align\Sync\PsaAssetSync::IMPORT_CATEGORIES,
                'help' => 'Assets already matched to an RMM or hand-added device (by serial or name) are skipped. Types Align doesn\'t recognize go to Unassigned hardware.'];
        }
        if ($this->can('sla')) {
            $f[] = ['name' => 'psa_sla_sync', 'label' => 'Service levels: bring in ticket SLA results', 'type' => 'switch', 'default' => '1',
                'help' => $this->slaHelp()];
            $f[] = ['name' => 'sla_target', 'label' => 'SLA goal (share of targets met)', 'type' => 'number', 'min' => 50, 'max' => 100, 'default' => '90', 'suffix' => '%',
                'help' => 'At or above the goal shows green, up to 10 points below shows amber, lower shows red.'];
        }
        if ($this->can('assets.write')) {
            $f[] = ['name' => 'psa_writeback', 'label' => "Write warranty dates back to $n assets", 'type' => 'select', 'default' => 'off',
                'options' => ['off' => 'Off (read only)', 'fill_empty' => 'Fill empty fields only', 'overwrite' => "Keep $n in sync (overwrite)"]];
        }
        return $f;
    }

    /** Help under the SLA switch (a provider can add version requirements). */
    protected function slaHelp(): string
    {
        return 'Align copies each ticket\'s number, subject, priority and SLA times and results, never the ticket body, and reports response and resolution SLA performance per client.';
    }

    /** Last 2-minute poll and SLA ticket counts (HTML; the poll result comes from the PSA and is escaped). */
    public function notes(): string
    {
        $n = $this->name();
        $out = '';
        if ($this->can('assets')) {
            $p = \Align\DB::one('SELECT * FROM psa_poll_state WHERE id = 1');
            $out = $p ? "Last 2-minute $n check: " . ($p['last_run'] ? e(rel_time($p['last_run'])) . ' · ' . e((string) $p['last_result']) : 'not run yet') : '';
        }
        if ($this->can('sla') && Settings::get('psa_sla_sync', '1') === '1') {
            $count = (int) \Align\DB::value('SELECT COUNT(*) FROM psa_tickets');
            $sup = \Align\Service\Sla::supported();
            $out .= ($out ? '<br>' : '') . 'Tickets for SLA reporting: ' . num($count)
                . (($l = \Align\Service\Sla::lastSync()) ? ' · last read ' . e(rel_time($l)) : ' · not read yet (runs with the hourly sync)')
                . ($sup === false ? ' · <span class="text-danger">' . e($this->noSlaMessage()) . '</span>' : '');
        }
        return $out;
    }

    /** How to get SLAs working, shown when the PSA's tickets have no SLA fields (trusted HTML). */
    public function slaSetupHint(): string
    {
        return 'Set up SLAs in ' . e($this->name()) . ', and they appear here after the next sync.';
    }

    /** Shown when the PSA returned tickets without SLA fields. */
    public function noSlaMessage(): string
    {
        return 'this ' . $this->name() . ' has no SLA fields';
    }

    /** Every PSA has a connection test. */
    public function hasTest(): bool
    {
        return true;
    }

    /** Runs the provider's own test with the saved settings (see Connector::test). */
    public function test(): string
    {
        return $this->provider()->test();
    }
}
