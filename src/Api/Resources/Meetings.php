<?php
declare(strict_types=1);

namespace Align\Api\Resources;

use Align\Api\ApiError;
use Align\Api\Context;
use Align\Api\Input;
use Align\Api\Out;
use Align\DB;
use Align\Meetings\Meetings as M;

/** Meetings. Invitations go out only when send_invites is true (and email is connected), like the meeting form. */
final class Meetings
{
    public static function rules(bool $creating = false): array
    {
        return array_filter([
            'client_id' => ['int', ['min' => 1, 'desc' => 'Client (null = internal meeting; needs a key for all clients).']],
            'title' => ['string', ['max' => 255, 'desc' => 'Title (default: the meeting type\'s name).']],
            'type' => ['string', ['enum' => array_keys(M::TYPES), 'desc' => 'Meeting type (default "other").']],
            'starts_at' => ['datetime', ['required' => true, 'desc' => 'Start, ISO 8601 (e.g. 2026-10-14T09:00:00-07:00). Without an offset the server\'s time zone is used.']],
            'duration_minutes' => ['int', ['min' => 15, 'max' => 600, 'desc' => 'Length (default from Settings → Planning).']],
            'location' => ['string', ['max' => 255]],
            'video_url' => ['url', ['max' => 500, 'desc' => 'Teams / Meet / Zoom link.']],
            'attendees' => ['email_list', ['desc' => 'List of "email" or "Name <email>".']],
            'agenda' => ['string', ['max' => 20000]],
            'owner_id' => ['int', ['min' => 1, 'desc' => 'Staff user who owns / organizes it (default: the key\'s creator). Invitations can go out from this person\'s mailbox, so only keys for all clients may set it.']],
            'status' => $creating ? null : ['string', ['enum' => ['scheduled', 'completed', 'cancelled'], 'desc' => 'Mark completed or cancelled (cancelling sends cancellations if invitations went out), or scheduled to reopen.']],
            'notes' => $creating ? null : ['string', ['max' => 50000, 'desc' => 'Meeting notes.']],
            'send_invites' => ['bool', ['desc' => 'Email calendar invitations (or updates) to the attendees. Default false.']],
        ]);
    }

    public static function index(): array
    {
        $where = ' WHERE 1 = 1';
        $args = [];
        if (($cid = Input::queryInt('client_id')) !== null) {
            Clients::load($cid);
            $where .= ' AND m.client_id = ?';
            $args[] = $cid;
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (($v = Input::queryStr($k)) !== null) {
                $t = strtotime($v);
                if (!$t) {
                    throw ApiError::invalid([$k => 'Must be a date or date and time.'], 'Invalid query parameter.');
                }
                $where .= " AND m.starts_at $op ?";
                $args[] = date('Y-m-d H:i:s', $k === 'to' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $t + 86399 : $t);
            }
        }
        if ($s = Input::queryStr('status', ['scheduled', 'completed', 'cancelled'])) {
            $where .= ' AND m.status = ?';
            $args[] = $s;
        }
        if ($s = Input::queryStr('type', array_keys(M::TYPES))) {
            $where .= ' AND m.type = ?';
            $args[] = $s;
        }
        if ($since = Input::querySince()) {
            $where .= ' AND COALESCE(m.updated_at, m.created_at) >= ?';
            $args[] = $since;
        }
        [$cs, $ca] = Context::clientSql('m.client_id');
        $where .= $cs;
        $args = [...$args, ...$ca];
        [$page, $per, $off] = Input::page();
        $total = (int) DB::value("SELECT COUNT(*) FROM meetings m$where", $args);
        $rows = DB::all("SELECT m.*, u.name AS owner_name FROM meetings m LEFT JOIN users u ON u.id = m.owner_id$where ORDER BY m.starts_at LIMIT $per OFFSET $off", $args);
        return Out::list(array_map([self::class, 'shape'], $rows), $total, $page, $per);
    }

    public static function show(int $id): array
    {
        return Out::one(self::shape(self::load($id)));
    }

    private static function load(int $id): array
    {
        $m = DB::one('SELECT m.*, u.name AS owner_name FROM meetings m LEFT JOIN users u ON u.id = m.owner_id WHERE m.id = ?', [$id]);
        if (!$m || !Context::allowsClient($m['client_id'] !== null ? (int) $m['client_id'] : null)) {
            throw ApiError::notFound('Meeting');
        }
        return $m;
    }

    /** Validated columns for insert/update from cleaned input. */
    private static function columns(array $in, ?array $current): array
    {
        $cols = [];
        if (array_key_exists('client_id', $in)) {
            if ($in['client_id'] !== null) {
                Clients::load($in['client_id']);
            } elseif (Context::clients() !== null) {
                throw ApiError::invalid(['client_id' => 'This key is limited to certain clients, so the meeting needs one of them.']);
            }
            $cols['client_id'] = $in['client_id'];
        }
        if (array_key_exists('type', $in)) {
            $cols['type'] = $in['type'] ?? 'other';
        }
        if (array_key_exists('title', $in)) {
            $cols['title'] = $in['title'] ?: M::typeLabel($cols['type'] ?? $current['type'] ?? 'other');
        }
        $start = array_key_exists('starts_at', $in) ? strtotime($in['starts_at']) : ($current ? strtotime($current['starts_at']) : null);
        $mins = $in['duration_minutes'] ?? ($current ? (int) round((strtotime($current['ends_at']) - strtotime($current['starts_at'])) / 60) : \Align\Settings::int('meeting_default_minutes', 60));
        if (array_key_exists('starts_at', $in) || array_key_exists('duration_minutes', $in)) {
            $cols['starts_at'] = date('Y-m-d H:i:s', $start);
            $cols['ends_at'] = date('Y-m-d H:i:s', $start + max(15, (int) $mins) * 60);
        }
        foreach (['location', 'video_url', 'agenda', 'notes'] as $k) {
            if (array_key_exists($k, $in)) {
                $cols[$k] = $in[$k];
            }
        }
        if (array_key_exists('attendees', $in)) {
            $cols['attendees'] = $in['attendees'] ? mb_substr(implode("\n", $in['attendees']), 0, 4000) : null;
        }
        if (array_key_exists('owner_id', $in) && $in['owner_id'] !== null) {
            if (Context::clients() !== null) {
                throw ApiError::invalid(['owner_id' => 'Only a key for all clients can choose the owner (invitations may be sent from their mailbox).']);
            }
            if (!DB::value('SELECT 1 FROM users WHERE id = ? AND is_active = 1', [$in['owner_id']])) {
                throw ApiError::invalid(['owner_id' => 'No active staff user with that id.']);
            }
            $cols['owner_id'] = $in['owner_id'];
        }
        return $cols;
    }

    public static function create(): array
    {
        $in = Input::clean(Context::$body, self::rules(true), true);
        if (!array_key_exists('client_id', $in) && Context::clients() !== null) {
            throw ApiError::invalid(['client_id' => 'Required for a key limited to certain clients.']);
        }
        $in += ['type' => 'other', 'title' => null];
        $cols = self::columns($in, null);
        $cols['owner_id'] ??= Context::$key['created_by'] ? (int) Context::$key['created_by'] : null;
        $id = DB::insert('meetings', $cols + ['uid' => M::newUid(), 'status' => 'scheduled', 'created_by' => null]);
        \Align\Audit::log('meeting.create', $cols['title']);
        $invite = !empty($in['send_invites']) ? \Align\Mail\Invites::send($id) : null;
        return Out::one(self::shape(self::load($id)) + ['invitations' => self::inviteResult($invite, !empty($in['send_invites']))], 201);
    }

    public static function update(int $id): array
    {
        $m = self::load($id);
        $in = Input::clean(Context::$body, self::rules());
        if (!$in) {
            throw ApiError::invalid([], 'Send at least one field to change.');
        }
        $cols = self::columns($in, $m);
        $status = $in['status'] ?? null;
        if ($status !== null && $status !== $m['status']) {
            $cols['status'] = $status;
        }
        if ($cols) {
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($cols)));
            DB::run("UPDATE meetings SET $sets WHERE id = ?", [...array_values($cols), $id]);
        }
        $action = match (true) {
            isset($cols['status']) && $cols['status'] === 'completed' => 'complete',
            isset($cols['status']) && $cols['status'] === 'cancelled' => 'cancel',
            isset($cols['status']) && $cols['status'] === 'scheduled' => 'reopen',
            default => 'update',
        };
        \Align\Audit::log("meeting.$action", ($cols['title'] ?? $m['title']) . ' (' . implode(', ', array_keys($in)) . ')');
        $invite = null;
        if ($action === 'cancel') {
            $invite = \Align\Mail\Invites::send($id, 'cancel'); // only when invitations had gone out
        } elseif (($action === 'reopen' && $m['invites_sent_at']) || (!empty($in['send_invites']) && ($cols['status'] ?? $m['status']) === 'scheduled')) {
            $invite = \Align\Mail\Invites::send($id);
        }
        return Out::one(self::shape(self::load($id)) + ['invitations' => self::inviteResult($invite, $invite !== null)]);
    }

    /** What happened to invitations, without passing on the mail provider's error text (that goes to the audit log). */
    private static function inviteResult(?string $r, bool $asked): ?array
    {
        if (!$asked) {
            return null;
        }
        if ($r === null) {
            return ['status' => 'not_sent', 'message' => 'Nothing to send: email isn\'t connected, or there are no attendees.'];
        }
        return str_contains($r, 'not sent') || str_contains(strtolower($r), 'fail')
            ? ['status' => 'failed', 'message' => 'Invitations could not be sent. Details are in the audit log.']
            : ['status' => 'sent', 'message' => $r];
    }

    public static function delete(int $id): array
    {
        $m = self::load($id);
        if ($m['invites_sent_at'] && $m['status'] === 'scheduled') {
            \Align\Mail\Invites::send($id, 'cancel'); // tell attendees before it disappears
        }
        DB::run('DELETE FROM meetings WHERE id = ?', [$id]);
        \Align\Audit::log('meeting.delete', $m['title']);
        return Out::none();
    }

    public static function shape(array $m): array
    {
        return [
            'id' => (int) $m['id'],
            'client_id' => Out::int($m['client_id']),
            'title' => $m['title'],
            'type' => $m['type'],
            'type_label' => M::typeLabel((string) $m['type']),
            'status' => $m['status'],
            'starts_at' => Out::ts($m['starts_at']),
            'ends_at' => Out::ts($m['ends_at']),
            'duration_minutes' => (int) round((strtotime($m['ends_at']) - strtotime($m['starts_at'])) / 60),
            'location' => $m['location'],
            'video_url' => $m['video_url'] ?: $m['online_join_url'],
            'attendees' => array_map(fn($a) => ['email' => $a['address'], 'name' => $a['name'] ?: null], \Align\Mail\Invites::attendees($m['attendees'])),
            'agenda' => $m['agenda'],
            'notes' => $m['notes'],
            'owner' => $m['owner_id'] ? ['id' => (int) $m['owner_id'], 'name' => $m['owner_name'] ?? null] : null,
            'series_id' => Out::int($m['series_id']),
            'invitations_sent_at' => Out::ts($m['invites_sent_at']),
            'created_at' => Out::ts($m['created_at']),
            'updated_at' => Out::ts($m['updated_at'] ?? $m['created_at']),
            'url' => Out::url('/meetings/' . (int) $m['id']),
        ];
    }
}
