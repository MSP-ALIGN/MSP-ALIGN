<?php
declare(strict_types=1);

namespace Align\Contracts;

use Align\Fmt;
use Align\Pdf\Pdf;

/**
 * Prints a contract to PDF with Align's own writer (Pdf\Pdf): the header (logo, header text), the title, every
 * block (text from the editor, the services table, field lists, signatures, page breaks), a footer with page
 * numbers and the client's initials, and, for the signed copy, the signature certificate as the last page.
 */
final class PdfRender
{
    private Pdf $pdf;
    private array $c;
    private array $vals;
    private float $size;
    private string $family;
    private array $accent;
    private float $ml = 60;
    private float $mr = 60;
    private float $top;
    private float $bottom;
    private float $y = 0;
    private float $w;
    private ?int $logo = null;
    private bool $final;
    private const GRAY = [0.36, 0.40, 0.45];
    private const LIGHT = [0.85, 0.87, 0.89];
    private const TEXT = [0.11, 0.13, 0.16];

    /** The PDF bytes. $final: the signed copy (with certificate); otherwise a draft marked as such. */
    public static function build(array $c, bool $final = false): string
    {
        if (!empty($c['def']['pdf'])) {
            return PdfStamp::build($c, $final); // printed on the MSP's own PDF
        }
        $r = new self($c, $final);
        return $r->render();
    }

    /** The mark on every page of a copy that isn't signed by everyone yet. */
    public static function draftLabel(string $status): string
    {
        return $status === 'draft' ? 'DRAFT - not signed' : 'NOT YET SIGNED BY EVERYONE';
    }

    /**
     * The signature certificate as new pages at the end of $pdf (its default page size).
     * PdfStamp uses it for contracts printed on the MSP's own PDF. Header and footer go on the new pages only, so
     * the MSP's own pages are left as they are.
     */
    public static function certificateInto(Pdf $pdf, array $c): void
    {
        $r = new self($c, true, $pdf);
        $from = $pdf->pageCount();
        $r->certificate();
        $r->decorate($from, $from);
    }

    /**
     * Sets up a renderer for $c (as Contracts::load() returns it: the def normalized by Template::normalize). With
     * $into it draws into that Pdf, at its page size, instead of a new one. Sets the document info and loads the
     * logo; Branding::logoFile() only returns a file whose name matches its own pattern, never a path from input.
     */
    private function __construct(array $c, bool $final, ?Pdf $into = null)
    {
        $this->c = $c;
        $this->final = $final;
        $st = $c['def']['style'];
        [$pw, $ph] = $into ? [$into->w, $into->h] : ($st['paper'] === 'a4' ? Pdf::A4 : Pdf::LETTER);
        $this->pdf = $into ?? new Pdf($pw, $ph);
        $this->pdf->setInfo(Contracts::party($c) . ' - ' . $c['title'], (string) (\Align\Settings::get('company_name') ?: ''), Contracts::number($c));
        $this->size = (float) $st['size'];
        $this->family = $st['font'] === 'serif' ? 'Times' : 'Helvetica';
        $this->accent = Pdf::hex($st['color'] ?: \Align\Branding::color());
        $this->vals = Contracts::values($c);
        $this->w = $pw - $this->ml - $this->mr;
        if ($st['logo'] && ($f = \Align\Branding::logoFile())) {
            $this->logo = $this->pdf->addImage((string) file_get_contents($f));
        }
        $this->top = ($this->logo !== null || $st['header'] !== '' || !$final) ? 92 : 60;
        $this->bottom = $ph - 64;
    }

    /** The core font for the contract's family (Helvetica or Times), bold and/or italic. */
    private function font(bool $b = false, bool $i = false): string
    {
        if ($this->family === 'Times') {
            return $b && $i ? 'Times-BoldItalic' : ($b ? 'Times-Bold' : ($i ? 'Times-Italic' : 'Times-Roman'));
        }
        return $b && $i ? 'Helvetica-BoldOblique' : ($b ? 'Helvetica-Bold' : ($i ? 'Helvetica-Oblique' : 'Helvetica'));
    }

    /** Starts a page, with the cursor at the top margin. */
    private function newPage(): void
    {
        $this->pdf->addPage();
        $this->y = $this->top;
    }

    /** Room for $h points more on this page, else a new page. */
    private function need(float $h): void
    {
        if ($this->y + $h > $this->bottom) {
            $this->newPage();
        }
    }

    /**
     * Lays out the whole contract (title, number and start date, the blocks that print, and the certificate on the
     * signed copy), then adds headers and footers. Returns the PDF bytes. The style comes from fixed lists and the
     * text blocks are Html::clean output capped in size (Template::normalize). Every piece of text, values filled in
     * by the client included, reaches the page through Pdf::text() or textRaw(), which write it as a hex string,
     * so it can't add PDF operators.
     */
    private function render(): string
    {
        $c = $this->c;
        $st = $c['def']['style'];
        $this->newPage();
        if ($st['title'] !== '') {
            $this->paragraph([['t' => Contracts::fillText($st['title'], $this->vals), 'b' => true, 'color' => $this->accent]], $this->size * 1.75, 'left', 0, 1.2);
            $this->y -= $this->size * 0.2;
        }
        $this->paragraph([['t' => Contracts::number($c) . ($this->vals['start_date'] !== '' ? ' · Starts ' . $this->vals['start_date'] : ''), 'color' => self::GRAY]], $this->size * 0.9);
        $this->y += $this->size * 0.6;
        foreach (Contracts::blocks($c['def'], $c['vals']) as $b) {
            match ($b['type']) {
                'text' => $this->htmlBlock($b['html']),
                'services' => $this->services(),
                'fields' => $this->fieldList($b),
                'signatures' => $this->signatures(),
                'page_break' => $this->newPage(),
                default => null,
            };
        }
        $contentPages = $this->pdf->pageCount();
        if ($this->final) {
            $this->certificate();
        }
        $this->decorate($contentPages);
        return $this->pdf->output();
    }

    /** Header and footer on every page. */
    private function decorate(int $contentPages, int $from = 0): void
    {
        $st = $this->c['def']['style'];
        $n = $this->pdf->pageCount();
        $fs = $this->size * 0.8;
        $initials = $this->c['client_sig']['initials'] ?? '';
        for ($p = $from; $p < $n; $p++) {
            $this->pdf->setPage($p);
            $hy = 40;
            if ($this->logo !== null) {
                [$iw, $ih] = $this->pdf->imageSize($this->logo);
                $h = 30;
                $w = $iw / max(1, $ih) * $h;
                if ($w > 150) {
                    $w = 150;
                    $h = $ih / max(1, $iw) * $w;
                }
                $this->pdf->image($this->logo, $this->ml, $hy - 8, $w, $h);
            }
            if ($st['header'] !== '') {
                $t = Contracts::fillText($st['header'], $this->vals);
                $this->pdf->text($this->ml + $this->w - Pdf::width($t, $this->font(), $fs), $hy + 8, $t, $this->font(), $fs, self::GRAY);
            }
            if (!$this->final) {
                $d = self::draftLabel((string) ($this->c['status'] ?? 'draft'));
                $this->pdf->text($this->ml + ($this->w - Pdf::width($d, $this->font(true), $fs)) / 2, $hy + 8, $d, $this->font(true), $fs, [0.75, 0.2, 0.2]);
            }
            $fy = $this->bottom + 36;
            $this->pdf->line($this->ml, $fy - 12, $this->ml + $this->w, $fy - 12, 0.4, self::LIGHT);
            $left = $st['footer'] !== '' ? Contracts::fillText($st['footer'], $this->vals) : '';
            if ($p >= $contentPages) {
                $left = 'Signature certificate · ' . Contracts::number($this->c);
            }
            if ($left !== '') {
                $this->pdf->text($this->ml, $fy, $this->fit($left, $this->font(), $fs, $this->w * 0.55), $this->font(), $fs, self::GRAY);
            }
            if ($st['page_numbers']) {
                $t = 'Page ' . ($p + 1) . ' of ' . $n;
                $this->pdf->text($this->ml + $this->w - Pdf::width($t, $this->font(), $fs), $fy, $t, $this->font(), $fs, self::GRAY);
            }
            if ($st['initials_footer'] && $p < $contentPages) {
                $t = 'Initials: ';
                $x = $this->ml + $this->w * 0.62;
                $this->pdf->text($x, $fy, $t, $this->font(), $fs, self::GRAY);
                $x += Pdf::width($t, $this->font(), $fs);
                if ($initials !== '') {
                    $this->pdf->text($x + 2, $fy, $initials, 'Times-BoldItalic', $fs * 1.4, [0.1, 0.15, 0.4]);
                }
                $this->pdf->line($x, $fy + 2, $x + 44, $fy + 2, 0.4, self::GRAY);
            }
        }
    }

    /** Cuts text to fit a width, with an ellipsis. */
    private function fit(string $t, string $font, float $size, float $w): string
    {
        if (Pdf::width($t, $font, $size) <= $w) {
            return $t;
        }
        while (mb_strlen($t) > 1 && Pdf::width($t . '…', $font, $size) > $w) {
            $t = mb_substr($t, 0, -1);
        }
        return $t . '…';
    }

    // ---- Text layout ---------------------------------------------------------------------------------------

    /**
     * Lays out and prints a paragraph of runs [{t, b, i, u, s, color}] in the width from $indent, wrapping lines,
     * with page breaks between lines. $marker prints in the hanging indent of the first line (list items).
     */
    private function paragraph(array $runs, float $size, string $align = 'left', float $indent = 0, float $leading = 1.38, string $marker = '', float $hang = 0, ?float $width = null, ?float $x0 = null, bool $breakable = false): void
    {
        $x0 ??= $this->ml;
        $avail = ($width ?? $this->w) - $indent - $hang;
        $lines = $this->wrap($runs, $size, $avail);
        $lh = $size * $leading;
        foreach ($lines as $li => $line) {
            if ($width === null || $breakable) {
                $this->need($lh);
            }
            $base = $this->y + $size * 0.95;
            $x = $x0 + $indent + $hang;
            $lineW = array_sum(array_column($line['segs'], 'w'));
            $ws = 0;
            if ($align === 'center') {
                $x += ($avail - $lineW) / 2;
            } elseif ($align === 'right') {
                $x += $avail - $lineW;
            } elseif ($align === 'justify' && !$line['last']) {
                $spaces = 0;
                foreach ($line['segs'] as $s) {
                    $spaces += substr_count($s['bytes'], ' ');
                }
                $ws = $spaces ? min($size * 0.6, ($avail - $lineW) / $spaces) : 0;
            }
            if ($li === 0 && $marker !== '') {
                $mf = $this->font();
                $this->pdf->text($x0 + $indent + $hang - Pdf::width($marker, $mf, $size) - $size * 0.35, $base, $marker, $mf, $size, self::TEXT);
            }
            foreach ($line['segs'] as $s) {
                $adv = $this->pdf->textRaw($x, $base, $s['bytes'], $s['font'], $s['size'], $s['color'], $ws);
                if ($s['u']) {
                    $this->pdf->line($x, $base + $size * 0.15, $x + $adv, $base + $size * 0.15, max(0.4, $size * 0.05), $s['color']);
                }
                if ($s['s']) {
                    $this->pdf->line($x, $base - $size * 0.28, $x + $adv, $base - $size * 0.28, max(0.4, $size * 0.05), $s['color']);
                }
                $x += $adv;
            }
            $this->y += $lh;
        }
    }

    /** Height a paragraph would take (no printing). */
    private function measure(array $runs, float $size, float $width, float $leading = 1.38): float
    {
        return count($this->wrap($runs, $size, $width)) * $size * $leading;
    }

    /** Greedy line breaking over runs. Returns lines of segments [{bytes, font, size, color, u, s, w}]. */
    private function wrap(array $runs, float $size, float $avail): array
    {
        $words = []; // each: [bytes, style] or "\n"
        foreach ($runs as $r) {
            $font = $this->font(!empty($r['b']), !empty($r['i']));
            $sz = $r['size'] ?? $size;
            $style = ['font' => $font, 'size' => $sz, 'color' => $r['color'] ?? self::TEXT, 'u' => !empty($r['u']), 's' => !empty($r['s'])];
            foreach (preg_split('/(\n)/', (string) $r['t'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
                if ($part === "\n") {
                    $words[] = "\n";
                    continue;
                }
                foreach (preg_split('/( +)/', Pdf::encode(preg_replace('/[ \t\r]+/', ' ', $part) ?? ''), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
                    $words[] = [$tok, $style];
                }
            }
        }
        $lines = [];
        $segs = [];
        $lineW = 0;
        $flush = function (bool $last) use (&$lines, &$segs, &$lineW) {
            while ($segs && trim($segs[count($segs) - 1]['bytes']) === '') { // no trailing spaces
                $lineW -= array_pop($segs)['w'];
            }
            if ($segs && ($n = count($segs)) && str_ends_with($segs[$n - 1]['bytes'], ' ')) {
                $segs[$n - 1]['bytes'] = rtrim($segs[$n - 1]['bytes'], ' ');
                $w = Pdf::widthRaw($segs[$n - 1]['bytes'], $segs[$n - 1]['font'], $segs[$n - 1]['size']);
                $lineW -= $segs[$n - 1]['w'] - $w;
                $segs[$n - 1]['w'] = $w;
            }
            $lines[] = ['segs' => $segs, 'last' => $last];
            $segs = [];
            $lineW = 0;
        };
        $add = function (string $bytes, array $st) use (&$segs, &$lineW) {
            $w = Pdf::widthRaw($bytes, $st['font'], $st['size']);
            $n = count($segs);
            if ($n && $segs[$n - 1]['font'] === $st['font'] && $segs[$n - 1]['size'] === $st['size'] && $segs[$n - 1]['color'] === $st['color']
                && $segs[$n - 1]['u'] === $st['u'] && $segs[$n - 1]['s'] === $st['s']) {
                $segs[$n - 1]['bytes'] .= $bytes;
                $segs[$n - 1]['w'] += $w;
            } else {
                $segs[] = ['bytes' => $bytes] + $st + ['w' => $w];
            }
            $lineW += $w;
        };
        foreach ($words as $wd) {
            if ($wd === "\n") {
                $flush(true);
                continue;
            }
            [$tok, $st] = $wd;
            $tw = Pdf::widthRaw($tok, $st['font'], $st['size']);
            if (trim($tok) === '') {
                if ($segs) {
                    $add(' ', $st);
                }
                continue;
            }
            if ($lineW + $tw > $avail && $segs) {
                $flush(false); // doesn't fit: a new line (trailing space dropped)
            }
            if ($tw <= $avail) {
                $add($tok, $st);
                continue;
            }
            $chunk = ''; // wider than a whole line (a long URL or number): break it by characters
            for ($i = 0, $n = strlen($tok); $i < $n; $i++) {
                if ($chunk !== '' && Pdf::widthRaw($chunk . $tok[$i], $st['font'], $st['size']) + $lineW > $avail) {
                    $add($chunk, $st);
                    $flush(false);
                    $chunk = '';
                }
                $chunk .= $tok[$i];
            }
            $add($chunk, $st);
        }
        if ($segs || !$lines) {
            $flush(true);
        } else {
            $lines[count($lines) - 1]['last'] = true;
        }
        return $lines;
    }

    // ---- Blocks from the editor ----------------------------------------------------------------------------

    /** Placeholders as text: value, or (draft) [Label], or (signed) a dash. */
    private function fill(string $text): string
    {
        return preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]{0,39})\s*\}\}/', function ($m) {
            $v = $this->vals[$m[1]] ?? null;
            if ($v !== null && $v !== '') {
                return $v;
            }
            return $this->final ? '—' : '[' . Template::placeLabel($this->c['def'], $m[1]) . ']';
        }, $text) ?? $text;
    }

    /**
     * One text block from the editor: Quill's HTML (paragraphs, h1-h4, lists, blockquote, pre, hr, and the
     * ql-align-* and ql-indent-N classes). The HTML is expected to be Html::clean output, capped in size by Template.
     * libxml parses it only to read its structure (LIBXML_NONET, errors suppressed and the previous error mode put
     * back); nothing is fetched, <img> prints nothing and a link prints as underlined text. Placeholders are filled
     * in the parsed text nodes (runs()), so a filled-in value can't add markup.
     */
    private function htmlBlock(string $html): void
    {
        if (trim($html) === '') {
            return;
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="r">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('r');
        if (!$root) {
            return;
        }
        $s = $this->size;
        foreach ($root->childNodes as $n) {
            if ($n instanceof \DOMText) {
                if (trim($n->textContent) !== '') {
                    $this->paragraph($this->runs($n), $s);
                }
                continue;
            }
            if (!$n instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($n->tagName);
            $align = preg_match('/ql-align-(center|right|justify)/', $n->getAttribute('class'), $m) ? $m[1] : 'left';
            $indent = preg_match('/ql-indent-(\d)/', $n->getAttribute('class'), $m) ? (int) $m[1] * $s * 2.4 : 0;
            switch ($tag) {
                case 'h1': case 'h2': case 'h3': case 'h4':
                    $hs = ['h1' => 1.5, 'h2' => 1.25, 'h3' => 1.1, 'h4' => 1.0][$tag] * $s;
                    $runs = array_map(fn($r) => $r + ['b' => true], $this->runs($n));
                    $this->y += $s * 0.5;
                    $this->need($hs * 1.3 + $s * 3); // keep a heading with what follows
                    $this->paragraph($runs, $hs, $align, $indent, 1.25);
                    $this->y += $s * 0.25;
                    break;
                case 'ol': case 'ul':
                    $this->listBlock($n, $tag);
                    $this->y += $s * 0.35;
                    break;
                case 'blockquote':
                    $y0 = $this->y;
                    $page = $this->pdf->pageCount();
                    $this->paragraph($this->runs($n), $s, $align, $indent + $s * 1.4, 1.38);
                    if ($this->pdf->pageCount() === $page) {
                        $this->pdf->line($this->ml + $indent + $s * 0.4, $y0 + 2, $this->ml + $indent + $s * 0.4, $this->y - 2, 1.5, self::LIGHT);
                    }
                    $this->y += $s * 0.45;
                    break;
                case 'hr':
                    $this->need($s);
                    $this->pdf->line($this->ml, $this->y + $s * 0.4, $this->ml + $this->w, $this->y + $s * 0.4, 0.5, self::LIGHT);
                    $this->y += $s;
                    break;
                case 'pre':
                    $this->paragraph(array_map(fn($r) => $r + ['color' => self::GRAY], $this->runs($n)), $s * 0.92, 'left', $indent);
                    $this->y += $s * 0.45;
                    break;
                default: // p and anything else
                    $runs = $this->runs($n);
                    if (!$runs || trim(implode('', array_column($runs, 't'))) === '') {
                        $this->y += $s * 0.9; // an empty paragraph is a blank line
                        break;
                    }
                    $this->paragraph($runs, $s, $align, $indent);
                    $this->y += $s * 0.5;
            }
        }
    }

    /** Quill lists: one <ol> with li[data-list] and ql-indent-N classes. */
    private function listBlock(\DOMElement $list, string $tag, int $base = 0): void
    {
        $s = $this->size;
        $counters = [];
        foreach ($list->childNodes as $li) {
            if (!$li instanceof \DOMElement || strtolower($li->tagName) !== 'li') {
                continue;
            }
            $kind = $li->getAttribute('data-list') ?: ($tag === 'ul' ? 'bullet' : 'ordered');
            $level = $base + (preg_match('/ql-indent-(\d)/', $li->getAttribute('class'), $m) ? (int) $m[1] : 0);
            foreach (array_keys($counters) as $k) {
                if ($k > $level) {
                    unset($counters[$k]);
                }
            }
            if ($kind === 'ordered') {
                $counters[$level] = ($counters[$level] ?? 0) + 1;
                $nth = $counters[$level];
                $marker = match ($level % 3) { 1 => self::alpha($nth) . '.', 2 => self::roman($nth) . '.', default => $nth . '.' };
            } else {
                unset($counters[$level]);
                $marker = match ($kind) { 'checked' => '[x]', 'unchecked' => '[ ]', default => ['•', '–', '·'][$level % 3] };
            }
            $align = preg_match('/ql-align-(center|right|justify)/', $li->getAttribute('class'), $m) ? $m[1] : 'left';
            $this->paragraph($this->runs($li), $s, $align, $level * $s * 2.4, 1.38, $marker, $s * 1.8);
            $this->y += $s * 0.2;
            foreach ($li->childNodes as $sub) { // nested lists (semantic HTML) print one level in
                if ($sub instanceof \DOMElement && in_array(strtolower($sub->tagName), ['ol', 'ul'], true)) {
                    $this->listBlock($sub, strtolower($sub->tagName), $level + 1);
                }
            }
        }
    }

    /** List marker letters: 1 is "a", 26 "z", 27 "aa". */
    private static function alpha(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(97 + $n % 26) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }

    /** Lower-case Roman numerals for list markers ('' for 0 or less). */
    private static function roman(int $n): string
    {
        $map = ['m' => 1000, 'cm' => 900, 'd' => 500, 'cd' => 400, 'c' => 100, 'xc' => 90, 'l' => 50, 'xl' => 40, 'x' => 10, 'ix' => 9, 'v' => 5, 'iv' => 4, 'i' => 1];
        $s = '';
        foreach ($map as $r => $v) {
            while ($n >= $v) {
                $s .= $r;
                $n -= $v;
            }
        }
        return $s;
    }

    /** Text runs in an element: inline styles inherited, <br> as a line break, placeholders filled. */
    private function runs(\DOMNode $n, array $style = []): array
    {
        if ($n instanceof \DOMText) {
            return [['t' => $this->fill($n->textContent)] + $style];
        }
        if (!$n instanceof \DOMElement) {
            return [];
        }
        $tag = strtolower($n->tagName);
        if ($tag === 'br') {
            return [['t' => "\n"] + $style];
        }
        if (in_array($tag, ['ol', 'ul'], true)) {
            return []; // nested lists print as their own items (listBlock)
        }
        $st = $style;
        match ($tag) {
            'strong', 'b' => $st['b'] = true,
            'em', 'i' => $st['i'] = true,
            'u' => $st['u'] = true,
            's', 'strike' => $st['s'] = true,
            'a' => $st += ['u' => true, 'color' => $this->accent],
            'sub', 'sup' => $st['size'] = $this->size * 0.75,
            default => null,
        };
        if (preg_match('/(?:^|;)\s*color:\s*(#[0-9a-f]{3,6})/i', $n->getAttribute('style'), $m)) {
            $st['color'] = Pdf::hex($m[1]);
        }
        $out = [];
        foreach ($n->childNodes as $ch) {
            array_push($out, ...$this->runs($ch, $st));
        }
        return $out;
    }

    // ---- Services, fields, signatures -----------------------------------------------------------------------

    /**
     * The services table: one row per line from Contracts::totals() (name and description, quantity and unit,
     * price and period, total), then the totals. A row is never split across pages, and the header row repeats on
     * each new page. Totals of zero are left out, except the monthly one when every total is zero.
     */
    private function services(): void
    {
        $def = $this->c['def'];
        $t = Contracts::totals($def, $this->c['vals']);
        $s = $this->size;
        $this->y += $s * 0.4;
        $this->need($s * 6);
        $this->paragraph([['t' => $def['services']['title'], 'b' => true]], $s * 1.15, 'left', 0, 1.3);
        $this->y += $s * 0.2;
        $cw = [$this->w - 230, 60, 90, 80];
        $tint = array_map(fn($v) => 0.88 + 0.12 * $v, $this->accent);
        $header = function () use ($cw, $tint, $s) {
            $h = $s * 2;
            $this->pdf->rect($this->ml, $this->y, $this->w, $h, $tint);
            $x = $this->ml;
            foreach (['Service', 'Qty', 'Price', 'Total'] as $i => $label) {
                $f = $this->font(true);
                $tx = $i === 0 ? $x + 6 : $x + $cw[$i] - 6 - Pdf::width($label, $f, $s * 0.9);
                $this->pdf->text($tx, $this->y + $h * 0.66, $label, $f, $s * 0.9, self::TEXT);
                $x += $cw[$i];
            }
            $this->y += $h;
        };
        $header();
        $ds = $s * 0.85;
        foreach ($t['lines'] as $l) {
            $nameH = $this->measure([['t' => $l['label'], 'b' => true]], $s, $cw[0] - 12, 1.3);
            $descH = $l['description'] !== '' ? $this->measure([['t' => $l['description']]], $ds, $cw[0] - 12, 1.3) : 0;
            $h = max($nameH + $descH, $s * 1.3) + $s * 0.9;
            if ($this->y + $h > $this->bottom) {
                $this->newPage();
                $header();
            }
            $y0 = $this->y;
            $this->y += $s * 0.45;
            $this->paragraph([['t' => $l['label'], 'b' => true]], $s, 'left', 0, 1.3, '', 0, $cw[0] - 12, $this->ml + 6);
            if ($l['description'] !== '') {
                $this->paragraph([['t' => $l['description'], 'color' => self::GRAY]], $ds, 'left', 0, 1.3, '', 0, $cw[0] - 12, $this->ml + 6);
            }
            $base = $y0 + $s * 0.45 + $s * 0.95;
            $x = $this->ml + $cw[0];
            $qty = Render::qty($l['qty']);
            $this->pdf->text($x + $cw[1] - 6 - Pdf::width($qty, $this->font(), $s), $base, $qty, $this->font(), $s, self::TEXT);
            if ($l['unit'] !== '') {
                $this->pdf->text($x + $cw[1] - 6 - Pdf::width($l['unit'], $this->font(), $ds * 0.9), $base + $s * 1.2, $l['unit'], $this->font(), $ds * 0.9, self::GRAY);
            }
            $x += $cw[1];
            $price = Fmt::money($l['price'], true);
            $this->pdf->text($x + $cw[2] - 6 - Pdf::width($price, $this->font(), $s), $base, $price, $this->font(), $s, self::TEXT);
            $per = Template::PERIOD_SHORT[$l['period']];
            $this->pdf->text($x + $cw[2] - 6 - Pdf::width($per, $this->font(), $ds * 0.9), $base + $s * 1.2, $per, $this->font(), $ds * 0.9, self::GRAY);
            $x += $cw[2];
            $tot = Fmt::money($l['total'], true);
            $this->pdf->text($x + $cw[3] - 6 - Pdf::width($tot, $this->font(), $s), $base, $tot, $this->font(), $s, self::TEXT);
            $this->y = $y0 + $h;
            $this->pdf->line($this->ml, $this->y, $this->ml + $this->w, $this->y, 0.4, self::LIGHT);
        }
        if (!$t['lines']) {
            $this->y += $s * 0.4;
            $this->paragraph([['t' => 'No services listed.', 'i' => true, 'color' => self::GRAY]], $s);
        }
        foreach (['month' => 'Monthly total', 'year' => 'Yearly total', 'once' => 'One-time total'] as $p => $label) {
            if ($t['sum'][$p] <= 0 && !($p === 'month' && !array_filter($t['sum']))) {
                continue;
            }
            $this->need($s * 1.8);
            $base = $this->y + $s * 1.35;
            $f = $this->font(true);
            $amt = Fmt::money($t['sum'][$p], true);
            $this->pdf->text($this->ml + $this->w - 92 - Pdf::width($label, $f, $s), $base, $label, $f, $s, self::TEXT);
            $this->pdf->text($this->ml + $this->w - 6 - Pdf::width($amt, $f, $s), $base, $amt, $f, $s, self::TEXT);
            $this->y += $s * 1.8;
        }
        $this->y += $s * 0.8;
    }

    /**
     * A fields block: a label and value row per key. An empty value prints as [Label] on a draft (with "client
     * fills in" for the client's fields) and as a dash on the signed copy. A value can be long text the client typed
     * (up to 4,000 characters), so it may run over a page break; the label stays with its first line.
     */
    private function fieldList(array $b): void
    {
        if (!$b['keys']) {
            return;
        }
        $s = $this->size;
        $this->y += $s * 0.4;
        $this->need($s * 5);
        if ($b['title'] !== '') {
            $this->paragraph([['t' => $b['title'], 'b' => true]], $s * 1.15, 'left', 0, 1.3);
            $this->y += $s * 0.2;
        }
        $lw = $this->w * 0.36;
        foreach ($b['keys'] as $k) {
            $v = $this->vals[$k] ?? '';
            $f = Template::field($this->c['def'], $k);
            if ($v === '') {
                $v = $this->final ? '—' : '[' . Template::placeLabel($this->c['def'], $k) . ($f && $f['by'] === 'client' ? ': client fills in' : '') . ']';
            }
            $labelH = $this->measure([['t' => Template::placeLabel($this->c['def'], $k)]], $s, $lw - 8, 1.3);
            $this->need(min($this->bottom - $this->top, max($labelH, $s * 1.3) + $s * 0.7));
            $y0 = $this->y;
            $page = $this->pdf->pageCount();
            $this->y += $s * 0.35;
            $this->paragraph([['t' => Template::placeLabel($this->c['def'], $k), 'color' => self::GRAY]], $s, 'left', 0, 1.3, '', 0, $lw - 8, $this->ml);
            $yLabel = $this->y;
            $this->y = $y0 + $s * 0.35;
            // A long value carries on over the page break
            $this->paragraph([['t' => $v]], $s, 'left', 0, 1.3, '', 0, $this->w - $lw, $this->ml + $lw, true);
            $this->y = ($this->pdf->pageCount() === $page ? max($yLabel, $this->y) : $this->y) + $s * 0.35;
            $this->pdf->line($this->ml, $this->y, $this->ml + $this->w, $this->y, 0.4, self::LIGHT);
        }
        $this->y += $s * 0.8;
    }

    /**
     * The signature boxes side by side (the provider first when the template countersigns): the drawn signature or
     * the typed one in a script font, the signer's photo when the signature has one, then name, title and when it
     * was signed (in the server's time zone). The pictures come from the stored contract, checked when it was signed;
     * Pdf::addImage() checks their size again, and a drawn signature it can't read prints as the typed name.
     */
    private function signatures(): void
    {
        $s = $this->size;
        $signers = Render::signers($this->c, $this->vals);
        $this->y += $s * 0.8;
        $boxH = 46;
        $this->need($boxH + $s * 7.5);
        $gap = 28;
        $colW = ($this->w - $gap) / 2;
        $y0 = $this->y;
        $maxY = $y0;
        foreach ($signers as $i => $sg) {
            $x = $this->ml + ($colW + $gap) * (count($signers) === 1 ? 0 : $i);
            $this->y = $y0;
            $this->paragraph([['t' => $sg['label'] . ': ', 'color' => self::GRAY], ['t' => $sg['company'], 'b' => true]], $s * 0.9, 'left', 0, 1.3, '', 0, $colW, $x);
            $top = $this->y + 4;
            $sx = $x;
            if (!empty($sg['sig']['photo']) && ($ph = $this->pdf->addImage((string) base64_decode((string) $sg['sig']['photo']))) !== null) {
                $this->pdf->image($ph, $x, $top + 2, $boxH - 4, $boxH - 4);
                $sx = $x + $boxH + 4; // the signature sits to the right of the picture
            }
            $colW2 = $colW - ($sx - $x);
            if ($sig = $sg['sig']) {
                if ($sig['kind'] === 'drawn' && ($img = $this->pdf->addImage((string) base64_decode((string) $sig['png'])))!== null) {
                    [$iw, $ih] = $this->pdf->imageSize($img);
                    $h = $boxH - 4;
                    $w = $iw / max(1, $ih) * $h;
                    if ($w > $colW2) {
                        $w = $colW2;
                        $h = $ih / max(1, $iw) * $w;
                    }
                    $this->pdf->image($img, $sx, $top + ($boxH - $h) / 2, $w, $h);
                } else {
                    $this->pdf->text($sx + 4, $top + $boxH * 0.7, $this->fit((string) ($sig['text'] ?? $sig['name']), 'Times-BoldItalic', 22, $colW2 - 8), 'Times-BoldItalic', 22, [0.08, 0.14, 0.42]);
                }
            }
            $ly = $top + $boxH;
            $this->pdf->line($x, $ly, $x + $colW, $ly, 0.6, self::TEXT);
            $this->y = $ly + 3;
            $this->paragraph([['t' => ($sg['name'] ?: 'Name') . ($sg['title'] ? ', ' . $sg['title'] : '')]], $s * 0.95, 'left', 0, 1.3, '', 0, $colW, $x);
            $when = $sg['at'] ? 'Signed electronically ' . Fmt::dateTime($sg['at']) . ' ' . date('T', strtotime((string) $sg['at'])) : 'Date:';
            $this->paragraph([['t' => $when, 'color' => self::GRAY]], $s * 0.85, 'left', 0, 1.3, '', 0, $colW, $x);
            $maxY = max($maxY, $this->y);
        }
        $this->y = $maxY + $s;
    }

    // ---- Signature certificate ------------------------------------------------------------------------------

    /**
     * The signature certificate, from a new page: the contract and its dates, the SHA-256 fingerprint, each signer's
     * identity check, IP address, browser and consent, and the history of events (downloads, emails and PDF
     * failures left out). The fingerprint is content_hash as stored, not recomputed here: the caller sets it before
     * it builds the signed copy. Names, titles, browser strings and event details come from signers and their
     * requests; they print as recorded, through Pdf::text(), so they can't add PDF operators. The identity-check
     * wording describes Align's signing flow; only the one-time code is taken from the events (the first code_ok).
     */
    private function certificate(): void
    {
        $c = $this->c;
        $s = 9.0;
        $this->newPage();
        $this->paragraph([['t' => 'Signature certificate', 'b' => true, 'color' => $this->accent]], 16, 'left', 0, 1.2);
        $this->paragraph([['t' => 'This page records how and when this contract was signed. It is part of the signed document.', 'color' => self::GRAY]], $s);
        $this->y += 8;
        $events = Contracts::events((int) $c['id']);
        $codeOk = array_values(array_filter($events, fn($e) => $e['event'] === 'code_ok'));
        $row = function (string $label, string $value) use ($s) {
            $lw = 130;
            $h = $this->measure([['t' => $value]], $s, $this->w - $lw, 1.35) + 4;
            $this->need($h);
            $y0 = $this->y;
            $this->paragraph([['t' => $label, 'color' => self::GRAY]], $s, 'left', 0, 1.35, '', 0, $lw - 6, $this->ml);
            $this->y = $y0;
            $this->paragraph([['t' => $value]], $s, 'left', 0, 1.35, '', 0, $this->w - $lw, $this->ml + $lw);
            $this->y = $y0 + $h;
        };
        $section = function (string $title) use ($s) {
            $this->y += 6;
            $this->need(40);
            $this->paragraph([['t' => $title, 'b' => true]], $s * 1.15, 'left', 0, 1.3);
            $this->pdf->line($this->ml, $this->y, $this->ml + $this->w, $this->y, 0.4, self::LIGHT);
            $this->y += 4;
        };
        $tz = date('T');
        $dt = fn(?string $d) => $d ? Fmt::dateTime($d, 'day', ' · ', true) . ' ' . date('T', strtotime($d)) : '—';
        $section('Document');
        $row('Contract', $c['title'] . ' (' . Contracts::number($c) . ')');
        $row('Between', $this->vals['company_name'] . ' and ' . Contracts::party($c));
        $row('Sent', $dt($c['sent_at']));
        $row('Completed', $dt($c['completed_at']));
        $row('Fingerprint (SHA-256)', (string) $c['content_hash']);
        $row('', 'The fingerprint is calculated from the contract\'s wording, the filled-in values and the signatures. ' . ($this->vals['company_name'] ?: 'The provider') . ' keeps it with the contract, so any later change to the content can be detected.');
        foreach (Render::signers($c, $this->vals) as $sg) {
            $sig = $sg['sig'];
            if (!$sig) {
                continue;
            }
            $section(($sg['side'] === 'client' ? 'Client signer' : 'Provider signer') . ': ' . $sig['name']);
            $row('Company', $sg['company']);
            if ($sig['title'] ?? '') {
                $row('Title', $sig['title']);
            }
            if ($sg['side'] === 'client') {
                $row('Email', (string) $c['signer_email']);
                $row('Identity check', $codeOk ? 'Opened the private link sent to ' . $c['signer_email'] . ' and entered the one-time code emailed to that address (' . $dt($codeOk[0]['created_at']) . ').'
                    : 'Opened the private signing link sent to ' . $c['signer_email'] . '.');
            } else {
                $row('Email', (string) ($c['provider_email'] ?? ''));
                $row('Identity check', 'Signed in to their account in ' . ($this->vals['company_name'] ?: 'the provider') . '\'s MSP-ALIGN. Accounts there need a password and two-factor authentication to sign in.');
            }
            $row('Signature', ($sig['kind'] === 'drawn' ? 'Drawn' : 'Typed') . ' signature' . (($sig['initials'] ?? '') !== '' && $sg['side'] === 'client' && $c['def']['style']['initials_footer'] ? '; initials "' . $sig['initials'] . '"' : ''));
            $row('Signed', $dt($sig['at'] ?? $sg['at']));
            $row('IP address', (string) ($sig['ip'] ?? ''));
            $row('Browser', (string) ($sig['agent'] ?? ''));
            $row('Consent', 'Agreed to do business electronically, to use an electronic signature, and that it is as binding as a handwritten one.');
        }
        $section('History (times in ' . $tz . ')');
        foreach ($events as $e) {
            if (in_array($e['event'], ['downloaded', 'emailed', 'pdf_failed'], true)) {
                continue;
            }
            $label = Contracts::EVENT_LABELS[$e['event']] ?? $e['event'];
            $who = trim((string) $e['actor']);
            $detail = trim((string) $e['detail']);
            $row(Fmt::dateTime($e['created_at'], 'day', ' · ', true), $label . ($who !== '' ? ' — ' . $who : '') . ($detail !== '' ? '. ' . $detail : '') . ($e['ip'] ? ' (IP ' . $e['ip'] . ')' : ''));
        }
    }
}
