<?php
declare(strict_types=1);

namespace Align\M365;

use Align\Http\HttpException;

/**
 * 2.6.0 A failed call to Microsoft for a client tenant, with a message an admin can act on.
 * $status is the HTTP status (0 when no answer); $oauthError the OAuth error code from the login endpoint
 * (authorization_pending and the like, which the device code sign-in needs to tell apart).
 *
 * Security assumptions: the message quotes Microsoft's error description (remote text: escaped where shown, cut to
 * 300 characters) and never a token, secret or request body.
 */
final class M365Exception extends \RuntimeException
{
    /** $status: HTTP status (0 = none); $oauthError: e.g. 'authorization_pending', '' when not an OAuth error. */
    public function __construct(string $message, public readonly int $status = 0, public readonly string $oauthError = '')
    {
        parent::__construct($message);
    }

    /** From an HttpException: Microsoft's error with a hint for the usual causes. */
    public static function from(HttpException $e): self
    {
        $j = json_decode((string) $e->body, true) ?: [];
        $desc = (string) ($j['error_description'] ?? ($j['error']['message'] ?? ''));
        $code = is_string($j['error'] ?? null) ? $j['error'] : (string) ($j['error']['code'] ?? '');
        $aad = preg_match('/AADSTS(\d+)/', $desc, $m) ? (int) $m[1] : 0;
        $hint = match (true) {
            $aad === 700016 => 'The client hasn\'t approved the app in their tenant, or removed it. Connect the client again.',
            in_array($aad, [65001, 65004, 650051], true) => 'The app\'s permissions haven\'t been approved in this tenant. Connect again and accept as an admin.',
            $aad === 7000222 => 'The client secret has expired. Create a new one and save it in Align.',
            $aad === 7000215 => 'The client secret is wrong. Copy its Value (not its Secret ID).',
            in_array($aad, [90002, 900023, 90013], true) => 'The tenant wasn\'t found.',
            in_array($aad, [700027, 700024, 50012], true) => 'Microsoft rejected the app\'s certificate. Set the app up again under Integrations → Microsoft 365 (clients).',
            in_array($aad, [50020, 50076, 50079, 53003], true) => 'Sign in with an admin account of that tenant (and finish its MFA).',
            in_array($code, ['Authorization_RequestDenied', 'Forbidden', 'AccessDenied'], true) || $e->status === 403 => 'Access denied: the app\'s permissions aren\'t granted in this tenant, or the account isn\'t an admin.',
            $e->status === 429 => 'Microsoft is throttling requests; it will be tried again on the next sync.',
            default => '',
        };
        $text = $hint !== '' ? $hint : 'Microsoft answered: ' . ($desc !== '' ? mb_strimwidth(preg_replace('/\s+Trace ID:.*$/s', '', $desc) ?? $desc, 0, 300, '…') : ($code ?: 'HTTP ' . $e->status));
        return new self($text, (int) $e->status, $code);
    }
}
