<?php
declare(strict_types=1);

namespace Align\Docs;

/**
 * 2.38.1 The approval block of a written document sent for signature: the paragraph Align's templates end with,
 * like "Approved by: [name, title] / Signature: ____ / Date approved: [date] / Next review date: [date]" (lines split
 * by line breaks or " · "). find() takes it out of the body so Contracts\PdfRender::document can print it with a
 * blank name, signature and date for each signer and note where they are on the page; Docs\Signing stamps the
 * signatures there once everyone has signed. A document without one gets a block added at its end instead.
 * The document itself is never changed.
 *
 * Which paragraph (2.38.1 review): the last top-level paragraph with a name line ("<Label>: [name, title]", or
 * blank/underscores) and a "Signature:" line, since an approval block comes at the end (a form earlier in the
 * document isn't it); else the last with a name line and a date line. "Name:" and "Prepared by:" lines are
 * someone filling in a form or who wrote it, not an approval, and are never used. A name line already filled in by
 * hand isn't a blank, so it isn't taken over.
 *
 * Security assumptions: $html is a document body saved through Docs\Html::clean; libxml reads it only for its
 * structure (LIBXML_NONET, errors suppressed and the previous mode put back) and nothing is fetched. The parts given
 * back are serialized from that parse; the labels and extra lines are plain text, printed through the PDF writer.
 */
final class SignBlock
{
    /** A blank to fill in: a bracketed placeholder, underscores, or nothing. */
    private const BLANK = '(?:\[[^\]]{0,120}\]|_{3,}|)';

    /**
     * The approval block of $html, or null when there is none: ['before' => HTML before it, 'after' => HTML after
     * it, 'label' => the name line's label ("Approved by"), 'date' => the date line's label ("Date approved"), 'extra'
     * => the block's other lines as plain text, a "Next review date" one filled with $reviewDue (a date, or null to
     * keep it as it is)].
     */
    public static function find(string $html, ?string $reviewDue = null): ?array
    {
        if (trim($html) === '' || stripos($html, '<p') === false) {
            return null;
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="r">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('r');
        if (!$root) {
            return null;
        }
        $nodes = iterator_to_array($root->childNodes);
        $pick = null;
        $fallback = null;
        foreach ($nodes as $i => $n) {
            if (!$n instanceof \DOMElement || strtolower($n->tagName) !== 'p') {
                continue;
            }
            $b = self::parse(self::lines($n), $reviewDue);
            if ($b && $b['sig']) {
                $pick = [$i, $b]; // the last one wins: an approval block comes at the end (a form earlier isn't it)
            } elseif ($b && $b['has_date']) {
                $fallback = [$i, $b];
            }
        }
        $pick ??= $fallback; // a block without a "Signature:" line (the last one) only when none has one
        if ($pick === null) {
            return null;
        }
        [$at, $b] = $pick;
        $before = $after = '';
        foreach ($nodes as $i => $n) {
            if ($i < $at) {
                $before .= $doc->saveHTML($n);
            } elseif ($i > $at) {
                $after .= $doc->saveHTML($n);
            }
        }
        return ['before' => $before, 'after' => $after, 'label' => $b['label'], 'date' => $b['date'], 'extra' => $b['extra']];
    }

    /**
     * A paragraph's text as lines: split at line breaks (also inside bold or other inline elements), then at " · "
     * or " — " between "Label: value" parts (never inside [brackets]).
     */
    private static function lines(\DOMElement $p): array
    {
        $text = self::text($p);
        $out = [];
        foreach (explode("\n", str_replace("\u{a0}", ' ', $text)) as $line) {
            foreach (preg_split('/\s+[·—]\s+(?![^\[]*\])(?=[^:]{1,60}:)/u', trim($line)) ?: [] as $part) {
                if (trim($part) !== '') {
                    $out[] = trim($part);
                }
            }
        }
        return $out;
    }

    /** An element's text with each <br> (at any depth) as a line break. */
    private static function text(\DOMNode $n): string
    {
        if ($n instanceof \DOMElement && strtolower($n->tagName) === 'br') {
            return "\n";
        }
        if (!$n->hasChildNodes()) {
            return $n instanceof \DOMText ? $n->textContent : '';
        }
        $t = '';
        foreach ($n->childNodes as $c) {
            $t .= self::text($c);
        }
        return $t;
    }

    /**
     * The block in $lines, or null when they aren't one: label (the name line's), sig (a "Signature:" line was
     * there), date (the date line's label, "Date" when there was none), has_date, extra (the other lines; "Next
     * review date" filled with $reviewDue).
     */
    private static function parse(array $lines, ?string $reviewDue): ?array
    {
        $label = null;
        $sig = false;
        $date = null;
        $extra = [];
        foreach ($lines as $l) {
            if (!preg_match('/^([^:]{1,60}):\s*(.*)$/u', $l, $m)) {
                $extra[] = $l;
                continue;
            }
            $k = trim($m[1]);
            $blank = (bool) preg_match('/^' . self::BLANK . '$/u', trim($m[2]));
            $lk = mb_strtolower($k);
            if ($lk === 'signature' && $blank) {
                $sig = true;
            } elseif ($blank && $date === null && preg_match('/^date\b/', $lk)) {
                $date = $k;
            } elseif (preg_match('/^(next review|re-review)/', $lk) && $reviewDue !== null && preg_match('/^\[[^\]]*\]$|^_{3,}$|^$/u', trim($m[2]))) {
                $extra[] = $k . ': ' . \Align\Fmt::date($reviewDue);
            } elseif ($label === null && $blank && !in_array($lk, ['name', 'prepared by', 'printed name', 'employee name'], true)
                && preg_match('/\b(approv\w*|official|owner|administrator|affirm\w*|accepted|signed|authori[sz]ed|responsible|manager|officer)\b/u', $lk)) {
                $label = $k;
            } else {
                $extra[] = $l;
            }
        }
        if ($label === null || (!$sig && $date === null)) {
            return null;
        }
        return ['label' => $label, 'sig' => $sig, 'date' => $date ?? 'Date', 'has_date' => $date !== null, 'extra' => $extra];
    }
}
