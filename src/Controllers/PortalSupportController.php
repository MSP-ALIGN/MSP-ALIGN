<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Portal\PortalAuth;
use Align\Portal\Support;

/**
 * 2.35.0 The PSA's tickets, quotes and shared documents in the client portal (ITFlow; see Portal\Support).
 *
 * Security assumptions: the router checked CSRF on the POST. Each handler starts with PortalAuth::require() for its
 * section and then needs the PSA's portal features (needPsa: Support::available()); ids from the URL are untrusted and only
 * ever looked up through Support, which checks them against the user's own client (and, for tickets, that the user may
 * see that ticket). The PSA's guest pages are reached by a redirect from here, after those checks, so their secret
 * keys are never printed in a page; the redirect target is built by the ITFlow client from the saved https:// address.
 * A PSA that can't be reached shows a short message, never its error text.
 */
final class PortalSupportController
{
    /** After PortalAuth::require(): a 404 page unless the PSA has the portal features (the section doesn't exist without them). */
    private static function needPsa(array $pu): void
    {
        if (!Support::available()) {
            self::notFound($pu, 'This page isn\'t available.');
        }
    }

    /** A 404 page in the portal layout, then stops. */
    private static function notFound(array $pu, string $message): never
    {
        http_response_code(404);
        PortalController::render('error', ['title' => 'Not found', 'message' => $message], $pu);
        exit;
    }

    /** A "couldn't reach your IT team's system" page (503), then stops. The PSA's error text isn't shown. */
    private static function unreachable(array $pu, \Throwable $e, string $nav): never
    {
        error_log('MSP Align portal: PSA read failed: ' . $e->getMessage());
        http_response_code(503);
        PortalController::render('error', ['title' => 'Not available right now', 'nav' => $nav,
            'message' => 'Your IT team\'s system didn\'t answer just now. Please try again in a few minutes, or call them.'], $pu);
        exit;
    }

    /** Tickets (can_tickets): open ones, or ?state=closed (last 12 months); the form to open one. */
    public static function tickets(): void
    {
        $pu = PortalAuth::require('can_tickets');
        self::needPsa($pu);
        $state = query('state') === 'closed' ? 'closed' : 'open';
        PortalController::render('tickets', ['title' => 'Tickets', 'nav' => 'tickets', 'state' => $state, 'tickets' => Support::tickets($pu, $state),
            'canOpen' => Support::canOpen(), 'linked' => Support::clientPsaId($pu) !== ''], $pu);
    }

    /** Opens a ticket from the portal form (can_tickets). Subject, details and priority are untrusted. */
    public static function open(): void
    {
        $pu = PortalAuth::require('can_tickets');
        self::needPsa($pu);
        try {
            $r = Support::open($pu, post('subject'), post('details'), post('priority'));
        } catch (\Throwable $e) {
            error_log('MSP Align portal: ticket not opened: ' . $e->getMessage());
            $r = 'Your ticket couldn\'t be sent just now. Please try again, or email or call your IT team.';
        }
        if (is_string($r)) {
            flash('error', $r);
            $_SESSION['ticket_draft'] = ['subject' => mb_substr(post('subject'), 0, 200), 'details' => mb_substr(post('details'), 0, 10000), 'priority' => post('priority')];
            redirect('/portal/tickets#new');
        }
        unset($_SESSION['ticket_draft']);
        Audit::log('portal.ticket_opened', "{$pu['client_name']}: ticket " . ($r['number'] ?: $r['id']), null, (int) $pu['id']);
        flash('success', 'Ticket ' . ($r['number'] ?: '') . ' sent to your IT team. You\'ll get their replies by email, and they show here.');
        redirect('/portal/tickets/' . rawurlencode($r['id']));
    }

    /** One ticket with its replies (can_tickets; own unless can_all_tickets). */
    public static function ticket(int $id): void
    {
        $pu = PortalAuth::require('can_tickets');
        self::needPsa($pu);
        try {
            $t = Support::ticket($pu, (string) $id);
        } catch (\Throwable $e) {
            self::unreachable($pu, $e, 'tickets');
        }
        if (!$t) {
            self::notFound($pu, 'That ticket isn\'t available.');
        }
        Audit::access('portal_ticket', $pu['client_name'] . ': ' . $t['number']);
        PortalController::render('ticket', ['title' => 'Ticket ' . $t['number'], 'nav' => 'tickets', 't' => $t], $pu);
    }

    /** Sends the user to the PSA's own page for the ticket, to reply or close it (same checks as viewing it). */
    public static function reply(int $id): void
    {
        $pu = PortalAuth::require('can_tickets');
        self::needPsa($pu);
        try {
            $t = Support::ticket($pu, (string) $id);
        } catch (\Throwable $e) {
            self::unreachable($pu, $e, 'tickets');
        }
        if (!$t || !$t['guest_url']) {
            self::notFound($pu, 'That ticket can\'t be answered here. Reply to the email your IT team sent about it.');
        }
        Audit::log('portal.ticket_reply_opened', "{$pu['client_name']}: ticket {$t['number']}", null, (int) $pu['id']);
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $t['guest_url'], true, 303);
        exit;
    }

    /** Quotes sent to the company (can_quotes). */
    public static function quotes(): void
    {
        $pu = PortalAuth::require('can_quotes');
        self::needPsa($pu);
        PortalController::render('quotes', ['title' => 'Quotes', 'nav' => 'quotes', 'quotes' => Support::quotes($pu)], $pu);
    }

    /** Sends the user to the PSA's own page for the quote, to read, accept or decline it (can_quotes). */
    public static function quote(int $id): void
    {
        $pu = PortalAuth::require('can_quotes');
        self::needPsa($pu);
        try {
            $q = Support::quote($pu, (string) $id);
        } catch (\Throwable $e) {
            self::unreachable($pu, $e, 'quotes');
        }
        if (!$q || !$q['guest_url']) {
            self::notFound($pu, 'That quote isn\'t available. Ask your IT team to send it again.');
        }
        Audit::log('portal.quote_opened', "{$pu['client_name']}: quote {$q['number']}", null, (int) $pu['id']);
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $q['guest_url'], true, 303);
        exit;
    }

    /** A document the PSA shares with the company (can_documents and can_psa_docs), read live. */
    public static function document(int $id): void
    {
        $pu = PortalAuth::require('can_documents');
        self::needPsa($pu);
        if (empty($pu['can_psa_docs'])) {
            self::notFound($pu, 'That document isn\'t available.');
        }
        try {
            $d = Support::document($pu, (string) $id);
        } catch (\Throwable $e) {
            self::unreachable($pu, $e, 'documents');
        }
        if (!$d) {
            self::notFound($pu, 'That document isn\'t available.');
        }
        Audit::log('portal.document_view', $d['name'] . ' (' . psa_name() . ')', null, (int) $pu['id']);
        PortalController::render('shared_document', ['title' => $d['name'], 'nav' => 'documents', 'd' => $d], $pu);
    }
}
