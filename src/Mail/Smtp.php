<?php
declare(strict_types=1);

namespace Align\Mail;

use Align\Settings;

/**
 * Any SMTP server or relay (1.37): your own mail server, a relay such as SMTP2GO, Mailgun, SendGrid or
 * Amazon SES, or the Microsoft 365 / Google SMTP relay. Sends the same messages as the other connections;
 * meeting invitations always go out as .ics emails (SMTP has no calendar to put them in).
 *
 * Settings: smtp_host, smtp_port, smtp_security (starttls | tls | none), smtp_user, smtp_pass (secret),
 * smtp_verify ('0' accepts a certificate that isn't trusted, for an internal relay), mail_from.
 * Errors are GraphException (the mail queue's error type):
 *   401 / 403 = sign-in refused or a setting is wrong: stop the run, messages stay queued for when it's fixed
 *   503       = the server can't be reached: stop the run, try again later
 *   400       = the server refused this message for good (don't retry)
 *   0         = try this message again later
 */
final class Smtp
{
    public const SECURITY = ['starttls' => 'STARTTLS (usually port 587)', 'tls' => 'TLS from the start (usually port 465)', 'none' => 'None (a relay on your own network, usually port 25)'];
    private const TIMEOUT = 30;
    /** After the final "." servers may take a while (content scanning); RFC 5321 4.5.3.2.6 suggests 10 minutes. */
    private const DATA_TIMEOUT = 600;

    /** Recipients the server refused for good while the message still went to the others (for the email log). */
    public ?string $lastWarning = null;

    /** @var resource|null */
    private $sock = null;
    private array $ext = [];

    public static function ready(): bool
    {
        return self::host() !== '' && trim((string) Settings::get('mail_from')) !== ''
            && (trim((string) Settings::get('smtp_user')) === '' || Settings::hasSecret('smtp_pass')); // a user name needs its password
    }

    public static function host(): string
    {
        return trim((string) Settings::get('smtp_host'));
    }

    public static function security(): string
    {
        $s = (string) Settings::get('smtp_security', 'starttls');
        return isset(self::SECURITY[$s]) ? $s : 'starttls';
    }

    public static function port(): int
    {
        $p = (int) Settings::get('smtp_port');
        return $p >= 1 && $p <= 65535 ? $p : match (self::security()) { 'tls' => 465, 'none' => 25, default => 587 };
    }

    /** A host name or an IP address, nothing else (no scheme, path or port). */
    public static function validHost(string $h): bool
    {
        return (bool) preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?)*$/', $h)
            || filter_var(trim($h, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    private static function loopback(string $h): bool
    {
        return in_array(strtolower(trim($h, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    public function calendarLabel(): string
    {
        return 'email';
    }

    public function meetingLabel(): string
    {
        return '';
    }

    /** Same signature as Graph::sendMail. */
    public function sendMail(array $to, string $subject, string $html, array $cc = [], array $attachments = [], ?string $replyTo = null): void
    {
        $this->lastWarning = null;
        $from = Mail::fromAddress();
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new GraphException('Set the From address under Integrations → Email.', 403);
        }
        $rcpt = array_values(array_unique(array_filter(array_map(fn($r) => strtolower(trim((string) ($r['address'] ?? ''))), [...$to, ...$cc]),
            fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n<>]/', $a))));
        if (!$rcpt) {
            throw new GraphException('No valid recipient.', 400);
        }
        $raw = Mime::build($from, trim((string) Settings::get('mail_from_name')), $to, $cc, $replyTo ?? ((string) Settings::get('mail_reply_to') ?: null), $subject, $html, $attachments, true);
        try {
            $this->open();
            // A refused sender is a setting (relay not allowed, From address not permitted), not this message
            [$code, $text] = $this->send('MAIL FROM:<' . $from . '>');
            if ($code !== 250) {
                throw new GraphException("The SMTP server refused to send as $from: $code " . self::short($text) . ($code >= 500 ? ' Check the From address and that this server may relay through it.' : ''), $code >= 500 ? 403 : 0);
            }
            $accepted = 0;
            $refused = [];
            foreach ($rcpt as $a) {
                [$code, $text] = $this->send('RCPT TO:<' . $a . '>');
                if ($code === 250 || $code === 251) {
                    $accepted++;
                } elseif ($code >= 500) {
                    $refused[] = "$a ($code " . self::short($text) . ')';
                } else {
                    throw new GraphException("The SMTP server can't take mail for $a right now: $code " . self::short($text), 0);
                }
            }
            if (!$accepted) {
                throw new GraphException('The SMTP server refused every recipient: ' . implode(', ', $refused), 400);
            }
            $this->cmd('DATA', [354]);
            // CRLF line ends, and a line starting with a dot gets a second one (RFC 5321 4.5.2)
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $raw));
            $this->write(rtrim($data, "\r\n") . "\r\n.\r\n");
            stream_set_timeout($this->sock, self::DATA_TIMEOUT);
            try {
                [$code, $text] = $this->read();
            } catch (GraphException $e) {
                // It may have been delivered: sending it again automatically could send it twice
                throw new GraphException('No answer from the SMTP server after the message was sent, so it may or may not have been delivered. Check before pressing Retry.', 400);
            }
            if ($code !== 250) {
                throw new GraphException('The SMTP server didn\'t accept the message: ' . $code . ' ' . self::short($text), $code >= 500 ? 400 : 0);
            }
            if ($refused) {
                $this->lastWarning = 'Sent, but the SMTP server refused ' . implode(', ', $refused);
                error_log('[msp-align] smtp: ' . $this->lastWarning);
            }
            try {
                $this->send('QUIT');
            } catch (GraphException) {
                // the message was accepted; a server that hangs up instead of saying goodbye is fine
            }
        } finally {
            $this->close();
        }
    }

    /** Connects, says hello, starts TLS and signs in. */
    private function open(): void
    {
        $host = self::host();
        if (!self::validHost($host)) {
            throw new GraphException('The SMTP server name isn\'t valid. Set it under Integrations → Email.', 403);
        }
        $sec = self::security();
        $verify = Settings::get('smtp_verify', '1') !== '0';
        $peer = trim($host, '[]');
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify,
            'peer_name' => $peer, 'SNI_enabled' => true, 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]);
        $target = ($sec === 'tls' ? 'tls://' : 'tcp://') . (filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$peer]" : $peer) . ':' . self::port();
        $errno = 0;
        $err = '';
        $s = @stream_socket_client($target, $errno, $err, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            $tls = $sec === 'tls' && ($err === '' || stripos($err, 'ssl') !== false || stripos($err, 'certificate') !== false);
            throw new GraphException($tls ? self::tlsError($host)
                : "Couldn't connect to the SMTP server $host on port " . self::port() . ($err !== '' ? ": $err" : '') . '. Check the server name, port and firewall.', 503);
        }
        stream_set_timeout($s, self::TIMEOUT);
        $this->sock = $s;
        try {
            [$code, $text] = $this->read();
        } catch (GraphException $e) {
            throw new GraphException('The server on port ' . self::port() . ' ' . lcfirst(rtrim($e->getMessage(), '.')) . ' before saying hello. Check the port and security setting.', 503);
        }
        if ($code !== 220) {
            // Only repeat what it said if it talks SMTP (not another service's banner on that port)
            throw new GraphException("The server on port " . self::port() . ($code >= 400 && $code < 600 ? ' refused the connection: ' . $code . ' ' . self::short($text) : " doesn't answer like an SMTP server. Check the port."), 503);
        }
        $this->ehlo();
        if ($sec === 'starttls') {
            if (!isset($this->ext['STARTTLS'])) {
                throw new GraphException("The SMTP server $host doesn't offer STARTTLS. Choose \"TLS from the start\" (port 465), or \"None\" only for a relay on your own network.", 403);
            }
            $this->cmd('STARTTLS', [220]);
            if (@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                throw new GraphException(self::tlsError($host), 503);
            }
            $this->ehlo();
        }
        $user = trim((string) Settings::get('smtp_user'));
        if ($user === '') {
            return; // a relay that trusts this server's address
        }
        if ($sec === 'none' && !self::loopback($host)) {
            throw new GraphException('The SMTP password would be sent without encryption. Choose STARTTLS or TLS, or leave the user name empty for a relay that doesn\'t need a sign-in.', 403);
        }
        $pass = (string) Settings::secret('smtp_pass');
        $methods = preg_split('/\s+/', strtoupper((string) ($this->ext['AUTH'] ?? ''))) ?: [];
        if (in_array('PLAIN', $methods, true)) {
            [$code, $text] = $this->send('AUTH PLAIN ' . base64_encode("\0$user\0$pass"), true);
        } elseif (in_array('LOGIN', $methods, true)) {
            [$code, $text] = $this->send('AUTH LOGIN');
            if ($code === 334) {
                [$code, $text] = $this->send(base64_encode($user), true);
                if ($code === 334) {
                    [$code, $text] = $this->send(base64_encode($pass), true);
                }
            }
        } else {
            throw new GraphException("The SMTP server $host doesn't offer a password sign-in (AUTH PLAIN or LOGIN)" . ($methods && $methods[0] !== '' ? ': it offers ' . implode(', ', $methods) : '') . '. Leave the user name empty if it relays for this server without one.', 403);
        }
        if ($code !== 235) {
            throw new GraphException('The SMTP server refused the user name or password: ' . $code . ' ' . self::short($text), $code >= 500 ? 401 : 0);
        }
    }

    private static function tlsError(string $host): string
    {
        return "Couldn't start an encrypted connection to $host. If the server's certificate isn't trusted (an internal relay with its own certificate), switch off \"Check the server's certificate\"; otherwise check the security setting matches the port.";
    }

    private function ehlo(): void
    {
        // This server's name as the relay should see it: the address people use, an IP as [a.b.c.d]
        $name = trim((string) (parse_url(\Align\Portal\PortalAuth::baseUrl(), PHP_URL_HOST) ?: (gethostname() ?: 'localhost')), '[]');
        $name = match (true) {
            filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => "[$name]",
            filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => "[IPv6:$name]",
            (bool) preg_match('/^[A-Za-z0-9.-]+$/', $name) => $name,
            default => 'localhost',
        };
        [$code, $text] = $this->send('EHLO ' . $name);
        if ($code !== 250) {
            [$code, $text] = $this->send('HELO ' . $name);
            if ($code !== 250) {
                throw new GraphException('The SMTP server refused the greeting: ' . $code . ' ' . self::short($text), 0);
            }
            $this->ext = [];
            return;
        }
        $this->ext = [];
        foreach (array_slice(explode("\n", $text), 1) as $l) {
            $w = explode(' ', trim($l), 2);
            if ($w[0] !== '') {
                $this->ext[strtoupper($w[0])] = $w[1] ?? '';
            }
        }
    }

    /** Sends a command and requires one of the reply codes. */
    private function cmd(string $line, array $want, bool $secret = false): string
    {
        [$code, $text] = $this->send($line, $secret);
        if (!in_array($code, $want, true)) {
            $what = $secret ? 'sign-in' : strtok($line, ' :');
            throw new GraphException("The SMTP server refused $what: $code " . self::short($text), $code >= 500 ? 400 : 0);
        }
        return $text;
    }

    /** @return array{0:int,1:string} */
    private function send(string $line, bool $secret = false): array
    {
        $this->write($line . "\r\n");
        return $this->read();
    }

    private function write(string $s): void
    {
        for ($off = 0, $len = strlen($s); $off < $len; $off += $n) {
            $n = @fwrite($this->sock, substr($s, $off, 65536));
            if ($n === false || $n === 0) {
                throw new GraphException('The connection to the SMTP server was closed.', 0);
            }
        }
    }

    /** Reads a (possibly multi-line) reply. @return array{0:int,1:string} */
    private function read(): array
    {
        $lines = [];
        while (true) {
            $l = @fgets($this->sock, 4096);
            if ($l === false) {
                $meta = stream_get_meta_data($this->sock);
                throw new GraphException(!empty($meta['timed_out']) ? 'The SMTP server stopped answering.' : 'The connection to the SMTP server was closed.', 0);
            }
            $lines[] = rtrim(substr($l, 4));
            if (!preg_match('/^\d{3}-/', $l) || count($lines) > 200) {
                return [(int) substr($l, 0, 3), implode("\n", $lines)];
            }
        }
    }

    private function close(): void
    {
        if ($this->sock) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    private static function short(string $t): string
    {
        return mb_strimwidth(trim(preg_replace('/\s+/', ' ', $t) ?? ''), 0, 300, '…');
    }
}
