<?php
declare(strict_types=1);

namespace Align\Providers\Rmm;

/**
 * An RMM (remote monitoring and management) tool: the source of computers, servers and other monitored
 * devices with their hardware and OS details. An install can run several; each device belongs to the
 * RMM that reports it, and each RMM organization links to at most one client (see client_links).
 *
 * Neutral records (arrays; ids are strings, since some RMMs use non-numeric ids):
 *   organization  id, name, description
 *   device        id, org_id, display_name, system_name, node_class (the RMM's own device class),
 *                 device_type (an Align type from Lifecycle::TYPES), manufacturer, model, serial (raw),
 *                 chassis, is_virtual (bool), os_name, os_build, os_release_id,
 *                 last_contact, created_at (Y-m-d H:i:s), last_user, offline (bool)
 */
interface RmmProvider
{
    public const CAPABILITIES = [
        'last_user' => 'Last logged-in user',
        'last_contact' => 'Last check-in time',
        'device_links' => 'Links to devices in the RMM console',
    ];

    public function key(): string;

    public function name(): string;

    public function supports(string $capability): bool;

    /** Checks the connection; returns a short success message or throws. */
    public function test(): string;

    public function organizations(): array;

    public function devices(): array;

    /** Link to the device in the RMM's own console, or null. */
    public function deviceUrl(string $deviceId): ?string;
}
