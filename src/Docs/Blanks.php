<?php
declare(strict_types=1);

namespace Align\Docs;

/**
 * 2.39.0 The fill-in blanks of a written document: the bracketed prompts Align's templates leave for the MSP, such as
 * "[name, title]", "[date]" or "[technology, configuration, procedure and who performs it]". A blank is a bracketed
 * run of 1-120 characters with no bracket or line break inside (so for "[plan, e.g. kept at [location]]" the inner
 * "[location]" is found first), except a plain number like "[1]", which is a citation. The editor's panel
 * (public/assets/docs.js, BLANK) uses the same rule on the editor's text, so the counts agree.
 *
 * Only text is read: tags and attribute values are never blanks. Also walks a body's text with what comes before and
 * after each piece in its paragraph (texts()), which Docs\Facts uses to fill blanks by their label.
 *
 * Security assumptions: $html is a body saved through Docs\Html::clean (or a template about to be cleaned); libxml
 * reads it only for its structure (LIBXML_NONET, errors suppressed and the previous mode put back), nothing is
 * fetched, and only text node values are changed, so libxml escapes whatever is written back.
 */
final class Blanks
{
    /** One blank (same rule as BLANK in docs.js). */
    public const PATTERN = '/\[[^\[\]\r\n]{1,120}\]/u';

    /** The elements a label and its blank must share: the blank's nearest one of these. */
    private const BLOCKS = ['p', 'li', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'pre', 'td', 'th', 'div'];

    /** How many blanks $html still has (0 for an empty body or an uploaded file's empty body). */
    public static function count(?string $html): int
    {
        if ($html === null || trim($html) === '' || !str_contains($html, '[')) {
            return 0;
        }
        $text = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/li|\/h\d|\/td|\/th|\/blockquote|\/pre|\/div)\b[^>]*>/i', "\n", $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return self::countText($text);
    }

    /** How many blanks plain $text has. */
    public static function countText(string $text): int
    {
        if (!preg_match_all(self::PATTERN, $text, $m)) {
            return 0;
        }
        return count(array_filter($m[0], fn($b) => !preg_match('/^\[\d+\]$/', $b)));
    }

    /**
     * Calls $fn(DOMText $node, string $before, string $after) for each text node of $html in document order, where
     * $before / $after are the text of the same paragraph (nearest BLOCKS element) before / after the node. $fn may
     * change $node->nodeValue (plain text; libxml escapes it). Returns the body re-serialized, which the caller cleans
     * again before storing (Html::clean), or $html unchanged when nothing was changed or it couldn't be read.
     */
    public static function texts(string $html, callable $fn): string
    {
        if (trim($html) === '') {
            return $html;
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="__blanks">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__blanks');
        if (!$root) {
            return $html;
        }
        // Group the text nodes by their paragraph, so each one knows the text around it there
        $groups = [];
        $order = [];
        foreach ((new \DOMXPath($doc))->query('.//text()', $root) as $t) {
            $block = $root;
            for ($p = $t->parentNode; $p && $p !== $root; $p = $p->parentNode) {
                if ($p instanceof \DOMElement && in_array(strtolower($p->tagName), self::BLOCKS, true)) {
                    $block = $p;
                    break;
                }
            }
            $key = $block->getNodePath(); // (not spl_object_id: libxml's node wrappers are freed and their ids reused)
            $groups[$key][] = $t;
            $order[] = [$key, count($groups[$key]) - 1];
        }
        $changed = false;
        foreach ($order as [$key, $i]) {
            $nodes = $groups[$key];
            $before = implode('', array_map(fn($n) => $n->nodeValue, array_slice($nodes, 0, $i)));
            $after = implode('', array_map(fn($n) => $n->nodeValue, array_slice($nodes, $i + 1)));
            $was = $nodes[$i]->nodeValue;
            $fn($nodes[$i], $before, $after);
            $changed = $changed || $nodes[$i]->nodeValue !== $was;
        }
        if (!$changed) {
            return $html;
        }
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }
}
