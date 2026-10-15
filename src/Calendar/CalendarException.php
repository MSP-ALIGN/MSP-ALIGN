<?php
declare(strict_types=1);

namespace Align\Calendar;

/**
 * 2.23.0 A failed call to Microsoft for a staff calendar (Outlook), with a message the person can act on: $status
 * is the HTTP status (0 when none), $oauthError the sign-in error code ('invalid_grant' and the like, '' otherwise).
 *
 * Security assumptions: the message quotes Microsoft's error text (remote: escaped where shown) and never a token.
 */
final class CalendarException extends \RuntimeException
{
    /** $status: HTTP status (0 = none); $oauthError: e.g. 'invalid_grant', '' when not an OAuth error. */
    public function __construct(string $message, public readonly int $status = 0, public readonly string $oauthError = '')
    {
        parent::__construct($message);
    }
}
