<?php
declare(strict_types=1);

namespace Align\Docs;

/**
 * 2.20.0 Brings a Word (.docx) file's text into an editable document: paragraphs, headings (Title and Heading 1–3),
 * bold, italic, underline and strikethrough, links to web and email addresses, bulleted and numbered lists (with
 * their levels) and tables (each row as a paragraph, cells separated by " | "; the editor has no tables). Pictures,
 * text boxes, headers, footers, comments, footnotes and tracked deletions are left out.
 *
 * Security assumptions: the file is untrusted. Only word/document.xml, word/numbering.xml and the document's
 * relationships are read, each at most MAX_PART bytes uncompressed (a ZIP bomb is refused before it's inflated);
 * XML with a DOCTYPE is refused and libxml never loads anything from the network. Text is escaped as it is
 * written and the result goes through Html::clean, so the editor gets only allowlisted HTML.
 */
final class Docx
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    /** Largest XML part read (uncompressed). */
    private const MAX_PART = 5 * 1024 * 1024;

    /** @var array<string, string> relationship id => external link target */
    private array $links = [];
    /** @var array<string, string> "numId:level" => 'bullet' | 'ordered' */
    private array $lists = [];

    /** The document as clean HTML for the editor. Throws InvalidArgumentException with a sentence for the person. */
    public static function toHtml(string $path): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \InvalidArgumentException('Word import needs PHP\'s zip extension on the server (php-zip).');
        }
        $z = new \ZipArchive();
        if ($z->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \InvalidArgumentException('That Word file couldn\'t be opened.');
        }
        try {
            $self = new self();
            $body = self::xml($z, 'word/document.xml');
            if (!$body) {
                throw new \InvalidArgumentException('That file has no Word document inside.');
            }
            if ($rels = self::xml($z, 'word/_rels/document.xml.rels')) {
                foreach ($rels->getElementsByTagName('Relationship') as $r) {
                    if (strcasecmp($r->getAttribute('TargetMode'), 'External') === 0) {
                        $self->links[$r->getAttribute('Id')] = $r->getAttribute('Target');
                    }
                }
            }
            if ($num = self::xml($z, 'word/numbering.xml')) {
                $self->numbering($num);
            }
        } finally {
            $z->close();
        }
        $b = $body->getElementsByTagNameNS(self::W, 'body')->item(0);
        return $b ? Html::clean($self->blocks($b)) : '';
    }

    /** One XML part of the ZIP, or null when it's missing; refuses parts that are too large or have a DOCTYPE. */
    private static function xml(\ZipArchive $z, string $name): ?\DOMDocument
    {
        $st = $z->statName($name);
        if ($st === false) {
            return null;
        }
        if ($st['size'] > self::MAX_PART) {
            throw new \InvalidArgumentException('That Word file is too large to import.');
        }
        $xml = (string) $z->getFromName($name);
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new \InvalidArgumentException('That Word file couldn\'t be read.');
        }
        $d = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $d->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($ok && $d->doctype !== null) { // also one written in another encoding, which the text check above can't see
            throw new \InvalidArgumentException('That Word file couldn\'t be read.');
        }
        return $ok ? $d : null;
    }

    /** Reads which list numbers are bullets: numId and level => 'bullet' or 'ordered'. */
    private function numbering(\DOMDocument $d): void
    {
        $abstract = [];
        foreach ($d->getElementsByTagNameNS(self::W, 'abstractNum') as $a) {
            $id = $a->getAttributeNS(self::W, 'abstractNumId');
            foreach ($a->getElementsByTagNameNS(self::W, 'lvl') as $lvl) {
                $fmt = $lvl->getElementsByTagNameNS(self::W, 'numFmt')->item(0);
                $abstract[$id][$lvl->getAttributeNS(self::W, 'ilvl')] = $fmt && in_array($fmt->getAttributeNS(self::W, 'val'), ['bullet', 'none'], true) ? 'bullet' : 'ordered';
            }
        }
        foreach ($d->getElementsByTagNameNS(self::W, 'num') as $n) {
            $ref = $n->getElementsByTagNameNS(self::W, 'abstractNumId')->item(0);
            foreach ($abstract[$ref ? $ref->getAttributeNS(self::W, 'val') : ''] ?? [] as $lvl => $kind) {
                $this->lists[$n->getAttributeNS(self::W, 'numId') . ':' . $lvl] = $kind;
            }
        }
    }

    /** The HTML of a container's paragraphs and tables, with list items grouped into lists. */
    private function blocks(\DOMElement $parent): string
    {
        $out = '';
        $items = '';
        foreach ($parent->childNodes as $n) {
            if (!$n instanceof \DOMElement || $n->namespaceURI !== self::W) {
                continue;
            }
            if ($n->localName === 'p') {
                [$html, $li] = $this->paragraph($n);
                if ($li) {
                    $items .= $html;
                    continue;
                }
                $out .= $items !== '' ? '<ol>' . $items . '</ol>' : '';
                $items = '';
                $out .= $html;
            } elseif ($n->localName === 'tbl') {
                $out .= $items !== '' ? '<ol>' . $items . '</ol>' : '';
                $items = '';
                $out .= $this->table($n);
            } elseif ($n->localName === 'sdt' && ($c = $n->getElementsByTagNameNS(self::W, 'sdtContent')->item(0))) {
                $out .= $items !== '' ? '<ol>' . $items . '</ol>' : '';
                $items = '';
                $out .= $this->blocks($c);
            }
        }
        return $out . ($items !== '' ? '<ol>' . $items . '</ol>' : '');
    }

    /** A table as one paragraph per row, the cells' text separated by " | ". */
    private function table(\DOMElement $tbl): string
    {
        $out = '';
        foreach ($tbl->childNodes as $tr) {
            if (!$tr instanceof \DOMElement || $tr->localName !== 'tr') {
                continue;
            }
            $cells = [];
            foreach ($tr->childNodes as $tc) {
                if ($tc instanceof \DOMElement && $tc->localName === 'tc') {
                    $parts = [];
                    foreach ($tc->getElementsByTagNameNS(self::W, 'p') as $p) {
                        $t = trim($this->runs($p));
                        if ($t !== '') {
                            $parts[] = $t;
                        }
                    }
                    $cells[] = implode(' ', $parts);
                }
            }
            if (array_filter($cells, fn($c) => $c !== '')) {
                $out .= '<p>' . implode(' | ', $cells) . '</p>';
            }
        }
        return $out;
    }

    /** A paragraph: [html, whether it is a list item]. Empty paragraphs give ''. */
    private function paragraph(\DOMElement $p): array
    {
        $text = $this->runs($p);
        $style = '';
        $numId = '';
        $lvl = 0;
        $ppr = null;
        foreach ($p->childNodes as $c) {
            if ($c instanceof \DOMElement && $c->localName === 'pPr') {
                $ppr = $c;
            }
        }
        if ($ppr) {
            $s = $ppr->getElementsByTagNameNS(self::W, 'pStyle')->item(0);
            $style = $s ? $s->getAttributeNS(self::W, 'val') : '';
            $np = $ppr->getElementsByTagNameNS(self::W, 'numPr')->item(0);
            if ($np) {
                $id = $np->getElementsByTagNameNS(self::W, 'numId')->item(0);
                $il = $np->getElementsByTagNameNS(self::W, 'ilvl')->item(0);
                $numId = $id ? $id->getAttributeNS(self::W, 'val') : '';
                $lvl = $il ? max(0, min(8, (int) $il->getAttributeNS(self::W, 'val'))) : 0;
            }
        }
        if (trim(strip_tags($text)) === '') {
            return ['', false];
        }
        if ($numId !== '' && $numId !== '0') {
            $kind = $this->lists["$numId:$lvl"] ?? 'bullet';
            return ['<li data-list="' . $kind . '"' . ($lvl ? ' class="ql-indent-' . $lvl . '"' : '') . '>' . $text . '</li>', true];
        }
        if (preg_match('/^(title|heading\s*1)$/i', $style)) {
            return ['<h1>' . $text . '</h1>', false];
        }
        if (preg_match('/^(subtitle|heading\s*2)$/i', $style)) {
            return ['<h2>' . $text . '</h2>', false];
        }
        if (preg_match('/^heading\s*[3-9]$/i', $style)) {
            return ['<h3>' . $text . '</h3>', false];
        }
        if (preg_match('/^(list\s*bullet|listbullet)/i', $style)) {
            return ['<li data-list="bullet">' . $text . '</li>', true];
        }
        return ['<p>' . $text . '</p>', false];
    }

    /** The inline HTML of a paragraph's runs, links and inserted text (deleted text is left out). */
    private function runs(\DOMElement $el): string
    {
        $out = '';
        foreach ($el->childNodes as $n) {
            if (!$n instanceof \DOMElement || $n->namespaceURI !== self::W) {
                continue;
            }
            switch ($n->localName) {
                case 'r':
                    $out .= $this->run($n);
                    break;
                case 'hyperlink':
                    $inner = $this->runs($n);
                    $href = $this->links[$n->getAttributeNS(self::R, 'id')] ?? '';
                    $out .= $href !== '' && preg_match('#^(https?://|mailto:)#i', $href)
                        ? '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . $inner . '</a>' : $inner;
                    break;
                case 'ins':
                case 'smartTag':
                case 'fldSimple':
                case 'customXml':
                    $out .= $this->runs($n);
                    break;
                case 'sdt':
                    if ($c = $n->getElementsByTagNameNS(self::W, 'sdtContent')->item(0)) {
                        $out .= $this->runs($c);
                    }
                    break;
            }
        }
        return $out;
    }

    /** One run: its text (escaped) with bold, italic, underline and strikethrough. */
    private function run(\DOMElement $r): string
    {
        $text = '';
        $fmt = [];
        foreach ($r->childNodes as $c) {
            if (!$c instanceof \DOMElement || $c->namespaceURI !== self::W) {
                continue;
            }
            switch ($c->localName) {
                case 'rPr':
                    foreach ($c->childNodes as $f) {
                        if (!$f instanceof \DOMElement) {
                            continue;
                        }
                        $v = strtolower($f->getAttributeNS(self::W, 'val'));
                        $on = !in_array($v, ['0', 'false', 'none'], true);
                        if ($on && in_array($f->localName, ['b', 'i', 'u', 'strike'], true)) {
                            $fmt[$f->localName] = true;
                        }
                    }
                    break;
                case 't':
                    $text .= htmlspecialchars($c->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    break;
                case 'tab':
                    $text .= ' ';
                    break;
                case 'br':
                case 'cr':
                    $text .= '<br>';
                    break;
                case 'noBreakHyphen':
                    $text .= '-';
                    break;
            }
        }
        if ($text === '') {
            return '';
        }
        foreach (['b' => 'strong', 'i' => 'em', 'u' => 'u', 'strike' => 's'] as $k => $tag) {
            if (!empty($fmt[$k])) {
                $text = "<$tag>$text</$tag>";
            }
        }
        return $text;
    }
}
