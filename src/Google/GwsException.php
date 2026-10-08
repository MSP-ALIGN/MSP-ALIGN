<?php
declare(strict_types=1);

namespace Align\Google;

use Align\Http\HttpException;

/**
 * 2.6.3 A failed Google Workspace call, with a message staff can act on and the HTTP status and Google's error code
 * (Security tells "not allowed yet" from "not in this edition" by them).
 *
 * Security assumptions: Google's answer is remote data: only its error code, reason and description are used, cut
 * to 220 characters; the service account's client id in the hint is public (it's what clients paste).
 */
final class GwsException extends \RuntimeException
{
    /** $status: HTTP status (0 when none); $reason: Google's error code or reason, e.g. unauthorized_client. */
    public function __construct(string $message, public readonly int $status = 0, public readonly string $reason = '')
    {
        parent::__construct($message);
    }

    /** A failed request in words: what to fix for the common cases (delegation missing, API off, wrong admin). */
    public static function from(HttpException $e, array $sa, string $admin): self
    {
        $j = json_decode($e->body, true) ?: [];
        $err = is_string($j['error'] ?? null) ? $j['error'] : '';
        $desc = (string) ($j['error_description'] ?? ($j['error']['message'] ?? ''));
        $reason = (string) ($j['error']['errors'][0]['reason'] ?? ($j['error']['status'] ?? $err));
        $hint = match (true) {
            $err === 'unauthorized_client' => 'The client hasn\'t allowed Align\'s service account yet (or not every scope): in their Google Admin console → Security → Access and data control → API controls → Domain-wide delegation, add client ID '
                . $sa['client_id'] . ' with the scopes shown on this page. It can take a few minutes to apply.',
            $err === 'invalid_grant' && (str_contains($desc, 'Invalid email') || str_contains($desc, 'not found')) => 'Google doesn\'t know ' . $admin . ' as a user in this domain. Use one of the client\'s super admin accounts.',
            $err === 'invalid_grant' => 'Google refused the service account\'s sign-in. Check the server clock and that the key hasn\'t been deleted in Google Cloud.',
            in_array($reason, ['accessNotConfigured', 'SERVICE_DISABLED'], true) || str_contains($desc, 'has not been used in project') || str_contains($desc, 'is disabled')
                => 'An API isn\'t turned on in the service account\'s Google Cloud project. Turn on Admin SDK API, Enterprise License Manager API and Cloud Identity API under APIs & Services → Library.',
            $e->status === 403 && in_array($reason, ['forbidden', 'PERMISSION_DENIED', 'insufficientPermissions', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'], true)
                => 'Not allowed: ' . $admin . ' may not be a super admin, or a scope is missing from the domain-wide delegation.',
            $e->status === 429 => 'Google is limiting requests. Align tries again on the next sync.',
            default => '',
        };
        $detail = trim($desc);
        return new self(trim(($hint ?: 'Google returned an error') . ($detail !== '' ? ' (' . mb_strimwidth($detail, 0, 220, '…') . ')' : " (HTTP {$e->status})")),
            $e->status, mb_substr($reason, 0, 80));
    }
}
