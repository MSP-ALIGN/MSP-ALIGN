<?php
declare(strict_types=1);

namespace Align\Docs;

/**
 * Allowlist HTML sanitizer for document bodies produced by the Quill editor.
 * Everything not explicitly allowed is removed (dangerous elements) or unwrapped (unknown tags).
 */
final class Html
{
    private const ALLOWED = ['p', 'br', 'h1', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup',
        'span', 'a', 'ol', 'ul', 'li', 'blockquote', 'pre', 'code', 'hr'];

    /** Removed together with their content. */
    private const DROP = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'svg', 'math',
        'form', 'input', 'button', 'select', 'textarea', 'option', 'link', 'meta', 'base', 'title', 'head', 'noscript',
        'template', 'video', 'audio', 'source', 'track', 'canvas', 'img', 'picture'];

    public static function clean(?string $html): string
    {
        $html = str_replace(["\u{00A0}", '&nbsp;'], ' ', (string) $html);
        if (trim($html) === '') {
            return '';
        }
        if (strlen($html) > 4 * 1024 * 1024) {
            $html = substr($html, 0, 4 * 1024 * 1024);
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="__root">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__root');
        if (!$root) {
            return '';
        }
        self::walk($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    private static function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction || $child instanceof \DOMCdataSection) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            self::walk($child);
            if (!in_array($tag, self::ALLOWED, true)) {
                // Unknown element: keep its (already cleaned) children, drop the element itself
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            self::cleanAttributes($child, $tag);
        }
    }

    private static function cleanAttributes(\DOMElement $el, string $tag): void
    {
        $keep = [];
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $value = trim($attr->value);
            switch (true) {
                case $name === 'class':
                    $classes = array_filter(preg_split('/\s+/', $value) ?: [], fn($c) => preg_match('/^ql-(align|indent|direction|size|font)-[a-z0-9-]{1,20}$|^ql-ui$/', $c) === 1);
                    if ($classes) {
                        $keep['class'] = implode(' ', $classes);
                    }
                    break;
                case $name === 'data-list' && $tag === 'li':
                    if (in_array($value, ['bullet', 'ordered', 'checked', 'unchecked'], true)) {
                        $keep['data-list'] = $value;
                    }
                    break;
                case $name === 'href' && $tag === 'a':
                    if (preg_match('#^(https?://|mailto:|tel:|/(?![/\\\\])|\#)#i', $value) && !preg_match('/[\x00-\x1f]/', $value)) {
                        $keep['href'] = $value;
                    }
                    break;
                case $name === 'style':
                    $safe = [];
                    foreach (explode(';', $value) as $decl) {
                        if (preg_match('/^\s*(color|background-color)\s*:\s*(#[0-9a-f]{3,6}|rgba?\(\s*[\d.\s,%]+\)|[a-z]{3,20})\s*$/i', $decl, $m)) {
                            $safe[] = strtolower($m[1]) . ': ' . $m[2];
                        }
                    }
                    if ($safe) {
                        $keep['style'] = implode('; ', $safe);
                    }
                    break;
            }
        }
        while ($el->attributes->length) {
            $el->removeAttribute($el->attributes->item(0)->name);
        }
        foreach ($keep as $k => $v) {
            $el->setAttribute($k, $v);
        }
        if ($tag === 'a' && isset($keep['href']) && preg_match('#^https?://#i', $keep['href'])) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /** Plain-text preview (first N characters). */
    public static function excerpt(?string $html, int $len = 180): string
    {
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>', '</h1>', '</h2>', '</h3>'], ' ', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        return mb_strlen($text) > $len ? mb_substr($text, 0, $len - 1) . '…' : $text;
    }
}
