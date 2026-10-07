<?php
declare(strict_types=1);

namespace Align\Api;

/**
 * OpenAPI 3.1 description of v1, generated from Routes (paths, parameters, request bodies from the same
 * validation rules the API enforces) plus the response schemas below. Served at /api/v1/openapi.json and
 * rendered as the docs page under Settings -> API.
 *
 * Security: /api/v1/openapi.json needs no key while the API is on (rate limited per address), so everything here
 * must be safe to publish: routes, field names, scopes and the configured base_url only. No settings, client
 * data, versions of the app or anything from the database.
 */
final class Spec
{
    /** Response objects: name => [description, [field => [type, description]]]. Types: string, integer, number, boolean, object, array, date, date-time, T[] (array of schema T). */
    public const SCHEMAS = [
        'Key' => ['The API key making the request.', [
            'name' => ['string', 'Name given to the key.'], 'prefix' => ['string', 'Public part of the key.'], 'scopes' => ['string[]', 'Permissions, e.g. projects:write.'],
            'client_ids' => ['integer[]', 'Clients the key is limited to; null = all clients.'], 'rate_limit_per_minute' => ['integer', ''], 'expires_at' => ['date-time', 'null = never.'],
            'api_version' => ['integer', ''], 'app_version' => ['string', ''], 'openapi' => ['string', 'Path of the OpenAPI document.']]],
        'Client' => ['A client (details come from the PSA and are read-only).', [
            'id' => ['integer', ''], 'name' => ['string', ''], 'industry' => ['string', ''], 'source' => ['string', 'The PSA\'s key (e.g. itflow) or manual.'], 'psa_id' => ['id', 'The client\'s id in the PSA, always as text (e.g. "57" for ITFlow). Since 2.0; before, a number for ITFlow.'], 'itflow_client_id' => ['integer', 'Older name for psa_id, kept for existing integrations.'],
            'main_phone' => ['string', ''], 'website' => ['string', ''], 'address' => ['string', ''], 'primary_contact' => ['object', 'name, title, email, phone, mobile.'],
            'vcio' => ['object', 'Staff user who is vCIO for the client: id, name.'], 'meeting_cadence' => ['string', 'How often you meet.'], 'in_planning' => ['boolean', 'false = taken out of planning.'],
            'created_at' => ['date-time', ''], 'updated_at' => ['date-time', ''], 'url' => ['string', 'Link to the client in the web app.']]],
        'ClientDetail' => ['A client plus a health summary.', ['(all Client fields)' => ['object', ''],
            'summary' => ['object', 'devices {total, healthy, past_end_of_life, unsupported_os, out_of_warranty}, projects {active, awaiting_decision}, next_meeting, backups {health, jobs, failed_jobs, ...}, compliance {frameworks, average_score}, alignment {score, band, reviewed_at, gaps} (2.3.0), service_levels_90d. Only the sections the key can read.']]],
        'Contact' => ['A client contact.', [
            'id' => ['integer', ''], 'client_id' => ['integer', ''], 'name' => ['string', ''], 'title' => ['string', ''], 'department' => ['string', ''], 'email' => ['string', ''],
            'phone' => ['string', ''], 'extension' => ['string', ''], 'mobile' => ['string', ''], 'location' => ['string', ''],
            'roles' => ['string[]', 'primary, billing, technical, important, decision_maker, meeting_invitee.'], 'notes' => ['string', 'Align notes.'], 'source' => ['string', ''],
            'psa_id' => ['id', 'The contact\'s id in the PSA (text, as psa_id on clients).'], 'itflow_contact_id' => ['integer', 'Older name for psa_id, kept for existing integrations.'], 'archived' => ['boolean', ''], 'updated_at' => ['date-time', '']]],
        'Device' => ['A device with its computed lifecycle.', [
            'id' => ['integer', ''], 'client_id' => ['integer', ''], 'client_name' => ['string', ''], 'name' => ['string', ''], 'type' => ['string', 'e.g. Server, Hypervisor host, Laptop.'],
            'category' => ['string', 'Lifecycle category: desktop, laptop, server, network, printer, storage, power, other.'], 'is_virtual' => ['boolean', ''], 'source' => ['string', 'ninja (NinjaOne), another RMM\'s key, the PSA\'s key (e.g. itflow) or manual.'], 'rmm' => ['object', 'For RMM devices: provider (e.g. ninjaone), device_id, organization_id. null otherwise.'],
            'manufacturer' => ['string', ''], 'model' => ['string', ''], 'serial' => ['string', ''], 'ip_address' => ['string', ''], 'location' => ['string', ''], 'last_user' => ['string', ''],
            'last_seen' => ['date-time', ''], 'os' => ['object', 'name, build, support_ends.'],
            'lifecycle' => ['object', 'status, status_label, health (ok/warn/bad/muted), flags, in_service_date, in_service_source, in_service_estimated, age_years, lifespan_years, end_of_life, replace_by, planned_replacement {quarter, label, note, deferred}, replacement_cost, excluded, project {id, title, status, target_quarter, psa_ticket_id}: the project replacing it (2.1; its devices leave the automatic plan, replace_by null).'],
            'warranty' => ['object', 'end, source.'], 'overrides' => ['object', 'Values set in Align (null = using the synced value or policy default).'],
            'psa_asset_id' => ['id', 'The linked PSA asset (text, as psa_id on clients).'], 'itflow_asset_id' => ['integer', 'Older name for psa_asset_id, kept for existing integrations.'], 'ninja_device_id' => ['integer', 'NinjaOne devices only: same as rmm.device_id. Older field, kept for existing integrations.'], 'synced_at' => ['date-time', ''], 'url' => ['string', ''],
            'psa_sync' => ['object', 'After PATCH only: status (ok, off, queued, error) and message.'], 'itflow_sync' => ['object', 'Older name for psa_sync, kept for existing integrations.']]],
        'Project' => ['A roadmap project.', [
            'id' => ['integer', ''], 'client_id' => ['integer', ''], 'client_name' => ['string', ''], 'title' => ['string', ''], 'category' => ['string', ''], 'category_label' => ['string', ''],
            'description' => ['string', ''], 'target_quarter' => ['date', 'First day of the planned quarter; null = backlog.'], 'quarter_label' => ['string', 'e.g. Q1 2027 or FY2027 Q3.'],
            'cost' => ['number', 'One-time.'], 'recurring_monthly' => ['number', ''], 'priority' => ['string', 'critical, high, medium, low.'],
            'status' => ['string', 'proposed, approved, scheduled, done, declined.'], 'device_ids' => ['integer[]', 'Devices this project replaces (made from Devices → Make projects); they leave the automatic replacement plan while the project isn\'t declined.'],
            'psa_ticket_id' => ['id', 'The project\'s QUOTE- ticket in the PSA, once someone pressed Ready to start (or asked for it when the project was made); null until then.'],
            'started_at' => ['date-time', 'When Ready to start was first pressed: the ticket was made, or (with no ticket possible, e.g. no PSA) the project was marked started; a ticket made later keeps this date. Null until then; projects whose ticket was made before 2.2.2 have none.'],
            'decision' => ['object', 'Who approved or declined it (e.g. in the client portal): by, at, comment, via_portal.'],
            'alignment_standard_id' => ['integer', 'The alignment standard this project fixes (made from a gap, 2.3.0); null otherwise.'],
            'created_at' => ['date-time', ''], 'updated_at' => ['date-time', ''], 'url' => ['string', '']]],
        'BudgetSummary' => ['A client\'s three-year technology budget.', [
            'client_id' => ['integer', ''], 'currency' => ['string', ''], 'recurring_monthly' => ['number', 'Current recurring monthly run rate.'],
            'selected_year' => ['object', 'label, from, to, total, one_time, by_category.'], 'years' => ['object[]', 'The three plan years, same shape.'],
            'quarters' => ['object[]', 'label, start, end, year_index, total, by_category.'], 'categories' => ['object', 'Category key => label.'],
            'lines' => ['object[]', 'Every budget line: key, name, category, source (manual, licensing, hardware, projects, or the PSA\'s key for the managed-services estimate), budget_line_id, detail, monthly, one_time, tentative, by_quarter (12 values).'],
            'contract_dates' => ['object[]', 'date, kind, label, name, term, auto_renew, annual_value.']]],
        'BudgetLine' => ['A budget line you added.', [
            'id' => ['integer', ''], 'client_id' => ['integer', ''], 'name' => ['string', ''], 'category' => ['string', ''], 'vendor' => ['string', ''], 'amount' => ['number', 'Per billing period.'],
            'frequency' => ['string', 'monthly, quarterly, annual, one_time.'], 'start_date' => ['date', ''], 'end_date' => ['date', ''], 'contract_term_months' => ['integer', ''],
            'contract_end' => ['date', ''], 'notice_days' => ['integer', ''], 'renegotiate_date' => ['date', ''], 'auto_renew' => ['boolean', ''], 'notes' => ['string', ''],
            'created_at' => ['date-time', ''], 'updated_at' => ['date-time', '']]],
        'License' => ['A software license or subscription.', [
            'id' => ['integer', ''], 'client_id' => ['integer', ''], 'source' => ['string', 'The PSA\'s key (e.g. itflow) or manual.'], 'psa_id' => ['id', 'The license\'s id in the PSA (text, as psa_id on clients).'], 'itflow_software_id' => ['integer', 'Older name for psa_id, kept for existing integrations.'], 'name' => ['string', ''],
            'version' => ['string', ''], 'software_type' => ['string', ''], 'license_type' => ['string', ''], 'category' => ['string', ''], 'vendor' => ['string', ''],
            'seats' => ['integer', ''], 'seats_used' => ['integer', ''], 'pricing' => ['string', 'per_seat or flat.'], 'unit_price' => ['number', ''], 'billing_cycle' => ['string', ''],
            'priced' => ['boolean', ''], 'cost_per_cycle' => ['number', ''], 'monthly' => ['number', ''], 'annual' => ['number', ''], 'purchase_date' => ['date', ''], 'expire_date' => ['date', ''],
            'auto_renew' => ['boolean', ''], 'contract_start' => ['date', ''], 'contract_term_months' => ['integer', ''], 'contract_end' => ['date', ''], 'notice_days' => ['integer', ''],
            'renegotiate_date' => ['date', ''], 'notes' => ['string', 'Align notes.'], 'psa_notes' => ['string', 'Notes from the PSA.'], 'itflow_notes' => ['string', 'Older name for psa_notes, kept for existing integrations.'], 'retired' => ['boolean', ''], 'updated_at' => ['date-time', '']]],
        'Meeting' => ['A meeting.', [
            'id' => ['integer', ''], 'client_id' => ['integer', 'null = internal.'], 'title' => ['string', ''], 'type' => ['string', ''], 'type_label' => ['string', ''],
            'status' => ['string', 'scheduled, completed, cancelled.'], 'starts_at' => ['date-time', ''], 'ends_at' => ['date-time', ''], 'duration_minutes' => ['integer', ''],
            'location' => ['string', ''], 'video_url' => ['string', ''], 'attendees' => ['object[]', 'email, name.'], 'agenda' => ['string', ''], 'notes' => ['string', ''],
            'owner' => ['object', 'id, name.'], 'series_id' => ['integer', ''], 'invitations_sent_at' => ['date-time', ''], 'created_at' => ['date-time', ''], 'updated_at' => ['date-time', ''],
            'url' => ['string', ''], 'invitations' => ['string', 'After POST/PATCH with send_invites: what happened to the invitations.']]],
        'Standard' => ['A standard in the alignment library (2.3.0).', [
            'id' => ['integer', ''], 'category' => ['string', ''], 'section' => ['string', 'Optional heading above the category.'], 'title' => ['string', ''],
            'why' => ['string', 'Why it matters, in the client\'s words.'], 'how' => ['string', 'How to check it (staff notes).'], 'priority' => ['string', 'critical, high, medium, low.'],
            'weight' => ['integer', 'How much it counts in the score: 4, 3, 2 or 1.'], 'auto_check' => ['string', 'os_supported, hw_lifecycle, warranty, stale or backups; null = answered by hand.'],
            'tags' => ['string[]', 'Compliance tags: answers are shared with compliance controls that have the same tags.'],
            'suggested_fix' => ['object', 'title, category, cost: what Make project fills in.'], 'active' => ['boolean', 'false = switched off (kept for old reviews).'], 'updated_at' => ['date-time', '']]],
        'Alignment' => ['A client\'s alignment with the standards (2.3.0).', [
            'client_id' => ['integer', ''], 'score' => ['integer', 'Percent from the latest finished review; null = never reviewed (or nothing that applies).'],
            'band' => ['string', 'On track (80+), Needs attention (60-79), At risk (under 60), Not reviewed or No score.'], 'reviewed_at' => ['date-time', ''], 'reviewed_by' => ['string', ''], 'review_id' => ['integer', ''],
            'counts' => ['object', 'aligned, misaligned, not_applicable, unanswered.'], 'change' => ['object', 'points since the review before, since (its date); null with fewer than two reviews.'],
            'gaps' => ['object[]', 'standard_id, title, priority, category, note, why, helps_with (compliance controls), project {id, title, status, target_quarter} or null. Most important first.'],
            'not_applicable' => ['object[]', 'standard_id, title, note.'], 'draft' => ['object', 'id, started_at of the open draft; null when none.'], 'url' => ['string', '']]],
        'AlignmentReview' => ['An alignment review (2.3.0).', [
            'id' => ['integer', ''], 'client_id' => ['integer', ''], 'status' => ['string', 'draft or done.'], 'started_at' => ['date-time', ''], 'started_by' => ['string', ''],
            'finished_at' => ['date-time', ''], 'finished_by' => ['string', ''], 'score' => ['integer', 'Stored when finished.'], 'band' => ['string', ''],
            'counts' => ['object', 'aligned, misaligned, not_applicable, unanswered.'], 'score_so_far' => ['integer', 'Drafts, with answers: the score if it were finished now.'],
            'answers' => ['object[]', 'Single review only: standard_id, category, title, priority, auto_check, answer (aligned, misaligned, na or null), note, updated_at. A draft lists every active standard.'],
            'url' => ['string', '']]],
        'Changes' => ['What changed for a client since a business review (2.4.0).', [
            'client_id' => ['integer', ''], 'since' => ['object', 'key, meeting_id, date, at, label, days, snapshot (then-figures saved at that review).'],
            'baselines' => ['object[]', 'Completed reviews to compare with, newest first.'], 'headline' => ['object[]', 'tone, title, text: a short summary.'],
            'devices' => ['object', 'now, then, counts and lists: added, removed, replaced, warranty_expired, became_due, os_ended (devices:read).'],
            'projects' => ['object', 'counts and lists: done, added, approved, started, declined (projects:read).'], 'spend' => ['object', 'done_cost, done_monthly, slipped (projects:read).'],
            'alignment' => ['object', 'then, now, change, closed and opened gaps (alignment:read).'], 'compliance' => ['object', 'frameworks with now, then, change, updated (compliance:read).'],
            'licenses' => ['object', 'now, then, added, retired (licenses:read).'], 'backup' => ['object', 'now, then (backups:read).'], 'tickets' => ['object', 'opened, closed, still_open, categories (service:read).'],
            'url' => ['string', '']]],
        'Health' => ['A client\'s health score (2.5.0).', [
            'client_id' => ['integer', ''], 'score' => ['integer', '0-100; null when no area has data.'], 'band' => ['string', 'Healthy, Needs attention, At risk or No score.'],
            'areas_counted' => ['integer', 'Areas with data and a weight above 0.'], 'areas_weighted' => ['integer', 'Areas with a weight above 0.'],
            'bands' => ['object', 'healthy_from, needs_attention_from (the settings in use).'],
            'areas' => ['object[]', 'key (lifecycle, backups, compliance, service, alignment), label, score (null = no data), weight, counted, details [{text, tone}] (null when the key can\'t read that area).'],
            'weakest' => ['object', 'key, score of the lowest counted area; null when none.'],
            'since_last_review' => ['object', 'date, score then, change, label (with meetings:read) of the newest completed business review; null when there is none or no score from then.'],
            'url' => ['string', '']]],
        'HealthDay' => ['A client\'s health on one day (2.5.0).', [
            'date' => ['date', ''], 'score' => ['integer', 'Null when no area had data.'], 'band' => ['string', ''], 'areas' => ['object', 'lifecycle, backups, compliance, service, alignment: each 0-100 or null.']]],
        'AlignmentAnswersResult' => ['Result of answering standards.', ['updated' => ['integer', ''], 'unchanged' => ['integer', ''], 'review' => ['object', 'The review afterwards (without answers).']]],
        'Framework' => ['A compliance framework.', ['id' => ['integer', ''], 'slug' => ['string', ''], 'name' => ['string', ''], 'description' => ['string', ''], 'built_in' => ['boolean', ''], 'controls' => ['integer', '']]],
        'Assessment' => ['A framework assigned to a client, with its score.', [
            'client_id' => ['integer', ''], 'framework_id' => ['integer', ''], 'framework' => ['string', ''], 'slug' => ['string', ''], 'assigned_at' => ['date-time', ''], 'last_reviewed' => ['date', ''], 'next_review' => ['date', ''],
            'score' => ['object', 'percent (partial counts half), met, partial, not_met, not_assessed, applicable, assessed_percent.'], 'url' => ['string', '']]],
        'Control' => ['A control and the client\'s status for it.', [
            'id' => ['integer', ''], 'ref' => ['string', ''], 'section' => ['string', ''], 'title' => ['string', ''], 'guidance' => ['string', 'What "met" looks like and the evidence to collect.'],
            'automatic_check' => ['string', 'Check Align runs from its own data, if any.'], 'crosswalk_tags' => ['string[]', ''], 'status' => ['string', ''], 'notes' => ['string', ''],
            'evidence' => ['string', ''], 'owner' => ['string', ''], 'due_date' => ['date', ''], 'document_id' => ['integer', ''], 'updated_at' => ['date-time', '']]],
        'BulkResult' => ['Result of a bulk update.', ['updated' => ['integer', ''], 'unchanged' => ['integer', ''], 'assessment' => ['object', 'The framework\'s score afterwards.']]],
        'BackupSummary' => ['Backup health for one client.', ['client_id' => ['integer', ''], 'client_name' => ['string', ''], 'health' => ['string', 'ok, warn, bad or muted.'],
            'jobs' => ['integer', ''], 'failed_jobs' => ['integer', ''], 'jobs_with_warnings' => ['integer', ''], 'protected_machines' => ['integer', ''], 'overdue' => ['integer', ''], 'success_rate_30d' => ['integer', '']]],
        'ClientBackups' => ['A client\'s backups.', ['client_id' => ['integer', ''], 'health' => ['string', ''], 'synced_at' => ['date-time', ''], 'stale_after_hours' => ['integer', ''],
            'stats' => ['object', ''], 'history_30d' => ['object[]', 'date, result (success, warning, failed, none).'],
            'jobs' => ['object[]', 'uid, name, kind, status, label, health, enabled, last_run, duration_seconds, message, target, size_bytes, hosted, shared_with_other_clients.'],
            'machines' => ['object[]', 'uid, name, kind, status, health, newest_restore_point, restore_points, backup_bytes, device_id, hosted, not_required.'],
            'servers_without_backup' => ['object[]', 'device_id, name, type.'], 'microsoft_365' => ['object', 'health, protected_objects, users, overdue, newest_restore_point, by_type, and objects: one per user, group, team or site (uid, name, type, health, newest_restore_point, restore_points, days_with_backup, counting_since, repositories, not_required, no_longer_backed_up: no new backup for longer than the "no longer backed up" days, old backups kept, health "retired", not counted). A mailbox kept in more than one repository is one object; days_with_backup counts each day with a restore point once, from counting_since.'], 'url' => ['string', '']]],
        'Exemption' => ['An item marked "backup not required".', ['id' => ['integer', ''], 'client_id' => ['integer', ''], 'kind' => ['string', 'device, workload or m365.'],
            'device_id' => ['integer', ''], 'item_uid' => ['string', ''], 'name' => ['string', ''], 'reason' => ['string', ''], 'created_at' => ['date-time', '']]],
        'HostedMachine' => ['A machine on your own backup server.', ['uid' => ['string', ''], 'name' => ['string', ''], 'kind' => ['string', 'vm or computer.'], 'newest_restore_point' => ['date-time', ''],
            'client_id' => ['integer', ''], 'client_name' => ['string', ''], 'matched_by' => ['string', 'device_name, job, manual or company.'], 'state' => ['string', 'sorted, ours or unmatched.'],
            'assignment' => ['string', '"auto", "ours" or a client id set by hand.'], 'device_id' => ['integer', ''], 'job_uids' => ['string[]', '']]],
        'HostedAssignment' => ['Result of assigning a hosted machine or job.', ['uid' => ['string', ''], 'name' => ['string', ''], 'client_id' => ['integer', 'Machines.'], 'client_ids' => ['integer[]', 'Jobs.'],
            'state' => ['string', 'Machines.'], 'machines' => ['integer', 'Jobs.']]],
        'ServiceLevels' => ['SLA results for a period.', ['client_id' => ['integer', ''], 'period' => ['string', ''], 'label' => ['string', ''], 'goal_pct' => ['number', ''], 'has_sla' => ['boolean', ''], 'synced_at' => ['date-time', 'Last ticket sync.'],
            'stats' => ['object', 'tickets, responded_on_time_pct, resolved_on_time_pct, response_met/missed, resolution_met/missed, avg_first_response_minutes.'], 'previous_period' => ['object', 'Same shape.'],
            'open' => ['object', 'total, past_target, close_to_target.'], 'monthly' => ['object[]', 'Last 12 months, oldest first: month (YYYY-MM) plus the stats fields.'], 'by_priority' => ['object[]', ''],
            'missed_tickets' => ['object[]', 'number, opened, priority, subject, missed_response, missed_resolution.'], 'url' => ['string', '']]],
    ];

    /** status => [error codes, description] for the error responses. */
    public const ERRORS = [
        400 => ['invalid_json, invalid_idempotency_key', 'The request body or a header is malformed.'],
        401 => ['missing_key, invalid_key, key_expired, key_revoked, key_owner_inactive', 'No usable API key (a key stops when the staff account that created it is disabled or is no longer an admin).'],
        403 => ['insufficient_scope, all_clients_required', 'The key lacks the permission (see the X-Required-Scope header).'],
        404 => ['not_found, api_disabled, no_backup_data, no_service_data', 'Not found, or outside the clients the key is limited to.'],
        405 => ['method_not_allowed', 'See the Allow header.'],
        409 => ['idempotency_conflict, idempotency_in_progress, managed_in_itflow (managed_in_<PSA key> for other PSAs), not_enabled', 'The request conflicts with the current state.'],
        413 => ['body_too_large', 'Bodies are limited to 1 MB.'],
        415 => ['unsupported_media_type', 'Send JSON with Content-Type: application/json.'],
        422 => ['validation_failed', 'error.fields names each problem.'],
        429 => ['rate_limited, too_many_failed_requests', 'Wait for Retry-After seconds. Requests without a valid key are also limited per IP address.'],
        500 => ['internal_error', 'Logged on the server with the request_id.'],
    ];

    /** The whole OpenAPI document. */
    public static function build(): array
    {
        $paths = [];
        $tags = [];
        $seen = [];
        foreach (Routes::all() as $r) {
            $tags[$r['tag']] = true;
            $oaPath = '/api/v1' . rtrim(preg_replace(['#\{(\w+):str\}#'], ['{$1}'], $r['path']), '/');
            $oaPath = $oaPath === '/api/v1' ? '/api/v1' : $oaPath;
            $params = [];
            preg_match_all('#\{(\w+)(:str)?\}#', $r['path'], $m, PREG_SET_ORDER);
            foreach ($m as $p) {
                $params[] = ['name' => $p[1], 'in' => 'path', 'required' => true, 'schema' => ['type' => empty($p[2]) ? 'integer' : 'string']];
            }
            foreach ($r['query'] as $name => [$type, $desc]) {
                $params[] = ['name' => $name, 'in' => 'query', 'required' => false, 'description' => $desc, 'schema' => ['type' => ['int' => 'integer', 'bool' => 'boolean'][$type] ?? 'string']];
            }
            $op = [
                'operationId' => $id = self::uniqueId(self::opId($r), $seen),
                'tags' => [$r['tag']],
                'summary' => $r['summary'],
                'description' => trim($r['description'] . ($r['scope'] ? "\n\nRequires the `{$r['scope']}` scope." : '')),
                'parameters' => $params,
                'responses' => self::responses($r),
            ];
            if ($r['scope']) {
                $op['security'] = [['bearer' => [$r['scope']]]];
            }
            if ($r['body'] !== null) {
                $op['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => self::bodySchema(self::rules($r), $r['creating'])]]];
                if ($r['method'] === 'POST') {
                    $op['parameters'][] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'maxLength' => 100],
                        'description' => 'Send a unique value to make retries safe: the same key within 24 hours returns the first response instead of creating a duplicate.'];
                }
            }
            $paths[$oaPath][strtolower($r['method'])] = $op;
        }
        $schemas = ['Error' => ['type' => 'object', 'properties' => [
            'error' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'message' => ['type' => 'string'], 'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]], 'required' => ['code', 'message']],
            'request_id' => ['type' => 'string']]], 'Meta' => ['type' => 'object', 'properties' => ['page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'total' => ['type' => 'integer'], 'has_more' => ['type' => 'boolean']]]];
        foreach (self::SCHEMAS as $name => [$desc, $fields]) {
            $props = [];
            foreach ($fields as $f => [$t, $d]) {
                if ($f[0] === '(') {
                    continue;
                }
                $props[$f] = self::type($t) + ($d !== '' ? ['description' => $d] : []);
            }
            $schemas[$name] = ['type' => 'object', 'description' => $desc, 'properties' => $props];
        }
        if (isset($schemas['ClientDetail'])) {
            $schemas['ClientDetail'] = ['allOf' => [['$ref' => '#/components/schemas/Client'], $schemas['ClientDetail']]];
        }
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => \Align\Branding::name() . ' API',
                'version' => (string) Kernel::VERSION . '.0',
                'description' => self::intro(),
            ],
            'servers' => [['url' => rtrim((string) \Align\Config::get('base_url', ''), '/') ?: '/']],
            'security' => [['bearer' => []]],
            'tags' => array_map(fn($t) => ['name' => $t], array_keys($tags)),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'API key from Settings → API: "Authorization: Bearer msa_…".']],
                'schemas' => $schemas,
            ],
        ];
    }

    /** $id, or $id2, $id3 ... if already used, so every operationId is unique. */
    private static function uniqueId(string $id, array &$seen): string
    {
        $base = $id;
        for ($n = 2; isset($seen[$id]); $n++) {
            $id = $base . $n;
        }
        $seen[$id] = true;
        return $id;
    }

    /** The overview text at the top of the spec and the docs page (Markdown). */
    public static function intro(): string
    {
        return "Read and change planning data in " . \Align\Branding::name() . ". JSON in and out.\n\n"
            . "**Authentication**: send the key from Settings → API as `Authorization: Bearer msa_…` (or `X-API-Key`). Each key has scopes (area:read / area:write) and can be limited to certain clients; everything else answers 404.\n\n"
            . "**Responses**: one item is `{\"data\": {...}}`; lists are `{\"data\": [...], \"meta\": {\"page\", \"per_page\", \"total\", \"has_more\"}}` with `?page=` and `?per_page=` (max 200). Errors are `{\"error\": {\"code\", \"message\", \"fields\"}, \"request_id\"}`.\n\n"
            . "**Changes**: PATCH changes only the fields you send; null clears a field. Unknown fields are refused (422) so typos don't pass silently. Every change is written to the audit log with the key's name.\n\n"
            . "**Rate limit**: per key per minute (X-RateLimit-* headers; 429 with Retry-After). **Retries**: send an `Idempotency-Key` header on POST.\n\n"
            . "**Dates**: dates are YYYY-MM-DD; times are ISO 8601 with offset. Quarters accept `2027-Q1` or any date in the quarter and come back as the quarter's first day (Q = calendar quarter).";
    }

    /** Validation rules for a route's body (calls the route's rules callable when it has one). */
    public static function rules(array $r): array
    {
        $b = $r['body'];
        return is_callable($b) ? $b() : (array) $b;
    }

    /** JSON Schema for a request body from Input rules: unknown fields refused, required fields only when creating. */
    private static function bodySchema(array $rules, bool $creating): array
    {
        $props = [];
        $required = [];
        foreach ($rules as $name => $rule) {
            [$type, $o] = $rule + [1 => []];
            $s = match ($type) {
                'int' => ['type' => 'integer'],
                'number' => ['type' => 'number'],
                'bool' => ['type' => 'boolean'],
                'date' => ['type' => 'string', 'format' => 'date'],
                'datetime' => ['type' => 'string', 'format' => 'date-time'],
                'quarter' => ['type' => 'string', 'examples' => ['2027-Q1', '2027-01-01']],
                'url' => ['type' => 'string', 'format' => 'uri'],
                'email_list' => ['type' => 'array', 'items' => ['type' => 'string']],
                'id_list' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'array' => ['type' => 'array', 'items' => ['type' => 'object']],
                default => ['type' => 'string'],
            };
            foreach (['min' => 'minimum', 'max' => $type === 'string' ? 'maxLength' : 'maximum'] as $k => $oa) {
                if (isset($o[$k]) && in_array($type, ['int', 'number', 'string'], true)) {
                    $s[$oa] = $o[$k];
                }
            }
            if (isset($o['enum'])) {
                $s['enum'] = $o['enum'];
            }
            if (!empty($o['desc'])) {
                $s['description'] = $o['desc'];
            }
            if (empty($o['required'])) {
                $s['type'] = [$s['type'], 'null'];
            } elseif ($creating) {
                $required[] = $name;
            }
            $props[$name] = $s;
        }
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $props] + ($required ? ['required' => $required] : []);
    }

    /** The success response and the common error responses for a route. */
    private static function responses(array $r): array
    {
        $out = [];
        if ($r['status'] === 204) {
            $out['204'] = ['description' => 'Done (no content).'];
        } else {
            $ref = $r['returns'] ? ['$ref' => '#/components/schemas/' . $r['returns']] : ['type' => 'object'];
            $schema = $r['list']
                ? ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => $ref], 'meta' => ['$ref' => '#/components/schemas/Meta']]]
                : ['type' => 'object', 'properties' => ['data' => $ref]];
            $out[(string) $r['status']] = ['description' => $r['status'] === 201 ? 'Created.' : 'OK.', 'content' => ['application/json' => ['schema' => $schema]]];
        }
        foreach ([401, 403, 404, 422, 429] as $code) {
            if ($code === 422 && $r['body'] === null && !$r['query']) {
                continue;
            }
            $out[(string) $code] = ['description' => self::ERRORS[$code][1] . ' Codes: ' . self::ERRORS[$code][0] . '.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        }
        return $out;
    }

    /** JSON Schema type for a SCHEMAS field type (every field may be null). */
    private static function type(string $t): array
    {
        if (str_ends_with($t, '[]')) {
            return ['type' => ['array', 'null'], 'items' => self::type(substr($t, 0, -2))];
        }
        return match ($t) {
            'date' => ['type' => ['string', 'null'], 'format' => 'date'],
            'date-time' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'integer', 'number', 'string', 'boolean', 'object', 'array' => ['type' => [$t, 'null']],
            'id' => ['type' => ['string', 'null']], // an id in another system: always text (2.0)
            default => ['type' => 'object'],
        };
    }

    /** listProjects, getProject, createProject, updateProject, deleteProject, updateManyClientsComplianceControls ... */
    public static function opId(array $r): string
    {
        $parts = array_values(array_filter(explode('/', $r['path']), fn($p) => $p !== '' && !str_starts_with($p, '{')));
        $words = array_map(fn($p) => str_replace(' ', '', ucwords(str_replace('-', ' ', $p))), $parts);
        $one = str_ends_with($r['path'], '}') || $r['method'] === 'POST';
        if ($words && $one) {
            $last = array_pop($words);
            $words[] = str_ends_with($last, 'ies') ? substr($last, 0, -3) . 'y' : (str_ends_with($last, 's') && !str_ends_with($last, 'ss') ? substr($last, 0, -1) : $last);
        }
        $verb = match ($r['method']) {
            'GET' => $r['list'] ? 'list' : 'get',
            'POST' => 'create',
            'PATCH' => $one ? 'update' : 'updateMany',
            'PUT' => 'set',
            'DELETE' => 'delete',
        };
        return $verb . (implode('', $words) ?: 'Key');
    }
}
