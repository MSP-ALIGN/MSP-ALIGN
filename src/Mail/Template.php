<?php
declare(strict_types=1);

namespace Align\Mail;

use Align\Settings;

/**
 * Branded HTML email built from simple blocks. Everything passed in as text is escaped;
 * only the builders here produce markup. Inline styles only (email clients ignore <style>).
 * Security: callers pass plain text (client names, titles, what a client typed) and get HTML back; a block is
 * only ever the output of these helpers (or markup the caller built with e()). Link targets must be http(s) or
 * mailto, anything else becomes "#". The brand colour is validated by Branding::color().
 */
final class Template
{
    /**
     * The whole email: logo or company name, heading, blocks, and a footer with $footerNote and the company's
     * contact details (all escaped).
     * @param list<string> $blocks built with the helpers below
     */
    public static function render(string $heading, array $blocks, string $footerNote = ''): string
    {
        $brand = \Align\Branding::color();
        $company = Settings::get('company_name') ?: 'Your company';
        $contact = implode(' · ', array_filter([Settings::get('company_phone'), Settings::get('company_email'), Settings::get('company_website')]));
        $logo = self::logo() ? '<img src="cid:brandlogo" alt="' . e($company) . '" style="max-height:40px;max-width:200px;display:block">' : '<b style="font-size:18px;color:#1f2d3d">' . e($company) . '</b>';
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
            . '<body style="margin:0;padding:0;background:#f1f3f6;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2d3d">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f3f6;padding:24px 12px"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e3e7ec">'
            . '<tr><td style="padding:18px 24px;border-bottom:3px solid ' . e($brand) . '">' . $logo . '</td></tr>'
            . '<tr><td style="padding:24px 24px 8px"><h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#1f2d3d">' . e($heading) . '</h1>' . implode('', $blocks) . '</td></tr>'
            . '<tr><td style="padding:16px 24px 20px;border-top:1px solid #eef1f4;font-size:12px;color:#7b8594;line-height:1.5">'
            . ($footerNote !== '' ? e($footerNote) . '<br>' : '') . e($company) . ($contact !== '' ? ' · ' . e($contact) : '') . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** The brand logo as an inline attachment (PNG/JPG/GIF under 300 KB; Outlook can't show WebP). The file was re-encoded on upload. */
    public static function logo(): ?array
    {
        $f = \Align\Branding::logoFile();
        if (!$f || filesize($f) > 300 * 1024 || !preg_match('/\.(png|jpg|gif)$/', $f, $m)) {
            return null;
        }
        return ['name' => 'logo.' . $m[1], 'type' => ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif'][$m[1]], 'path' => $f, 'inline_id' => 'brandlogo'];
    }

    /** A paragraph of plain text (line breaks kept). */
    public static function p(string $text, bool $muted = false): string
    {
        return '<p style="margin:0 0 12px;font-size:14px;line-height:1.55;color:' . ($muted ? '#5b6573' : '#1f2d3d') . '">' . nl2br(e($text)) . '</p>';
    }

    /** A button link. $url is normally one of ours (Notifications::url()); see safeUrl(). */
    public static function button(string $label, string $url): string
    {
        $brand = \Align\Branding::color();
        return '<p style="margin:16px 0 18px"><a href="' . e(self::safeUrl($url)) . '" style="background:' . e($brand) . ';color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:5px;font-weight:600;font-size:14px;display:inline-block">' . e($label) . '</a></p>';
    }

    /** A small section heading. */
    public static function h2(string $text): string
    {
        return '<h2 style="margin:18px 0 8px;font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:#5b6573">' . e($text) . '</h2>';
    }

    /** Key/value rows (plain text; empty values are left out). */
    public static function facts(array $rows): string
    {
        $h = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 14px;font-size:14px">';
        foreach ($rows as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $h .= '<tr><td style="padding:3px 16px 3px 0;color:#5b6573;vertical-align:top;white-space:nowrap">' . e((string) $k) . '</td><td style="padding:3px 0">' . nl2br(e((string) $v)) . '</td></tr>';
        }
        return $h . '</table>';
    }

    /** Bulleted list; each item [text, tone] where tone is bad|warn|ok|'' (a coloured dot; unknown tones are grey). */
    public static function items(array $items): string
    {
        $c = ['bad' => '#e5534b', 'warn' => '#f0b429', 'ok' => '#3fb67a', '' => '#9aa4b2'];
        $h = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 14px;font-size:14px;line-height:1.45">';
        foreach ($items as $it) {
            [$text, $tone] = is_array($it) ? [$it[0], $it[1] ?? ''] : [$it, ''];
            $h .= '<tr><td style="padding:3px 8px 3px 0;vertical-align:top"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . ($c[$tone] ?? $c['']) . '"></span></td><td style="padding:2px 0">' . e((string) $text) . '</td></tr>';
        }
        return $h . '</table>';
    }

    /** Simple table: $head list of labels, $rows list of lists (text, escaped). */
    public static function table(array $head, array $rows): string
    {
        $h = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 14px;font-size:13px;border-collapse:collapse">';
        $h .= '<tr>' . implode('', array_map(fn($x) => '<th align="left" style="padding:6px 8px;border-bottom:2px solid #e3e7ec;color:#5b6573;font-size:11px;text-transform:uppercase;letter-spacing:.04em">' . e($x) . '</th>', $head)) . '</tr>';
        foreach ($rows as $r) {
            $h .= '<tr>' . implode('', array_map(fn($x) => '<td style="padding:6px 8px;border-bottom:1px solid #eef1f4;vertical-align:top">' . e((string) $x) . '</td>', $r)) . '</tr>';
        }
        return $h . '</table>';
    }

    /** A text link. See safeUrl(). */
    public static function link(string $label, string $url): string
    {
        return '<p style="margin:0 0 12px;font-size:14px"><a href="' . e(self::safeUrl($url)) . '" style="color:' . e(\Align\Branding::color()) . '">' . e($label) . '</a></p>';
    }

    /**
     * $url when it is an http(s) or mailto link, else "#". e() alone would keep a javascript: or data: link working
     * in mail apps and webmail that run it (2.2.1).
     */
    private static function safeUrl(string $url): string
    {
        $url = trim($url);
        return preg_match('#^(https?://|mailto:)#i', $url) ? $url : '#';
    }
}
