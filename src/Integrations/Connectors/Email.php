<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\Connector;
use Align\Mail\Mail;
use Align\Mail\Mailer;

/**
 * Email: Microsoft 365, Google Workspace or an SMTP server. Has its own page (OAuth sign-in, provider choice).
 *
 * Security assumptions: only the card lives here; settings, secrets and sign-in are on EmailController's page,
 * reviewed with the mail code. status() may show the last send error (plain text, escaped by the views).
 */
final class Email extends Connector
{
    /** Slug for the URL and the Registry. */
    public function key(): string { return 'email'; }
    /** Name on the card and page. */
    public function name(): string { return 'Email (Microsoft 365, Google or SMTP)'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-envelope'; }
    /** Group on the Integrations page. */
    public function category(): string { return 'Email & calendar'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Sends notifications, digests, portal invitations and meeting invitations: through Microsoft 365 or Google Workspace (Outlook/Teams or Google Calendar/Meet invitations), or any SMTP server (.ics invitations).'; }
    /** Align only sends through it. */
    public function direction(): string { return 'Send only'; }

    /** Set up when a mail provider is chosen. */
    public function configured(): bool
    {
        return Mail::on();
    }

    /** Nothing: the email page has its own steps. */
    public function setup(): string
    {
        return '';
    }

    /** Not set up, not finished, a recent send failure (its error text, plain), or connected. */
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
