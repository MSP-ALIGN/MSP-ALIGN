<?php
declare(strict_types=1);

namespace Align\Providers\Psa;

/**
 * 2.35.0 A PSA whose tickets, quotes and client-shared documents the client portal shows (capability portal). Optional,
 * as PsaDomains; callers check `instanceof` and Providers::psaSupports('portal').
 *
 * SECURITY: everything returned is untrusted. Ids are the PSA's own, as text; the caller compares a record's client_id
 * with the portal user's own client's psa_id before showing anything, so an id from the URL can never show another
 * client's record. HTML (ticket details, replies, document bodies) is returned as the PSA keeps it: the caller cleans
 * it with the allowlist sanitizer (Docs\Html::clean) before printing. Replies are only the ones the client may see
 * (sent to them or written by them), never internal notes. A guest_url is the PSA's own no-sign-in page for that one
 * record (its secret key in the query): it is built here only for a record just read, and the caller sends it to a
 * signed-in portal user allowed to open it, as a redirect, never in a page.
 */
interface PsaPortal
{
    /**
     * One ticket fresh from the PSA, or null: ['id', 'client_id', 'contact_id' (or null), 'number', 'subject',
     * 'status' (the PSA's name), 'priority', 'created_at', 'updated_at', 'closed' (bool), 'details_html', 'guest_url'
     * (or null)], plus the neutral ticket fields PsaProvider::ticket() returns under 'neutral'.
     */
    public function portalTicket(string $ticketId): ?array;

    /**
     * The client-visible replies of a ticket, oldest first: ['by' (a name, may be ''), 'from_client' (bool),
     * 'at' (Y-m-d H:i:s), 'html']. Null when the PSA can't list replies (an older version).
     */
    public function portalTicketReplies(string $ticketId): ?array;

    /**
     * Every quote that has been sent to a client (drafts left out): ['id', 'client_id', 'number', 'scope', 'status',
     * 'date' (Y-m-d or null), 'expire' (Y-m-d or null), 'amount' (float or null), 'currency' (3 letters or null)].
     */
    public function portalQuotes(): array;

    /** One quote fresh from the PSA, as portalQuotes() plus 'guest_url' (or null); null when it's gone or a draft. */
    public function portalQuote(string $quoteId): ?array;

    /** The documents the PSA shares with their client: ['id', 'client_id', 'name', 'description', 'updated_at']. */
    public function portalDocuments(): array;

    /** One shared document fresh from the PSA, as portalDocuments() plus 'html'; null when it's gone or not shared. */
    public function portalDocument(string $documentId): ?array;
}
