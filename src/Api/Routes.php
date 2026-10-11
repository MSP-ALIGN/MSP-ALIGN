<?php
declare(strict_types=1);

namespace Align\Api;

use Align\Api\Resources\Alignment;
use Align\Api\Resources\Changes;
use Align\Api\Resources\Backups;
use Align\Api\Resources\Budget;
use Align\Api\Resources\Clients;
use Align\Api\Resources\Compliance;
use Align\Api\Resources\Devices;
use Align\Api\Resources\Health;
use Align\Api\Resources\Licenses;
use Align\Api\Resources\Meetings;
use Align\Api\Resources\Projects;
use Align\Api\Resources\ServiceLevels;
use Align\Api\Resources\Vendors;

/**
 * Every v1 endpoint, with what the OpenAPI spec and the docs page need. Paths are relative to /api/v1.
 * {id} is a number; {name:str} is any path segment (URL-encode it).
 *
 * Security: 'scope' is the permission Kernel checks before the handler runs; every route except GET / (which
 * only describes the key itself) has one, and a write route always needs the area's :write scope. Client limits
 * are not declared here: every handler that takes a client, or a record belonging to one, must check it
 * (Clients::load, Context::requireClient or Context::clientSql). Handler parameter names must match the
 * placeholders (api_e2e checks).
 */
final class Routes
{
    private static ?array $all = null;

    /** Every route (built once per request). */
    public static function all(): array
    {
        return self::$all ??= self::define();
    }

    /** One route with defaults for the optional spec fields ($o: query, body, creating, list, returns, status, description). */
    private static function r(string $method, string $path, ?string $scope, callable $handler, string $tag, string $summary, array $o = []): array
    {
        return ['method' => $method, 'path' => $path, 'scope' => $scope, 'handler' => $handler, 'tag' => $tag, 'summary' => $summary] + $o + [
            'query' => [], 'body' => null, 'creating' => false, 'list' => false, 'returns' => null, 'status' => 200, 'description' => '',
        ];
    }

    /** The route table. Body rules are closures so the spec reads the same rules the handlers enforce. */
    private static function define(): array
    {
        $page = ['page' => ['int', 'Page number (default 1).'], 'per_page' => ['int', 'Items per page, 1-200 (default 50).']];
        $since = ['updated_since' => ['string', 'Only items changed since this date/time (ISO 8601). Use it to sync incrementally.']];
        $client = ['client_id' => ['int', 'Only this client.']];
        $r = fn(...$a) => self::r(...$a);
        return [
            $r('GET', '/', null, [self::class, 'me'], 'Meta', 'About this key', ['description' => 'The key\'s name, scopes, client limit, expiry and rate limit. A cheap way to test a key.', 'returns' => 'Key']),

            $r('GET', '/clients', 'clients:read', [Clients::class, 'index'], 'Clients', 'List clients', ['list' => true, 'returns' => 'Client',
                'query' => ['search' => ['string', 'Name contains.'], 'include_excluded' => ['bool', 'Include clients taken out of planning.']] + $since + $page]),
            $r('GET', '/clients/{id}', 'clients:read', [Clients::class, 'show'], 'Clients', 'Get a client with its health summary', ['returns' => 'ClientDetail',
                'description' => 'The summary has a section for each area the key can read: devices, projects, next meeting, backups, compliance and service levels.']),

            $r('GET', '/contacts', 'contacts:read', [Clients::class, 'contacts'], 'Contacts', 'List contacts', ['list' => true, 'returns' => 'Contact',
                'query' => $client + ['role' => ['string', 'primary, billing, technical, important, decision_maker or meeting_invitee.'], 'search' => ['string', 'Name or email contains.'],
                    'include_archived' => ['bool', 'Include archived contacts.']] + $since + $page]),
            $r('GET', '/clients/{id}/contacts', 'contacts:read', [Clients::class, 'clientContacts'], 'Contacts', 'List a client\'s contacts', ['list' => true, 'returns' => 'Contact',
                'query' => ['role' => ['string', 'Filter by role.'], 'search' => ['string', 'Name or email contains.']] + $page]),
            $r('GET', '/contacts/{id}', 'contacts:read', [Clients::class, 'contact'], 'Contacts', 'Get a contact', ['returns' => 'Contact']),

            $r('GET', '/devices', 'devices:read', [Devices::class, 'index'], 'Devices', 'List devices with lifecycle status', ['list' => true, 'returns' => 'Device',
                'query' => $client + ['status' => ['string', 'Lifecycle status: replace, os_eos, plan, deferred, os_soon, warranty_expired, warranty_soon, ok, excluded, virtual.'],
                    'attention' => ['bool', 'Only devices that need attention (true) or don\'t (false).'], 'type' => ['string', 'Device type, e.g. Server, Laptop.'],
                    'class' => ['string', 'Category: desktop, laptop, server, network, printer, storage, power, other.'], 'virtual' => ['bool', 'Only virtual (true) or physical (false).'],
                    'search' => ['string', 'Name, serial, model or last user contains.']] + $since + $page]),
            $r('GET', '/devices/{id}', 'devices:read', [Devices::class, 'show'], 'Devices', 'Get a device', ['returns' => 'Device']),
            $r('PATCH', '/devices/{id}', 'devices:write', [Devices::class, 'update'], 'Devices', 'Change a device\'s lifecycle details', ['returns' => 'Device', 'body' => [Devices::class, 'rules'],
                'description' => 'Same as editing the device page: dates, lifespan and cost override the synced values and the policy defaults; the change goes to the PSA when two-way sync is on. Send null to clear an override.']),

            $r('GET', '/projects', 'projects:read', [Projects::class, 'index'], 'Projects', 'List projects', ['list' => true, 'returns' => 'Project',
                'query' => $client + ['status' => ['string', 'proposed, approved, scheduled, done or declined.'], 'category' => ['string', 'Project category.'],
                    'quarter' => ['string', 'Planned in this quarter (2027-Q1 or any date in it).']] + $since + $page]),
            $r('GET', '/projects/{id}', 'projects:read', [Projects::class, 'show'], 'Projects', 'Get a project', ['returns' => 'Project']),
            $r('POST', '/projects', 'projects:write', [Projects::class, 'create'], 'Projects', 'Create a project', ['returns' => 'Project', 'status' => 201, 'creating' => true,
                'body' => fn() => Projects::rules(true)]),
            $r('PATCH', '/projects/{id}', 'projects:write', [Projects::class, 'update'], 'Projects', 'Change a project', ['returns' => 'Project', 'body' => fn() => Projects::rules()]),
            $r('DELETE', '/projects/{id}', 'projects:write', [Projects::class, 'delete'], 'Projects', 'Delete a project', ['status' => 204]),

            $r('GET', '/clients/{id}/budget', 'budget:read', [Budget::class, 'summary'], 'Budget', 'Get a client\'s three-year technology budget', ['returns' => 'BudgetSummary',
                'query' => ['year' => ['int', 'Which plan year the selected_year block shows: 0 (default), 1 or 2.']],
                'description' => 'Everything the budget page shows: totals per year and quarter by category, recurring monthly run rate, each line (licensing, hardware, projects, managed services and your budget lines) and upcoming contract dates.']),
            $r('GET', '/budget-lines', 'budget:read', [Budget::class, 'lines'], 'Budget', 'List budget lines', ['list' => true, 'returns' => 'BudgetLine',
                'query' => $client + ['category' => ['string', 'Budget category.']] + $since + $page]),
            $r('GET', '/budget-lines/{id}', 'budget:read', [Budget::class, 'line'], 'Budget', 'Get a budget line', ['returns' => 'BudgetLine']),
            $r('POST', '/budget-lines', 'budget:write', [Budget::class, 'create'], 'Budget', 'Create a budget line', ['returns' => 'BudgetLine', 'status' => 201, 'creating' => true,
                'body' => fn() => Budget::rules(true)]),
            $r('PATCH', '/budget-lines/{id}', 'budget:write', [Budget::class, 'update'], 'Budget', 'Change a budget line', ['returns' => 'BudgetLine', 'body' => fn() => Budget::rules()]),
            $r('DELETE', '/budget-lines/{id}', 'budget:write', [Budget::class, 'delete'], 'Budget', 'Delete a budget line', ['status' => 204]),

            $r('GET', '/licenses', 'licenses:read', [Licenses::class, 'index'], 'Licensing', 'List licenses', ['list' => true, 'returns' => 'License',
                'query' => $client + ['category' => ['string', 'License category.'], 'unpriced' => ['bool', 'Only licenses without a price.'],
                    'renewing_within_days' => ['int', 'Expiry, contract end or renegotiate-by date within this many days.'], 'include_retired' => ['bool', 'Include retired licenses.']] + $since + $page]),
            $r('GET', '/licenses/{id}', 'licenses:read', [Licenses::class, 'show'], 'Licensing', 'Get a license', ['returns' => 'License']),
            $r('POST', '/licenses', 'licenses:write', [Licenses::class, 'create'], 'Licensing', 'Add a license', ['returns' => 'License', 'status' => 201, 'creating' => true,
                'body' => fn() => Licenses::rules(true), 'description' => 'For licenses you track only in Align. Licenses from the PSA arrive with the sync.']),
            $r('PATCH', '/licenses/{id}', 'licenses:write', [Licenses::class, 'update'], 'Licensing', 'Change a license', ['returns' => 'License', 'body' => fn() => Licenses::rules(),
                'description' => 'For licenses synced from the PSA, name, version, type, seats, vendor and dates are managed there and refused here.']),
            $r('DELETE', '/licenses/{id}', 'licenses:write', [Licenses::class, 'delete'], 'Licensing', 'Delete a license you added', ['status' => 204]),

            // 2.10.0 vendors, read-only
            $r('GET', '/vendors', 'vendors:read', [Vendors::class, 'index'], 'Vendors', 'List client vendors', ['list' => true, 'returns' => 'Vendor',
                'query' => $client + ['category' => ['string', 'Vendor category (its own, else its template\'s).'], 'include_retired' => ['bool', 'Include retired vendors.']] + $since + $page,
                'description' => 'Each client\'s vendors as its Vendors page shows them: a field left blank on the vendor takes its template\'s value (from_template lists which).']),
            $r('GET', '/vendors/{id}', 'vendors:read', [Vendors::class, 'show'], 'Vendors', 'Get a client vendor', ['returns' => 'Vendor']),
            $r('GET', '/vendor-templates', 'vendors:read', [Vendors::class, 'templates'], 'Vendors', 'List vendor templates', ['returns' => 'VendorTemplate',
                'description' => 'The shared vendor details entered once, with how many clients use each. Not paginated.']),

            $r('GET', '/meetings', 'meetings:read', [Meetings::class, 'index'], 'Meetings', 'List meetings', ['list' => true, 'returns' => 'Meeting',
                'query' => $client + ['from' => ['string', 'Starting on or after (date or date-time).'], 'to' => ['string', 'Starting on or before.'],
                    'status' => ['string', 'scheduled, completed or cancelled.'], 'type' => ['string', 'Meeting type.']] + $since + $page]),
            $r('GET', '/meetings/{id}', 'meetings:read', [Meetings::class, 'show'], 'Meetings', 'Get a meeting', ['returns' => 'Meeting']),
            $r('POST', '/meetings', 'meetings:write', [Meetings::class, 'create'], 'Meetings', 'Schedule a meeting', ['returns' => 'Meeting', 'status' => 201, 'creating' => true,
                'body' => fn() => Meetings::rules(true)]),
            $r('PATCH', '/meetings/{id}', 'meetings:write', [Meetings::class, 'update'], 'Meetings', 'Change, complete or cancel a meeting', ['returns' => 'Meeting', 'body' => fn() => Meetings::rules()]),
            $r('DELETE', '/meetings/{id}', 'meetings:write', [Meetings::class, 'delete'], 'Meetings', 'Delete a meeting', ['status' => 204,
                'description' => 'Attendees get a cancellation first if invitations had gone out.']),

            $r('GET', '/compliance/frameworks', 'compliance:read', [Compliance::class, 'frameworks'], 'Compliance', 'List frameworks', ['list' => true, 'returns' => 'Framework', 'query' => $page]),
            $r('GET', '/clients/{id}/compliance', 'compliance:read', [Compliance::class, 'client'], 'Compliance', 'List a client\'s frameworks with scores', ['list' => true, 'returns' => 'Assessment', 'query' => $page]),
            $r('POST', '/clients/{id}/compliance', 'compliance:write', [Compliance::class, 'assign'], 'Compliance', 'Assign a framework to a client', ['returns' => 'Assessment', 'status' => 201, 'creating' => true,
                'body' => ['framework_id' => ['int', ['required' => true, 'desc' => 'Framework to assign.']], 'next_review' => ['date', ['desc' => 'Next review (default one year).']]]]),
            $r('PATCH', '/clients/{id}/compliance/{framework}', 'compliance:write', [Compliance::class, 'review'], 'Compliance', 'Set review dates', ['returns' => 'Assessment',
                'body' => ['next_review' => ['date'], 'last_reviewed' => ['date']]]),
            $r('DELETE', '/clients/{id}/compliance/{framework}', 'compliance:write', [Compliance::class, 'unassign'], 'Compliance', 'Remove a framework from a client', ['status' => 204,
                'description' => 'Answers are kept and come back if the framework is assigned again.']),
            $r('GET', '/clients/{id}/compliance/{framework}/controls', 'compliance:read', [Compliance::class, 'controls'], 'Compliance', 'List controls with the client\'s status', ['list' => true, 'returns' => 'Control',
                'query' => ['status' => ['string', 'not_assessed, met, partial, not_met or na.']] + $page]),
            $r('PATCH', '/clients/{id}/compliance/{framework}/controls/{control}', 'compliance:write', [Compliance::class, 'updateControl'], 'Compliance', 'Update one control', ['returns' => 'Control',
                'body' => [Compliance::class, 'controlRules']]),
            $r('PATCH', '/clients/{id}/compliance/{framework}/controls', 'compliance:write', [Compliance::class, 'updateControls'], 'Compliance', 'Update many controls', ['returns' => 'BulkResult',
                'body' => ['controls' => ['array', ['required' => true, 'desc' => 'Up to 500 items: {"id": <control id>, "status": "met", "notes": "..."}. All or nothing.']]]]),

            // 2.4.0 What changed since the last business review
            $r('GET', '/clients/{id}/changes', 'clients:read', [Changes::class, 'client'], 'Clients', 'What changed since the last review', ['returns' => 'Changes',
                'query' => ['since' => ['string', 'm<meeting id> (one of the client\'s completed business reviews) or YYYY-MM-DD; default: the newest completed review.']],
                'description' => 'Projects finished, devices replaced, added and removed, warranties, end of life and OS support that ran out, alignment and compliance changes, licenses and tickets since the starting point. Each part is included only when the key can read its area (tickets need service:read). Then-figures come from the snapshot saved when the review was completed (since 2.3.0); without one they are null.']),

            // 2.5.0 Client health score
            $r('GET', '/clients/{id}/health', 'health:read', [Health::class, 'client'], 'Health', 'Get a client\'s health score', ['returns' => 'Health',
                'description' => 'One score from 0 to 100 made from lifecycle, backups, compliance, service levels and alignment, each scored 0 to 100 and weighted as set in Settings → Planning & lifecycle. An area with no data is left out. Each area\'s details (what pulls it down) are included only when the key can read that area; otherwise details is null. Worked out now, and stored as today\'s entry in the history.']),
            $r('GET', '/clients/{id}/health/history', 'health:read', [Health::class, 'history'], 'Health', 'Get a client\'s health history', ['list' => true, 'returns' => 'HealthDay',
                'query' => ['days' => ['int', 'How many days back, 1-1100 (default 90).']] + $page,
                'description' => 'One entry per day that was recorded (once a day, and whenever the client is opened), newest first. Scores are worked out with the weights in use now.']),

            // 2.3.0 Alignment reviews
            $r('GET', '/alignment/standards', 'alignment:read', [Alignment::class, 'standards'], 'Alignment', 'List the standards', ['list' => true, 'returns' => 'Standard',
                'query' => ['include_inactive' => ['bool', 'Also standards switched off (kept for old reviews).']] + $page, 'description' => 'The library alignment reviews measure clients against. Admins edit it in the web app (Settings → Standards).']),
            $r('GET', '/clients/{id}/alignment', 'alignment:read', [Alignment::class, 'client'], 'Alignment', 'Get a client\'s alignment', ['returns' => 'Alignment',
                'description' => 'The latest finished review\'s score and band, the change since the review before, the gaps (most important first, each with its roadmap project if one was made and the compliance controls it helps with) and the open draft.']),
            $r('GET', '/clients/{id}/alignment/reviews', 'alignment:read', [Alignment::class, 'reviews'], 'Alignment', 'List a client\'s reviews', ['list' => true, 'returns' => 'AlignmentReview', 'query' => $page]),
            $r('GET', '/clients/{id}/alignment/reviews/{review}', 'alignment:read', [Alignment::class, 'show'], 'Alignment', 'Get a review with its answers', ['returns' => 'AlignmentReview']),
            $r('POST', '/clients/{id}/alignment/reviews', 'alignment:write', [Alignment::class, 'start'], 'Alignment', 'Start a review', ['returns' => 'AlignmentReview', 'status' => 201, 'creating' => true,
                'description' => 'Starts from the last finished review\'s answers. If a draft is already open it is returned instead (200). Send an empty body.']),
            $r('PATCH', '/clients/{id}/alignment/reviews/{review}/answers', 'alignment:write', [Alignment::class, 'answers'], 'Alignment', 'Answer standards in a draft', ['returns' => 'AlignmentAnswersResult',
                'body' => ['answers' => ['array', ['required' => true, 'desc' => 'Up to 500 items: {"standard_id": 12, "answer": "aligned", "note": "..."}. answer is aligned, misaligned or na; a field left out stays as it is. All or nothing; 409 when the review is finished.']]]]),
            $r('POST', '/clients/{id}/alignment/reviews/{review}/finish', 'alignment:write', [Alignment::class, 'finish'], 'Alignment', 'Finish a draft', ['returns' => 'AlignmentReview',
                'description' => 'Stores the score (weighted by priority; N/A and unanswered standards left out). 409 when it is already finished.']),
            $r('DELETE', '/clients/{id}/alignment/reviews/{review}', 'alignment:write', [Alignment::class, 'discard'], 'Alignment', 'Discard a draft', ['status' => 204,
                'description' => 'Finished reviews are kept: 409.']),

            $r('GET', '/backups', 'backups:read', [Backups::class, 'index'], 'Backups', 'Backup health for every client', ['list' => true, 'returns' => 'BackupSummary', 'query' => $page]),
            $r('GET', '/clients/{id}/backups', 'backups:read', [Backups::class, 'client'], 'Backups', 'Get a client\'s backups', ['returns' => 'ClientBackups',
                'description' => 'Stats, 30-day history, jobs (with the backup product\'s messages), protected machines, Microsoft 365 and servers with no backup. Includes machines backed up on your own server.']),
            $r('GET', '/clients/{id}/backup-exemptions', 'backups:read', [Backups::class, 'exemptions'], 'Backups', 'List items marked "backup not required"', ['list' => true, 'returns' => 'Exemption', 'query' => $page]),
            $r('POST', '/clients/{id}/backup-exemptions', 'backups:write', [Backups::class, 'exempt'], 'Backups', 'Mark an item "backup not required"', ['returns' => 'Exemption', 'status' => 201, 'creating' => true,
                'body' => ['kind' => ['string', ['required' => true, 'enum' => ['device', 'workload', 'm365']]], 'device_id' => ['int', ['desc' => 'For kind=device.']], 'item_uid' => ['string', ['desc' => 'For kind=workload or m365.']], 'reason' => ['string', ['required' => true, 'max' => 255]]]]),
            $r('DELETE', '/clients/{id}/backup-exemptions/{exemption}', 'backups:write', [Backups::class, 'unexempt'], 'Backups', 'Remove a "not required" mark', ['status' => 204]),
            $r('GET', '/backups/hosted', 'backups:read', [Backups::class, 'hosted'], 'Backups', 'Machines and jobs on your own backup server', ['list' => true, 'returns' => 'HostedMachine',
                'query' => ['show' => ['string', 'unmatched, sorted, ours or all (default).']] + $page, 'description' => 'meta.jobs lists the jobs. Needs a key for all clients.']),
            $r('PUT', '/backups/hosted/machines/{uid:str}', 'backups:write', [Backups::class, 'assignMachine'], 'Backups', 'Assign a hosted machine', ['returns' => 'HostedAssignment',
                'body' => ['assign' => ['string', ['required' => true, 'desc' => 'A client id (number), "ours" or "auto".']]], 'description' => 'uid from GET /backups/hosted, URL-encoded (vm:abc -> vm%3Aabc). Needs a key for all clients.']),
            $r('PUT', '/backups/hosted/jobs/{uid:str}', 'backups:write', [Backups::class, 'assignJob'], 'Backups', 'Assign a hosted job (and its machines)', ['returns' => 'HostedAssignment',
                'body' => ['assign' => ['string', ['required' => true, 'desc' => 'A client id (number), "ours" or "auto".']]], 'description' => 'Needs a key for all clients.']),

            $r('GET', '/clients/{id}/service-levels', 'service:read', [ServiceLevels::class, 'client'], 'Service levels', 'Get a client\'s SLA results', ['returns' => 'ServiceLevels',
                'query' => ['period' => ['string', 'Days (30, 90, 180, 365) or a named period as on the Service levels page. Default 90.'], 'missed_limit' => ['int', 'How many missed tickets to list (max 100, default 25).']]]),
        ];
    }

    /** GET /api/v1: what this key is. Needs only a valid key; shows nothing about other keys or the creator. */
    public static function me(): array
    {
        $k = Context::$key;
        return Out::one([
            'name' => $k['name'],
            'prefix' => 'msa_' . $k['prefix'],
            'scopes' => $k['scope_list'],
            'client_ids' => $k['client_list'],
            'rate_limit_per_minute' => (int) $k['rate_limit'],
            'expires_at' => Out::ts($k['expires_at']),
            'api_version' => Kernel::VERSION,
            'app_version' => APP_VERSION,
            'openapi' => '/api/v1/openapi.json',
        ]);
    }
}
