<?php
declare(strict_types=1);

namespace Align\Api\Resources;

use Align\Api\ApiError;
use Align\Api\Context;
use Align\Api\Input;
use Align\Api\Out;
use Align\DB;
use Align\Lifecycle\Lifecycle;

/** Devices with their computed lifecycle; writes change Align's lifecycle overrides (same as the device page). */
final class Devices
{
    public static function rules(): array
    {
        return [
            'purchase_date' => ['date', ['desc' => 'Purchase / in-service date. Overrides what the RMM or PSA says. null = use the synced date.']],
            'warranty_end' => ['date', ['desc' => 'Warranty end. null = use the warranty lookup or PSA date.']],
            'lifespan_years' => ['int', ['min' => 1, 'max' => 29, 'desc' => 'Lifespan for this device. null = the policy default for its type.']],
            'replacement_cost' => ['number', ['min' => 0, 'max' => 10000000, 'desc' => 'Replacement cost. null = the policy default for its type.']],
            'replace_quarter' => ['quarter', ['desc' => 'Plan the replacement in this quarter instead of at end of life (2027-Q2 or any date in it). null = follow end of life.']],
            'replace_note' => ['string', ['max' => 255, 'desc' => 'Why the replacement was moved (kept only with replace_quarter).']],
            'excluded' => ['bool', ['desc' => 'Leave the device out of planning, budgets and reports.']],
            'notes' => ['string', ['max' => 5000, 'desc' => 'Align notes.']],
            'device_type' => ['string', ['enum' => array_keys(Lifecycle::TYPES), 'desc' => 'Device type (sets its lifecycle category).']],
        ];
    }

    public static function index(): array
    {
        $clientId = Input::queryInt('client_id');
        if ($clientId !== null) {
            Clients::load($clientId);
        }
        $status = Input::queryStr('status', array_keys(Lifecycle::STATUS));
        $type = Input::queryStr('type', array_keys(Lifecycle::TYPES));
        $class = Input::queryStr('class', [...Lifecycle::HARDWARE_CLASSES]);
        $virtual = Input::queryBool('virtual');
        $attention = Input::queryBool('attention');
        $q = strtolower((string) Input::queryStr('search'));
        $since = Input::querySince();
        $allowed = Context::clients();
        $archived = array_flip(array_map('intval', array_column(DB::all('SELECT id FROM clients WHERE is_archived = 1'), 'id')));
        $rows = array_filter((new Lifecycle())->devices($clientId), function ($d) use ($allowed, $archived, $status, $type, $class, $virtual, $attention, $q, $since) {
            return ($allowed === null || in_array((int) $d['client_id'], $allowed, true))
                && $d['client_id'] !== null && !isset($archived[(int) $d['client_id']])
                && ($status === null || $d['status'] === $status)
                && ($type === null || $d['type'] === $type)
                && ($class === null || $d['device_class'] === $class)
                && ($virtual === null || (bool) $d['is_virtual'] === $virtual)
                && ($attention === null || in_array($d['status_tone'], ['bad', 'warn'], true) === $attention)
                && ($q === '' || str_contains(strtolower($d['name'] . ' ' . $d['serial'] . ' ' . $d['model'] . ' ' . $d['last_user']), $q))
                && ($since === null || max((string) $d['updated_at'], (string) $d['synced_at'], (string) $d['created_at']) >= $since);
        });
        usort($rows, fn($a, $b) => [(string) $a['client_name'], strnatcasecmp($a['name'], $b['name'])] <=> [(string) $b['client_name'], 0]);
        return Out::slice(array_map([self::class, 'shape'], $rows));
    }

    public static function show(int $id): array
    {
        return Out::one(self::shape(self::load($id)));
    }

    private static function load(int $id): array
    {
        $d = (new Lifecycle())->devices(null, false, $id)[0] ?? null;
        if (!$d || $d['client_id'] === null || !Context::allowsClient((int) $d['client_id'])
            || DB::value('SELECT is_archived FROM clients WHERE id = ?', [(int) $d['client_id']])) {
            throw ApiError::notFound('Device');
        }
        return $d;
    }

    public static function update(int $id): array
    {
        $d = self::load($id);
        $in = Input::clean(Context::$body, self::rules());
        if (!$in) {
            throw ApiError::invalid([], 'Send at least one field to change.');
        }
        $before = \Align\Sync\PsaAssetSync::snapshot($id);
        DB::transaction(function () use ($d, $id, $in) {
            $o = DB::one('SELECT * FROM device_overrides WHERE device_id = ?', [$id]) ?: ['device_id' => $id];
            $map = ['purchase_date' => 'purchase_date', 'warranty_end' => 'warranty_end', 'lifespan_years' => 'lifespan_years',
                'replacement_cost' => 'replacement_cost', 'replace_quarter' => 'replace_on', 'replace_note' => 'replace_note', 'notes' => 'notes'];
            foreach ($map as $k => $col) {
                if (array_key_exists($k, $in)) {
                    $o[$col] = $in[$k];
                }
            }
            if (array_key_exists('excluded', $in)) {
                $o['excluded'] = $in['excluded'] ? 1 : 0;
            }
            if (empty($o['replace_on'])) {
                $o['replace_note'] = null; // a note only makes sense with a planned quarter
            }
            $o['updated_by'] = null;
            unset($o['updated_at']);
            DB::upsert('device_overrides', $o, ['device_id']);
            if (array_key_exists('device_type', $in) && $in['device_type'] !== null) {
                \Align\Controllers\DeviceController::setType(['id' => $id, 'device_type' => $d['device_type']] + $d, $in['device_type']);
            }
        });
        \Align\Sync\PsaAssetSync::recordAlignEdit($id, $before);
        \Align\Audit::log('device.update', $d['name'] . ': ' . implode(', ', array_keys($in)));
        $push = \Align\Sync\PsaAssetSync::pushDevice($id, null);
        $out = self::shape(self::load($id));
        // Only the fixed wording goes back to the key: an error's own text can name internal hosts or database details (1.45)
        $msg = match ($push['status']) {
            'error' => psa_name() . ' wasn\'t updated; the next sync tries again. Details are on the device page.',
            'queued' => psa_name() . ' will be updated by the next sync.',
            default => $push['message'] ?: null,
        };
        $sync = ['status' => $push['status'], 'message' => $msg];
        return Out::one($out + ['psa_sync' => $sync, 'itflow_sync' => $sync]); // itflow_sync: deprecated alias
    }

    public static function shape(array $d): array
    {
        $plan = $d['o_replace'] ? \Align\Roadmap\Plan::quarterFor($d['o_replace']) : null;
        return [
            'id' => (int) $d['id'],
            'client_id' => Out::int($d['client_id']),
            'client_name' => $d['client_name'],
            'name' => $d['name'],
            'type' => $d['type'],
            'category' => $d['device_class'],
            'is_virtual' => (bool) $d['is_virtual'],
            'source' => Out::source($d['source'], $d['rmm_provider']),
            'rmm' => $d['rmm_provider'] ? ['provider' => $d['rmm_provider'], 'device_id' => $d['rmm_device_id'], 'organization_id' => $d['rmm_org_id']] : null,
            'manufacturer' => $d['manufacturer'],
            'model' => $d['model'],
            'serial' => $d['serial'],
            'ip_address' => $d['ip_address'],
            'location' => $d['location'],
            'last_user' => $d['last_user'],
            'last_seen' => Out::ts($d['last_contact']),
            'os' => $d['os_name'] ? ['name' => $d['os_name'], 'build' => $d['os_build'], 'support_ends' => $d['os_rule']['eos_date'] ?? null] : null,
            'lifecycle' => [
                'status' => $d['status'],
                'status_label' => $d['status_label'],
                'health' => $d['status_tone'],
                'flags' => array_values($d['flags']),
                'in_service_date' => $d['start_date'],
                'in_service_source' => $d['start_source'],
                'in_service_estimated' => (bool) $d['start_estimated'],
                'age_years' => $d['age_years'],
                'lifespan_years' => Out::int($d['lifespan']),
                'end_of_life' => $d['eol_date'],
                'replace_by' => $d['replace_by'],
                'planned_replacement' => $plan ? ['quarter' => $d['o_replace'], 'label' => $plan['label'], 'note' => $d['o_replace_note'], 'deferred' => (bool) $d['replace_deferred']] : null,
                'replacement_cost' => $d['is_hardware'] ? Out::num($d['replacement_cost']) : null,
                'project' => !empty($d['project']) ? ['id' => $d['project']['id'], 'title' => $d['project']['title'], 'status' => $d['project']['status'],
                    'target_quarter' => $d['project']['target_quarter'], 'psa_ticket_id' => $d['project']['psa_ticket_id']] : null,
                'excluded' => $d['status'] === 'excluded',
            ],
            'warranty' => ['end' => $d['warranty_end'], 'source' => $d['warranty_source']],
            'overrides' => [
                'purchase_date' => $d['o_purchase'], 'warranty_end' => $d['o_warranty'], 'lifespan_years' => Out::int($d['o_lifespan']),
                'replacement_cost' => Out::num($d['o_cost']), 'device_type' => $d['o_type'], 'notes' => $d['o_notes'],
            ],
            'psa_asset_id' => Out::extId($d['psa_asset_id']),
            'itflow_asset_id' => Out::numId($d['psa_asset_id']), // deprecated alias of psa_asset_id
            'ninja_device_id' => $d['rmm_provider'] === 'ninjaone' ? Out::int($d['rmm_device_id']) : null, // older field, kept for existing integrations
            'synced_at' => Out::ts($d['synced_at']),
            'url' => Out::url('/devices/' . (int) $d['id']),
        ];
    }
}
