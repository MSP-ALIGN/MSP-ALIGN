<?php
/**
 * Users and settings for the performance install (run by tests/perf/build.sh). Same sign-ins and 2FA
 * secrets as the end-to-end tests; integrations point at tests/perf/mock-scale.php.
 */
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use Align\DB;
use Align\Settings;

$M = getenv('PERF_MOCK') ?: 'http://127.0.0.1:8098';
$users = [
    ['admin@example.com', 'Alex Admin', 'admin', 'LongPassword123!', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXA'],
    ['tech@example.com', 'Terry Tech', 'tech', 'TechPassword123!', 'KRSXG5CTMVRXEZLUKRSXG5CTMVRXEZLU'],
    ['viewer@example.com', 'Vic Viewer', 'viewer', 'ViewerPassword123!', 'MFRGGZDFMZTWQ2LKMFRGGZDFMZTWQ2LK'],
];
// A realistic team: a few vCIOs who own clients
foreach (['Pat Owens', 'Robin Chen', 'Lee Marsh', 'Kris Dale', 'Jo Vance'] as $i => $name) {
    $users[] = ['vcio' . ($i + 1) . '@example.com', $name, $i < 2 ? 'admin' : 'tech', 'VcioPassword123!' . $i, null];
}
foreach ($users as [$email, $name, $role, $pw, $totp]) {
    DB::insert('users', ['email' => $email, 'name' => $name, 'role' => $role, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
        'totp_secret_enc' => $totp ? Align\Crypto::encrypt($totp) : null, 'totp_enabled' => $totp ? 1 : 0, 'is_active' => 1,
        'must_change_password' => 0, 'password_changed_at' => date('Y-m-d H:i:s')]);
}
$plain = [
    'company_email' => 'service@perfmsp.example', 'company_name' => 'Perf MSP', 'setup_state' => 'done', 'setup_seen' => '1',
    'dell_api_base' => $M, 'dell_client_id' => 'dell-id', 'lenovo_api_base' => $M,
    'itflow_url' => $M, 'ninja_instance' => $M, 'ninja_client_id' => 'ninja-id', 'veeam_url' => $M, 'veeam_hosting_companies' => '[]',
    'm365_auth' => 'secret', 'm365_client_id' => '11111111-2222-3333-4444-555555555555', 'm365_graph_base' => $M . '/graph/v1.0',
    'm365_login_base' => $M . '/login', 'm365_tenant' => 'examplemsp.onmicrosoft.com',
    'mail_from' => 'alerts@examplemsp.example', 'mail_from_name' => 'Perf MSP', 'mail_mode' => 'app', 'mail_provider' => 'microsoft',
    'mail_meeting_mode' => 'calendar', 'mail_meeting_organizer' => 'owner',
    'psa_provider' => 'itflow', 'psa_create_assets' => '1', 'psa_import_types' => 'network,printer,ups,storage', 'psa_sla_supported' => '1',
    'psa_sla_sync' => '1', 'psa_two_way' => '1', 'psa_writeback' => 'fill_empty', 'sla_target' => '90', 'stale_days' => '45', 'api_enabled' => '1',
    'client_requests' => '1', 'budget_msp_estimate' => '1', 'backup_stale_hours' => '48',
];
foreach ($plain as $k => $v) {
    Settings::set($k, $v);
}
foreach (['dell_client_secret' => 'dsecret', 'itflow_api_key' => 'itflow-key', 'lenovo_client_id' => 'lkey', 'm365_client_secret' => 'm365-secret',
    'ninja_client_secret' => 'ninja-secret', 'veeam_api_key' => 'veeam-key'] as $k => $v) {
    Settings::setSecret($k, $v);
}
echo "setup: users and settings\n";
