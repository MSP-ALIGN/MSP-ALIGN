<?php
declare(strict_types=1);

namespace Align\Mail;

/**
 * A Microsoft Graph call failed; the message is already written for an admin. Also the error type of the
 * Google and SMTP senders. The code tells the mail queue what to do (see Smtp and Mailer::deliver()):
 * 400/404 = fail this message for good, 401/403/503 = stop the run, anything else = retry later.
 * Security: the message is shown to admins and kept in the email log, so it never holds a secret or token.
 */
final class GraphException extends \RuntimeException
{
}
