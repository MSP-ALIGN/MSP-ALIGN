<?php
declare(strict_types=1);

namespace Align\Contracts;

use Align\Fmt;

/**
 * Contracts as HTML: the builder and prepare previews, the signing page (with inputs for what the client fills in)
 * and the read-only signed view. The PDF (PdfRender) prints the same blocks.
 *
 * Modes: 'preview' (empty values show as labelled chips), 'sign' (client fields are inputs), 'view' (as signed).
 *
 * Security assumptions: the output goes straight into pages (the signing page included), so everything here is
 * escaped: values, labels and template text with e(); the wording is the def's Html::clean output (cleaned again on
 * every load by Template::normalize) and placeholders are filled only between its tags; style values reach CSS
 * only after normStyle limited them (colour #rrggbb, size an integer, font a fixed word). The PDF viewer's data is
 * JSON with every HTML-special character escaped. The caller decides who may see the contract.
 */
final class Render
{
    /** A stand-in contract so a template can be previewed without a client. */
    public static function sample(array $def): array
    {
        // Made-up counts for the quantities Align would count for a client
        $counts = ['workstations' => 12, 'desktops' => 8, 'laptops' => 4, 'users' => 14, 'license_seats' => 14, 'm365_users' => 14, 'firewalls' => 1]
            + array_fill_keys(array_keys(Template::AUTO), 2);
        $vals = Contracts::initialVals($def, $counts);
        return [
            'id' => 42, 'source' => 'built', 'status' => 'draft', 'title' => $def['style']['title'] ?: 'Agreement', 'client_id' => null, 'client_name' => null,
            'lead_company' => 'Example Client, Inc.', 'lead_address' => '100 Main Street, Springfield', 'lead_phone' => '(555) 010-0100',
            'signer_name' => 'Alex Rivera', 'signer_title' => 'Office Manager', 'signer_email' => 'alex@example.com',
            'sent_at' => null, 'client_signed_at' => null, 'provider_signed_at' => null, 'completed_at' => null,
            'def' => $def, 'vals' => $vals, 'client_sig' => null, 'provider_sig' => null, 'verify_code' => 1, 'provider_name' => null,
        ];
    }

    /**
     * The contract as HTML for $mode ('preview', 'sign' or 'view'; see the class comment). $me: the staff member
     * looking (for their own signature preview on a PDF), null on the signing page. Safe to echo as is.
     */
    public static function html(array $c, string $mode = 'preview', ?array $me = null): string
    {
        if (!empty($c['def']['pdf'])) {
            return self::pdfView($c, $mode, $me);
        }
        $def = $c['def'];
        $st = $def['style'];
        $vals = Contracts::values($c);
        $accent = $st['color'] ?: \Align\Branding::color();
        $out = '<div class="contract-doc cd-' . $st['font'] . '" style="--cd-accent: ' . e($accent) . '; --cd-size: ' . (int) $st['size'] . 'pt">';
        $head = '';
        // A contract is a white page: the light mode logo, else the dark mode one (2.2.4)
        if ($st['logo'] && \Align\Branding::anyLogo()) {
            $head .= '<img class="cd-logo" src="' . e(\Align\Branding::lightLogoUrl()) . '" alt="">';
        }
        if ($st['header'] !== '') {
            $head .= '<div class="cd-header-text">' . e(Contracts::fillText($st['header'], $vals)) . '</div>';
        }
        if ($head !== '') {
            $out .= '<div class="cd-head">' . $head . '</div>';
        }
        if ($st['title'] !== '') {
            $out .= '<h1 class="cd-title">' . e(Contracts::fillText($st['title'], $vals)) . '</h1>';
        }
        $out .= '<div class="cd-meta">' . e(Contracts::number($c) . ($vals['start_date'] !== '' ? ' · Starts ' . $vals['start_date'] : '')) . '</div>';
        foreach (Contracts::blocks($def, $c['vals']) as $b) {
            $out .= match ($b['type']) {
                'text' => '<div class="cd-text">' . self::text($b['html'], $c, $vals, $mode) . '</div>',
                'services' => self::services($c),
                'fields' => self::fieldList($b, $c, $vals, $mode),
                'signatures' => self::signatures($c, $vals, $mode),
                'page_break' => '<div class="cd-pagebreak" aria-hidden="true"><span>Page break</span></div>',
                default => '',
            };
        }
        if ($st['footer'] !== '') {
            $out .= '<div class="cd-foot">' . e(Contracts::fillText($st['footer'], $vals)) . '</div>';
        }
        return $out . '</div>';
    }

    /**
     * A contract on the MSP's own PDF: the pages are drawn in the browser (pdfview.js, PDF.js) from $c['_src'], with
     * the boxes over them. Without JavaScript there's a link to the draft PDF.
     */
    public static function pdfView(array $c, string $mode, ?array $me = null): string
    {
        $items = PdfStamp::items($c, $mode, $me);
        $pages = $c['def']['pdf']['pages'];
        return '<div class="pv" data-pv data-mode="' . e($mode) . '" data-src="' . e((string) ($c['_src'] ?? '')) . '" data-v="' . e(APP_VERSION) . '">'
            . '<script type="application/json" class="pv-data">' . json_encode(['items' => $items, 'pages' => $pages],
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . '</script>'
            . '<div class="pv-pages"><div class="pv-loading text-muted small p-3"><i class="fas fa-spinner fa-spin me-1"></i>Loading the pages…</div></div></div>';
    }

    /** One placeholder: its value, a chip when empty (preview), or an input (client field, sign mode). */
    private static function value(string $key, array $c, array $vals, string $mode, bool $block = false): string
    {
        $def = $c['def'];
        $f = Template::field($def, $key);
        if ($f && $f['by'] === 'client' && $mode === 'sign') {
            return self::input($f, (string) ($c['vals']['f'][$key] ?? ''), $block);
        }
        $v = $vals[$key] ?? null;
        if ($v === null) {
            return $mode === 'preview' ? '<span class="cf-chip cf-unknown" title="Not a field">{{' . e($key) . '}}</span>' : '';
        }
        if ($v === '' && $key === 'signed_date' && $mode === 'sign') {
            return '<span class="cf-auto" title="Filled in automatically: the date you sign">' . e(\Align\Fmt::date(date('Y-m-d'), 'long')) . '</span>';
        }
        if ($v === '') {
            if ($mode !== 'preview') { // the signer sees a blank, not a "fill this in" box
                return '<span class="cf-blank">—</span>';
            }
            $who = $f && $f['by'] === 'client' ? ' cf-client' : '';
            return '<span class="cf-chip' . $who . '" title="' . ($f && $f['by'] === 'client' ? 'The client fills this in' : 'Filled in before sending') . '">' . e(Template::placeLabel($def, $key)) . '</span>';
        }
        $s = e($v);
        if ($f && $f['type'] === 'longtext') {
            $s = nl2br($s);
        }
        return '<span class="cf-val">' . $s . '</span>';
    }

    /**
     * The input for one of the client's fields on the signing page (part of #sign-form). The key matched
     * Template::KEY, so the name f[key] needs no more than e(); $v may be what the signer typed before an error.
     * The browser limits (maxlength, type) are for convenience only: Contracts::clientSign checks again.
     */
    private static function input(array $f, string $v, bool $block): string
    {
        $n = 'f[' . e($f['key']) . ']';
        $req = $f['required'] ? ' required' : '';
        $attr = ' class="cf-input" name="' . $n . '" form="sign-form" aria-label="' . e($f['label']) . '" title="' . e($f['label'] . ($f['help'] ? ' — ' . $f['help'] : '')) . '"' . $req . ' data-cf';
        $ph = ' placeholder="' . e($f['label']) . '"';
        return match ($f['type']) {
            'longtext' => '<textarea' . $attr . $ph . ' rows="3" maxlength="4000">' . e($v) . '</textarea>',
            'date' => '<input type="date"' . $attr . ' value="' . e($v) . '">',
            'email' => '<input type="email"' . $attr . $ph . ' value="' . e($v) . '" maxlength="190">',
            'phone' => '<input type="tel"' . $attr . $ph . ' value="' . e($v) . '" maxlength="60">',
            'number', 'money' => '<input type="number" step="any" min="0"' . $attr . $ph . ' value="' . e($v) . '">',
            'choice' => '<select' . $attr . '><option value="">' . e($f['label']) . '…</option>' . implode('', array_map(fn($o) => '<option value="' . e($o) . '"' . ($o === $v ? ' selected' : '') . '>' . e($o) . '</option>', $f['options'])) . '</select>',
            'checkbox' => '<label class="cf-check"><input type="checkbox" value="1"' . $attr . ($v === '1' ? ' checked' : '') . '> ' . e($f['label']) . '</label>',
            'initials' => '<input type="text"' . $attr . ' placeholder="Initials" value="' . e($v) . '" maxlength="6" data-initials size="5">',
            default => '<input type="text"' . $attr . $ph . ' value="' . e($v) . '" maxlength="500"' . ($block ? '' : ' size="' . max(8, min(40, mb_strlen($f['label']) + 4)) . '"') . '>',
        };
    }

    /**
     * Placeholders in the text are filled in (only between tags: the cleaned wording never has one inside a tag).
     * Relies on $html being Html::clean output, where a ">" inside an attribute is written as &gt;, so the split on
     * tags can't put a value inside one.
     */
    private static function text(string $html, array $c, array $vals, string $mode): string
    {
        $out = '';
        foreach (preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $part) {
            $out .= $i % 2 ? $part : (preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]{0,39})\s*\}\}/', fn($m) => self::value($m[1], $c, $vals, $mode), $part) ?? $part);
        }
        return $out;
    }

    /** A "fields" block: each listed key with its label and value (or input, for the client when signing). */
    private static function fieldList(array $b, array $c, array $vals, string $mode): string
    {
        if (!$b['keys']) {
            return $mode === 'preview' ? '<div class="cd-fields text-muted small">No fields chosen for this list.</div>' : '';
        }
        $out = '<div class="cd-fields">' . ($b['title'] !== '' ? '<h3>' . e($b['title']) . '</h3>' : '') . '<dl>';
        foreach ($b['keys'] as $k) {
            $f = Template::field($c['def'], $k);
            $req = $f && $f['required'] && $f['by'] === 'client' && $mode === 'sign' ? ' <span class="text-danger" aria-hidden="true">*</span>' : '';
            $out .= '<dt>' . e(Template::placeLabel($c['def'], $k)) . $req . '</dt><dd>' . self::value($k, $c, $vals, $mode, true) . '</dd>';
        }
        return $out . '</dl></div>';
    }

    /** A quantity as printed: whole numbers without decimals, others with 2. */
    public static function qty(float $q): string
    {
        return Fmt::number($q, fmod($q, 1.0) ? 2 : 0);
    }

    /** The services table with the lines that are on and the totals per period (amounts as sent, labels escaped). */
    private static function services(array $c): string
    {
        $def = $c['def'];
        $t = Contracts::totals($def, $c['vals']);
        $out = '<div class="cd-services"><h3>' . e($def['services']['title']) . '</h3><table><thead><tr><th>Service</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Total</th></tr></thead><tbody>';
        if (!$t['lines']) {
            $out .= '<tr><td colspan="4" class="cf-blank">No services listed.</td></tr>';
        }
        foreach ($t['lines'] as $l) {
            $out .= '<tr><td><b>' . e($l['label']) . '</b>' . ($l['description'] !== '' ? '<div class="cd-desc">' . e($l['description']) . '</div>' : '') . '</td>'
                . '<td class="num">' . e(self::qty($l['qty'])) . ($l['unit'] !== '' ? ' <span class="cd-desc">' . e($l['unit']) . '</span>' : '') . '</td>'
                . '<td class="num">' . e(Fmt::money($l['price'], true)) . '<span class="cd-desc"> ' . e(Template::PERIOD_SHORT[$l['period']]) . '</span></td>'
                . '<td class="num">' . e(Fmt::money($l['total'], true)) . '</td></tr>';
        }
        $out .= '</tbody><tfoot>';
        foreach (['month' => 'Monthly total', 'year' => 'Yearly total', 'once' => 'One-time total'] as $p => $label) {
            if ($t['sum'][$p] > 0 || ($p === 'month' && !array_filter($t['sum']))) {
                $out .= '<tr><td colspan="3">' . $label . '</td><td class="num">' . e(Fmt::money($t['sum'][$p], true)) . '</td></tr>';
            }
        }
        return $out . '</tfoot></table></div>';
    }

    /** Who signs: [party label, company, signature, signed at, name, title, side]. */
    public static function signers(array $c, array $vals): array
    {
        $out = [];
        if ($c['def']['signing']['countersign'] !== 'none') {
            $ps = $c['provider_sig'];
            $out[] = ['side' => 'provider', 'label' => 'Provider', 'company' => $vals['company_name'], 'sig' => $ps, 'at' => $c['provider_signed_at'],
                'name' => $ps['name'] ?? ($c['provider_name'] ?? ''), 'title' => $ps['title'] ?? ''];
        }
        $cs = $c['client_sig'];
        $out[] = ['side' => 'client', 'label' => 'Client', 'company' => $vals['client_name'], 'sig' => $cs, 'at' => $c['client_signed_at'],
            'name' => $cs['name'] ?? (string) $c['signer_name'], 'title' => $cs['title'] ?? (string) $c['signer_title']];
        return $out;
    }

    /**
     * The signature boxes. A drawn signature is the PNG Contracts::signature() re-encoded (base64 in a data: URL),
     * a photo the JPEG Contracts::photo() made; both only ever come from the stored contract.
     */
    private static function signatures(array $c, array $vals, string $mode): string
    {
        $out = '<div class="cd-signatures">';
        foreach (self::signers($c, $vals) as $s) {
            $box = '';
            if ($s['sig']) {
                $box = $s['sig']['kind'] === 'drawn'
                    ? '<img class="cd-sig-img" alt="Signature of ' . e($s['sig']['name']) . '" src="data:image/png;base64,' . e($s['sig']['png']) . '">'
                    : '<span class="cd-sig-typed">' . e($s['sig']['text']) . '</span>';
            } elseif ($mode === 'sign' && $s['side'] === 'client') {
                $box = '<a href="#sign" class="cd-sig-here">Sign below <i class="fas fa-arrow-down" aria-hidden="true"></i></a>';
            } else {
                $box = '<span class="cd-sig-empty">' . ($s['side'] === 'client' ? 'Client signature' : 'Provider signature') . '</span>';
            }
            $photo = !empty($s['sig']['photo']) ? '<img class="cd-sig-photo" alt="" src="data:image/jpeg;base64,' . e($s['sig']['photo']) . '">' : '';
            $out .= '<div class="cd-sig"><div class="cd-sig-label">' . e($s['label']) . ': <b>' . e($s['company']) . '</b></div>'
                . '<div class="cd-sig-box">' . $photo . $box . '</div>'
                . '<div class="cd-sig-line">' . e($s['name'] ?: 'Name') . ($s['title'] ? ', ' . e($s['title']) : '') . '</div>'
                . '<div class="cd-sig-date">' . ($s['at'] ? 'Signed electronically ' . e(Fmt::dateTime($s['at'])) : 'Date') . '</div></div>';
        }
        return $out . '</div>';
    }
}
