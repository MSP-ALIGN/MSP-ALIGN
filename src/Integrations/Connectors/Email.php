<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\Connector;
use Align\Mail\Mail;
use Align\Mail\Mailer;

/** Email: Microsoft 365, Google Workspace or an SMTP server. Has its own page (OAuth sign-in, provider choice). */
final class Email extends Connector
{
    public function key(): string { return 'email'; }
    public function name(): string { return 'Email (Microsoft 365, Google or SMTP)'; }
    public function icon(): string { return 'fas fa-envelope'; }
    public function category(): string { return 'Email & calendar'; }
    public function summary(): string { return 'Sends notifications, digests, portal invitations and meeting invitations: through Microsoft 365 or Google Workspace (Outlook/Teams or Google Calendar/Meet invitations), or any SMTP server (.ics invitations).'; }
    public function direction(): string { return 'Send only'; }

    public function configured(): bool
    {
        return Mail::on();
    }

    public function setup(): string
    {
        return '';
    }

    public function status(): array
    {
        if (!Mail::on()) {
            return ['secondary', 'Not set up', ''];
        }
        if (!Mail::ready()) {
            return ['warning', 'Not finished', 'Finish the connection settings'];
        }
        $s = Mailer::stats();
        if ($s['failed7'] && $s['last_error']) {
            return ['danger', 'Problem', mb_strimwidth((string) $s['last_error'], 0, 200, '…')];
        }
        return ['success', 'Connected', Mail::providerName() . ($s['last_sent'] ? ' · last sent ' . rel_time($s['last_sent']) : '')];
    }
}
