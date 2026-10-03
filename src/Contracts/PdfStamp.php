<?php
declare(strict_types=1);

namespace Align\Contracts;

use Align\Fmt;
use Align\Pdf\Pdf;
use Align\Pdf\PdfDict;
use Align\Pdf\PdfDoc;
use Align\Pdf\PdfName;
use Align\Pdf\PdfRef;

/**
 * Contracts printed on the MSP's own PDF (2.2): the boxes placed on its pages (Template places) get their values,
 * signatures and pictures, shown in the browser over the PDF (items) and stamped onto the PDF itself (build) as an
 * incremental update, so the original pages stay exactly as uploaded. The signed copy also gets the certificate.
 */
final class PdfStamp
{
    private const GRAY = [0.45, 0.48, 0.52];
    private const INK = [0.07, 0.09, 0.12];
    private const SIG = [0.08, 0.14, 0.42];

    /** The template's PDF file on disk, or null. */
    public static function sourcePath(?array $pdf): ?string
    {
        if (!$pdf || !preg_match('/^source-[a-f0-9]{16}\.pdf$/', (string) ($pdf['file'] ?? ''))) {
            return null;
        }
        $p = Contracts::dir() . '/' . $pdf['file'];
        return is_file($p) ? $p : null;
    }

    /** Sends the template's PDF to the browser (for the page viewer), or a 404. */
    public static function serve(?array $pdf): void
    {
        $p = self::sourcePath($pdf);
        if (!$p) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; ' . content_filename((string) ($pdf['name'] ?? 'contract.pdf')));
        header('Content-Length: ' . filesize($p));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($p);
    }

    /** Whether any template or contract still uses this PDF file (they're never changed, so copies share them). */
    /** Deletes a template's PDF once no template or contract uses it any more. */
    public static function removeIfUnused(string $file): void
    {
        if (preg_match('/^source-[a-f0-9]{16}\.pdf$/', $file) && !self::inUse($file)) {
            @unlink(Contracts::dir() . '/' . $file);
        }
    }

    public static function inUse(string $file, ?int $exceptTemplate = null): bool
    {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $file) . '%';
        return (bool) \Align\DB::value('SELECT 1 FROM contracts WHERE def LIKE ? LIMIT 1', [$like])
            || (bool) \Align\DB::value('SELECT 1 FROM contract_templates WHERE def LIKE ? AND id <> ? LIMIT 1', [$like, (int) $exceptTemplate]);
    }

    /**
     * Stores an uploaded PDF for a template after reading it (so a PDF Align can't work with is refused now, not when a
     * contract is signed). Returns the def's pdf part, or throws with a message for the person uploading.
     */
    public static function storeSource(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new \InvalidArgumentException(in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'That file is larger than the server accepts.' : 'Choose your contract\'s PDF to upload.');
        }
        if (filesize($file['tmp_name']) > Contracts::MAX_UPLOAD) {
            throw new \InvalidArgumentException('The PDF is larger than 25 MB.');
        }
        return self::storeBytes((string) file_get_contents($file['tmp_name']), (string) ($file['name'] ?? ''));
    }

    /** Checks and keeps a contract PDF; returns the template's 'pdf' part. */
    public static function storeBytes(string $bytes, string $fileName): array
    {
        if (strlen($bytes) > Contracts::MAX_UPLOAD) {
            throw new \InvalidArgumentException('The PDF is larger than 25 MB.');
        }
        ['pages' => $pages, 'notes' => $notes] = self::inspect($bytes);
        if (!is_dir(Contracts::dir())) {
            mkdir(Contracts::dir(), 0750, true);
        }
        $name = 'source-' . bin2hex(random_bytes(8)) . '.pdf';
        if (file_put_contents(Contracts::dir() . '/' . $name, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Could not save the PDF.');
        }
        $orig = mb_substr(preg_replace('/[^\w .()-]/u', '', $fileName) ?: 'contract.pdf', 0, 190);
        return ['file' => $name, 'name' => $orig, 'hash' => hash('sha256', $bytes), 'pages' => $pages, 'notes' => $notes];
    }

    /**
     * Reads a PDF the way signing will, so one Align can't work with is refused now, not when a contract is signed:
     * ['pages' => [[width, height] as shown, ...], 'notes' => [what signed copies will leave out]]. Throws with a
     * message for the person uploading.
     */
    public static function inspect(string $bytes): array
    {
        try {
            $doc = new PdfDoc($bytes);
            $doc->nextNumber();
            $out = [];
            $dropped = false;
            foreach ($doc->pages() as $p) {
                $out[] = $p['rotate'] % 180 ? [round($p['h'], 2), round($p['w'], 2)] : [round($p['w'], 2), round($p['h'], 2)];
                if ($doc->pageContent($p['dict']) === null) {
                    throw new \InvalidArgumentException('This PDF uses a kind of compression Align can\'t read. Save it again as a PDF (for example "Save as PDF" in Word) and upload that.');
                }
                self::isolate($doc, $p['dict']);
                $d = $p['dict']->d;
                $dropped = self::safeAnnots($doc, $d) || $dropped;
            }
            $notes = [];
            if ($dropped || self::safeCatalog($doc) !== null) {
                $notes[] = 'This PDF has fillable form fields, comments, scripts or attachments. Align draws its own boxes on the pages, and leaves those out of the contracts it makes (links are kept).';
            }
            return ['pages' => $out, 'notes' => $notes];
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[msp-align] contract PDF not readable: ' . $e->getMessage());
            throw new \InvalidArgumentException('This PDF can\'t be read. Save it again as a PDF (for example "Save as PDF" in Word) and upload that.');
        }
    }

    // ---- What each box shows ---------------------------------------------------------------------------------

    private static function money(float $v, bool $plain): string
    {
        $s = Fmt::money($v, true);
        return $plain ? trim(str_replace(Fmt::symbol(), '', $s)) : $s;
    }

    /**
     * The boxes with what they show. $mode: 'preview' (staff: empty boxes show their label), 'sign' (the client's
     * boxes are inputs), 'view' (as signed). $me: the staff member looking (their picture and name in previews).
     * Each: id, page, x, y, w, h, size, align, kind (text|input|sig|initials|photo|label|blank), text, label, img, field
     */
    public static function items(array $c, string $mode, ?array $me = null): array
    {
        $def = $c['def'];
        $vals = Contracts::values($c);
        $t = Contracts::totals($def, $c['vals']);
        $lines = [];
        foreach ($t['lines'] as $l) {
            if ($l['key'] !== '') {
                $lines[$l['key']] = $l;
            }
        }
        $cs = $c['client_sig'] ?? null;
        $ps = $c['provider_sig'] ?? null;
        $out = [];
        foreach ($def['places'] ?? [] as $pl) {
            $it = ['id' => $pl['id'], 'page' => $pl['page'], 'x' => $pl['x'], 'y' => $pl['y'], 'w' => $pl['w'], 'h' => $pl['h'], 'size' => $pl['size'],
                'align' => $pl['align'], 'key' => $pl['key'], 'label' => Template::placeLabel($def, $pl['key']), 'kind' => 'text', 'text' => '', 'img' => null, 'field' => null];
            $key = $pl['key'];
            $f = null;
            $side = str_ends_with($key, '.client') ? 'client' : (str_ends_with($key, '.provider') ? 'provider' : null);
            $sig = $side === 'client' ? $cs : ($side === 'provider' ? $ps : null);
            if (str_starts_with($key, 'sig.')) {
                if ($sig) {
                    $it['kind'] = 'sig';
                    if ($sig['kind'] === 'drawn') {
                        $it['img'] = 'data:image/png;base64,' . $sig['png'];
                    } else {
                        $it['text'] = (string) ($sig['text'] ?? $sig['name']);
                    }
                } else {
                    $it['kind'] = $mode === 'sign' && $side === 'client' ? 'sighere' : 'label';
                }
            } elseif ($key === 'initials.client') {
                // signing: a step the signer clicks once their initials are adopted
                $it['kind'] = $cs ? 'text' : ($mode === 'sign' ? 'initials' : 'label');
                $it['text'] = (string) ($cs['initials'] ?? '');
                $it['sig'] = true;
            } elseif ($key === 'photo.provider') {
                $photo = $ps['photo'] ?? ($mode === 'preview' && $me ? Contracts::photo($me) : null);
                $it['kind'] = $photo ? 'photo' : 'label';
                $it['img'] = $photo ? 'data:image/jpeg;base64,' . $photo : null;
            } elseif (str_starts_with($key, 'name.') || str_starts_with($key, 'title.') || str_starts_with($key, 'date.')) {
                [$what] = explode('.', $key);
                $at = $side === 'client' ? $c['client_signed_at'] : $c['provider_signed_at'];
                $it['text'] = match ($what) {
                    'name' => $sig['name'] ?? ($side === 'client' ? (string) $c['signer_name'] : (string) ($c['provider_name'] ?? '')),
                    'title' => $sig['title'] ?? ($side === 'client' ? (string) $c['signer_title'] : ''),
                    default => $at ? Fmt::date($at) : '',
                };
                if ($mode === 'sign' && $side === 'client' && !$sig && $what !== 'date') {
                    // the signer types their name and title on the page (nothing filled in for them)
                    $it['kind'] = 'input';
                    $it['text'] = '';
                    $it['field'] = ['name' => '', 'role' => $what === 'name' ? 'sig_name' : 'sig_title', 'type' => 'text', 'required' => true, 'options' => [], 'value' => '',
                        'label' => $what === 'name' ? 'Your full name' : 'Your title', 'help' => ''];
                }
            } elseif (preg_match(Template::SVC_KEY, $key, $m)) {
                $l = $lines[$m[1]] ?? null;
                $it['text'] = $l ? match ($m[2]) { 'qty' => Render::qty($l['qty']), 'price' => self::money($l['price'], $pl['plain']), default => self::money($l['total'], $pl['plain']) } : '';
                if (!$l && $mode !== 'preview') {
                    $it['text'] = $m[2] === 'qty' ? '0' : self::money(0, $pl['plain']);
                }
            } else {
                $f = Template::field($def, $key);
                if ($f && $f['by'] === 'client' && $mode === 'sign') {
                    $it['kind'] = $f['type'] === 'initials' ? 'initials' : 'input'; // initials: the same click-to-initial step
                    $it['field'] = ['name' => 'f[' . $f['key'] . ']', 'type' => $f['type'], 'required' => $f['required'], 'options' => $f['options'],
                        'value' => (string) ($c['vals']['f'][$f['key']] ?? ''), 'label' => $f['label'], 'help' => $f['help']];
                } else {
                    $v = (string) ($vals[$key] ?? '');
                    if (in_array($key, ['monthly_total', 'yearly_total', 'one_time_total'], true) && $pl['plain']) {
                        $v = trim(str_replace(Fmt::symbol(), '', $v));
                    }
                    if ($f && $f['type'] === 'checkbox') {
                        $v = $v === 'Yes' ? 'X' : '';
                    }
                    $it['text'] = $v;
                }
            }
            // Signing: the client's date shows today's date (it's filled in when they sign), not an empty box
            if ($mode === 'sign' && $it['text'] === '' && ($key === 'date.client' || $key === 'signed_date') && !$c['client_signed_at']) {
                $it['kind'] = 'text';
                $it['text'] = Fmt::date(date('Y-m-d'));
                $it['auto'] = true;
            }
            if ($it['kind'] === 'text' && $it['text'] === '' && $mode === 'preview') {
                $it['kind'] = 'label';
            }
            if ($it['kind'] === 'label' && $mode !== 'preview') {
                $it['kind'] = 'blank';
            }
            $it['who'] = $side ?? ($f ? $f['by'] : 'auto');
            $out[] = $it;
        }
        return $out;
    }

    // ---- The PDF -------------------------------------------------------------------------------------------

    /**
     * The contract's PDF: the MSP's pages with the boxes filled in (and, signed, the certificate at the end), as an
     * incremental update of the original file. What the original can do to a viewer is taken out of the update:
     * its graphics state can't reach the stamp (see isolate()), annotations other than plain links are dropped (they
     * draw over the page and could hide the signatures), and so are document scripts, automatic actions and forms.
     */
    public static function build(array $c, bool $final): string
    {
        $path = self::sourcePath($c['def']['pdf'] ?? null);
        if (!$path) {
            throw new \RuntimeException('The contract\'s PDF is missing from the server.');
        }
        $doc = new PdfDoc((string) file_get_contents($path));
        $pages = $doc->pages();
        $shown = fn(array $p) => $p['rotate'] % 180 ? [$p['h'], $p['w']] : [$p['w'], $p['h']];
        // Certificate pages: the size of the first page when it's a normal paper size, else Letter
        [$cw, $ch] = $shown($pages[0]);
        if ($cw < 300 || $ch < 300 || $cw > 1300 || $ch > 1300) {
            [$cw, $ch] = [612.0, 792.0];
        }
        $pdf = new Pdf($cw, $ch);
        // A fresh prefix each time, so names already on the pages (a PDF that came out of Align, say) never clash
        $pdf->prefix = 'AlignC' . bin2hex(random_bytes(4));
        $byPage = [];
        foreach (self::items($c, $final ? 'view' : 'preview') as $it) {
            $byPage[$it['page']][] = $it;
        }
        foreach ($pages as $i => $p) {
            [$w, $h] = $shown($p);
            $pdf->addPage($w, $h);
            foreach ($byPage[$i] ?? [] as $it) {
                self::draw($pdf, $it, $final);
            }
            if (!$final) {
                $label = PdfRender::draftLabel((string) ($c['status'] ?? 'draft'));
                $pdf->text(($w - Pdf::width($label, 'Helvetica-Bold', 8)) / 2, 12, $label, 'Helvetica-Bold', 8, [0.75, 0.2, 0.2]);
            }
        }
        $first = count($pages);
        if ($final) {
            PdfRender::certificateInto($pdf, $c); // on new pages of the certificate size ($cw x $ch)
        }
        $shared = $pdf->exportShared($doc->nextNumber());
        $objects = $shared['objects'];
        $next = $shared['next'];
        $fonts = array_map(fn($n) => new PdfRef($n), $shared['fonts']);
        $images = array_map(fn($n) => new PdfRef($n), $shared['images']);
        foreach ($pages as $i => $p) {
            $d = $p['dict']->d;
            $changed = self::safeAnnots($doc, $d) || isset($p['dict']->d['Annots']);
            $content = $pdf->pageContent($i);
            if (trim($content) !== '') {
                [$open, $close] = self::isolate($doc, $p['dict']);
                $objects[$o = $next++] = Pdf::streamBody($open);
                $objects[$n = $next++] = Pdf::streamBody($close . 'q ' . implode(' ', array_map(fn($v) => PdfDoc::ser((float) $v), self::toPage($p))) . " cm\n" . $content . "\nQ\n");
                $orig = $doc->resolve($d['Contents'] ?? null);
                $d['Contents'] = [new PdfRef($o), ...(is_array($orig) ? $orig : (isset($d['Contents']) ? [$d['Contents']] : [])), new PdfRef($n)];
                $d['Resources'] = self::resources($doc, $p['resources'], $fonts, $images);
                $changed = true;
            }
            if ($changed) {
                $objects[$p['ref']->num] = PdfDoc::ser(new PdfDict($d));
            }
        }
        if ($pdf->pageCount() > $first) {
            self::appendPages($doc, $pdf, $first, $objects, $next, $fonts, $images);
        }
        if (($cat = self::safeCatalog($doc)) !== null) {
            $objects[$doc->rootRef()->num] = $cat;
        }
        return $doc->update($objects);
    }

    /** From the page as shown (top-left, rotation applied) to the page's own coordinates: the cm matrix. */
    private static function toPage(array $p): array
    {
        [$w, $h] = $p['rotate'] % 180 ? [$p['h'], $p['w']] : [$p['w'], $p['h']];
        return match ($p['rotate']) {
            90 => [0, 1, -1, 0, $p['x0'] + $h, $p['y0']],
            180 => [-1, 0, 0, -1, $p['x0'] + $w, $p['y0'] + $h],
            270 => [0, -1, 1, 0, $p['x0'], $p['y0'] + $w],
            default => [1, 0, 0, 1, $p['x0'], $p['y0']],
        };
    }

    /**
     * The lowest and the final save depth of a content stream: each "q" adds one, each "Q" takes one away. Strings,
     * comments, hex strings, dictionaries, arrays and inline images are skipped, so a "q" inside them doesn't count.
     * Throws when the content ends inside one of them (a viewer would read what follows, our stamp, as part of it).
     */
    public static function balance(string $s): array
    {
        $depth = 0;
        $min = 0;
        $arrays = 0;
        $dicts = 0;
        $n = strlen($s);
        $p = 0;
        $delim = fn(int $i) => $i < 0 || $i >= $n || str_contains(" \t\r\n\f\0()<>[]{}/%", $s[$i]);
        $space = fn(int $i) => $i < 0 || $i >= $n || str_contains(" \t\r\n\f\0", $s[$i]);
        // a whole token (an operator, not part of a name like /Q or /BI)
        $token = fn(int $i, int $len) => $delim($i - 1) && ($s[$i - 1] ?? '') !== '/' && $delim($i + $len);
        $open = static fn(string $what) => new \RuntimeException('a page\'s content ends inside ' . $what);
        while ($p < $n) {
            $p += strcspn($s, '(%<>[]qQB', $p);
            if ($p >= $n) {
                break;
            }
            $c = $s[$p];
            if ($c === '(') {
                $lvl = 0;
                while (true) {
                    $p += strcspn($s, '()\\', $p);
                    if ($p >= $n) {
                        throw $open('a string');
                    }
                    $ch = $s[$p];
                    if ($ch === '\\') {
                        $p += 2;
                        continue;
                    }
                    $p++;
                    if ($ch === '(') {
                        $lvl++;
                    } elseif (--$lvl === 0) {
                        break;
                    }
                }
            } elseif ($c === '%') {
                $p += strcspn($s, "\r\n", $p);
            } elseif ($c === '<' && ($s[$p + 1] ?? '') === '<') {
                $dicts++;
                $p += 2;
            } elseif ($c === '<') {
                $e = strpos($s, '>', $p);
                if ($e === false) {
                    throw $open('a hex string');
                }
                $p = $e + 1;
            } elseif ($c === '>' && ($s[$p + 1] ?? '') === '>') {
                $dicts = max(0, $dicts - 1);
                $p += 2;
            } elseif ($c === '[' || $c === ']') {
                $arrays = max(0, $arrays + ($c === '[' ? 1 : -1));
                $p++;
            } elseif ($c === 'B' && ($s[$p + 1] ?? '') === 'I' && $token($p, 2)) {
                $p = self::skipInlineImage($s, $p + 2, $token, $space) ?? throw $open('an inline image');
            } elseif (($c === 'q' || $c === 'Q') && $token($p, 1)) {
                $depth += $c === 'q' ? 1 : -1;
                $min = min($min, $depth);
                $p++;
            } else {
                $p++;
            }
        }
        if ($arrays || $dicts) {
            throw $open($arrays ? 'an array' : 'a dictionary');
        }
        if ($depth - $min > 10000) {
            throw new \RuntimeException('a page saves its graphics state too deeply');
        }
        return [$min, $depth];
    }

    /**
     * Past an inline image (BI <dict> ID <data> EI), from just after "BI": the offset after "EI", or null. The data's
     * length comes from the image's size when it isn't compressed (as a viewer reads it); compressed, it runs to an
     * "EI" with white space before it.
     */
    private static function skipInlineImage(string $s, int $p, \Closure $token, \Closure $space): ?int
    {
        $n = strlen($s);
        $id = $p;
        while (($id = strpos($s, 'ID', $id)) !== false && !($token($id, 2) && $space($id + 2))) {
            $id += 2;
        }
        if ($id === false) {
            return null;
        }
        $dict = substr($s, $p, $id - $p);
        $num = fn(string $k) => preg_match('#/' . $k . '\s+(\d+)#', $dict, $m) ? (int) $m[1] : null;
        $w = $num('(?:W|Width)');
        $h = $num('(?:H|Height)');
        $bpc = $num('(?:BPC|BitsPerComponent)') ?? 1;
        $mask = (bool) preg_match('#/(?:IM|ImageMask)\s+true#', $dict);
        $comps = $mask ? 1 : (preg_match('#/(?:CS|ColorSpace)\s*/(\w+)#', $dict, $m) ? ['G' => 1, 'DeviceGray' => 1, 'RGB' => 3, 'DeviceRGB' => 3, 'CMYK' => 4, 'DeviceCMYK' => 4, 'I' => 1, 'Indexed' => 1][$m[1]] ?? null : null);
        $data = $id + 3; // "ID" and one white-space character
        if (!preg_match('#/(?:F|Filter)\b#', $dict) && $w && $h && $comps && $w * $h < 100_000_000) {
            $end = $data + intdiv($w * $comps * $bpc + 7, 8) * $h;
            $q = $end;
            while ($q < $n && $space($q)) {
                $q++;
            }
            return substr($s, $q, 2) === 'EI' && $token($q, 2) ? $q + 2 : null;
        }
        $q = $data;
        while (($q = strpos($s, 'EI', $q)) !== false && !($space($q - 1) && $token($q, 2))) {
            $q += 2;
        }
        return $q === false ? null : $q + 2;
    }

    /**
     * What goes before and after the page's own content so it can't change how the stamp is drawn: enough "q" before
     * it that its extra "Q"s can't pop past them, and enough "Q" after it to undo every "q" it leaves open (and any
     * clipping or transformation with it), back to the page's starting state.
     */
    private static function isolate(PdfDoc $doc, PdfDict $page): array
    {
        $content = $doc->pageContent($page);
        if ($content === null) {
            throw new \RuntimeException('a page uses a compression Align can\'t read');
        }
        [$min, $end] = self::balance($content);
        $open = 1 + max(0, -$min);
        // a new line first, so nothing at the very end of the page's content runs into our first "Q"
        return [str_repeat("q\n", $open), "\n" . str_repeat("Q\n", $open + $end)];
    }

    /**
     * Keeps only plain link annotations on a page (opening a web or email address, or another page), without an
     * appearance of their own (one could cover the page), and drops the page's automatic actions. Changes $d (the
     * page must be written again when it had annotations); returns whether anything other than a link was dropped.
     */
    private static function safeAnnots(PdfDoc $doc, array &$d): bool
    {
        $dropped = false;
        if (isset($d['AA'])) {
            unset($d['AA']);
            $dropped = true;
        }
        if (!isset($d['Annots'])) {
            return $dropped;
        }
        $keep = [];
        $list = $doc->resolve($d['Annots']);
        foreach (is_array($list) ? $list : [] as $a) {
            $ad = $doc->resolve($a);
            if (!$ad instanceof PdfDict || !self::plainLink($doc, $ad)) {
                $dropped = true;
                continue;
            }
            // written again inline, without an appearance (viewers draw nothing for a link without one)
            $keep[] = new PdfDict(array_diff_key($ad->d, ['AP' => 1, 'AS' => 1, 'PA' => 1, 'P' => 1]));
        }
        if ($keep) {
            $d['Annots'] = $keep;
        } else {
            unset($d['Annots']);
        }
        return $dropped;
    }

    /** A link annotation whose action (if any) opens an http(s) or mailto address or another page, and nothing else. */
    private static function plainLink(PdfDoc $doc, PdfDict $ad): bool
    {
        $sub = $doc->resolve($ad->d['Subtype'] ?? null);
        if (!$sub instanceof PdfName || $sub->n !== 'Link' || isset($ad->d['AA'])) {
            return false;
        }
        return self::plainAction($doc, $doc->resolve($ad->d['A'] ?? null));
    }

    /** No action, or one that opens an http(s) or mailto address or another page (and nothing after it). */
    private static function plainAction(PdfDoc $doc, mixed $act): bool
    {
        if ($act === null) {
            return true;
        }
        $kind = $act instanceof PdfDict ? $doc->resolve($act->d['S'] ?? null) : null;
        if (!$kind instanceof PdfName || isset($act->d['Next'])) {
            return false;
        }
        if ($kind->n === 'GoTo') {
            return true;
        }
        $uri = $kind->n === 'URI' ? $doc->resolve($act->d['URI'] ?? null) : null;
        return $uri instanceof \Align\Pdf\PdfStr && preg_match('#^(https?://|mailto:)#i', trim($uri->s)) === 1;
    }

    /** The catalog without document scripts, automatic actions, forms, bookmarks or attached files; null if it has none. */
    private static function safeCatalog(PdfDoc $doc): ?string
    {
        $cat = $doc->catalog()->d;
        $changed = false;
        foreach (['AA', 'AcroForm'] as $k) {
            if (isset($cat[$k])) {
                unset($cat[$k]);
                $changed = true;
            }
        }
        // bookmarks (Word makes them from headings) stay, unless one runs something other than a plain link
        if (isset($cat['Outlines']) && !self::plainOutlines($doc, $doc->resolve($cat['Outlines']))) {
            unset($cat['Outlines']);
            $changed = true;
        }
        if (isset($cat['OpenAction']) && !is_array($doc->resolve($cat['OpenAction']))) {
            unset($cat['OpenAction']); // an action, not just "open at this page"
            $changed = true;
        }
        $names = $doc->resolve($cat['Names'] ?? null);
        if ($names instanceof PdfDict && (isset($names->d['JavaScript']) || isset($names->d['EmbeddedFiles']))) {
            $cat['Names'] = new PdfDict(array_diff_key($names->d, ['JavaScript' => 1, 'EmbeddedFiles' => 1]));
            $changed = true;
        }
        return $changed ? PdfDoc::ser(new PdfDict($cat)) : null;
    }

    /** Whether every bookmark only opens a page or a plain address (a bounded walk of the outline tree). */
    private static function plainOutlines(PdfDoc $doc, mixed $root): bool
    {
        $todo = $root instanceof PdfDict ? [$root->d['First'] ?? null] : [];
        $seen = 0;
        while ($todo) {
            $item = $doc->resolve(array_pop($todo));
            if ($item === null) {
                continue;
            }
            if (!$item instanceof PdfDict || ++$seen > 10000 || !self::plainAction($doc, $doc->resolve($item->d['A'] ?? null))) {
                return false;
            }
            array_push($todo, $item->d['Next'] ?? null, $item->d['First'] ?? null);
        }
        return true;
    }

    /** New pages (the certificate) at the end of the original's page tree. */
    private static function appendPages(PdfDoc $doc, Pdf $pdf, int $first, array &$objects, int &$next, array $fonts, array $images): void
    {
        [$rootRef, $root] = $doc->pagesRoot();
        $kids = (array) $doc->resolve($root->d['Kids'] ?? []);
        for ($i = $first; $i < $pdf->pageCount(); $i++) {
            [$w, $h] = $pdf->pageSize($i);
            $objects[$cnum = $next++] = Pdf::streamBody($pdf->pageContent($i));
            $objects[$pnum = $next++] = PdfDoc::ser(new PdfDict(['Type' => new PdfName('Page'), 'Parent' => $rootRef,
                'MediaBox' => $box = [0, 0, round($w, 2), round($h, 2)], 'CropBox' => $box, 'Rotate' => 0,
                'Resources' => new PdfDict(['Font' => new PdfDict($fonts), 'XObject' => new PdfDict($images)]), 'Contents' => new PdfRef($cnum)]));
            $kids[] = new PdfRef($pnum);
        }
        $rd = $root->d;
        $rd['Kids'] = $kids;
        $rd['Count'] = count($doc->pages()) + ($pdf->pageCount() - $first);
        $objects[$rootRef->num] = PdfDoc::ser(new PdfDict($rd));
    }

    /** The page's resources plus Align's fonts and images. */
    private static function resources(PdfDoc $doc, PdfDict $res, array $fonts, array $images): PdfDict
    {
        $d = $res->d;
        foreach (['Font' => $fonts, 'XObject' => $images] as $k => $add) {
            if (!$add) {
                continue;
            }
            $cur = $doc->resolve($d[$k] ?? null);
            $d[$k] = new PdfDict(($cur instanceof PdfDict ? $cur->d : []) + $add);
        }
        return new PdfDict($d);
    }

    /** One box onto the page. */
    private static function draw(Pdf $pdf, array $it, bool $final): void
    {
        [$x, $y, $w, $h] = [$it['x'], $it['y'], $it['w'], $it['h']];
        if (in_array($it['kind'], ['sig', 'photo'], true) && $it['img']) {
            $bytes = (string) base64_decode(substr($it['img'], strpos($it['img'], ',') + 1));
            if (($img = $pdf->addImage($bytes)) !== null) {
                [$iw, $ih] = $pdf->imageSize($img);
                $s = min($w / max(1, $iw), $h / max(1, $ih));
                $dw = $iw * $s;
                $dh = $ih * $s;
                $dx = $it['align'] === 'center' ? ($w - $dw) / 2 : ($it['align'] === 'right' ? $w - $dw : 0);
                $pdf->image($img, $x + $dx, $y + ($h - $dh) / 2, $dw, $dh);
            }
            return;
        }
        $text = $it['text'];
        $font = 'Helvetica';
        $color = self::INK;
        $size = (float) $it['size'];
        if ($it['kind'] === 'sig' || !empty($it['sig'])) {
            $font = 'Times-BoldItalic';
            $color = self::SIG;
            $size = max($size, min(26, $h * 0.75));
        } elseif ($it['kind'] === 'label') {
            if ($final) {
                return;
            }
            $text = '[' . $it['label'] . ']';
            $color = self::GRAY;
            $size = min($size, 9);
        } elseif ($it['kind'] !== 'text' || $text === '') {
            return;
        }
        // Long text: wrap into the box when it's tall enough, and shrink until it fits. A value is never cut short
        // (a signed contract can't lose part of a date or a name): if it still doesn't fit, it runs past the box.
        // Only the builder's labels are shortened.
        $cut = $it['kind'] === 'label';
        $multi = $h >= $size * 2.4;
        $fit = function (float $size) use ($text, $font, $w, $h, $multi): array {
            $lines = $multi && Pdf::width($text, $font, $size) > $w ? self::wrap($text, $font, $size, $w) : [$text];
            $widest = max(array_map(fn($l) => Pdf::width($l, $font, $size), $lines));
            return [$lines, $widest <= $w && count($lines) * $size * 1.2 <= max($h, $size * 1.2)];
        };
        [$lines, $ok] = $fit($size);
        while (!$ok && $size > 6.5) {
            $size = max(6.5, $size - 0.5);
            [$lines, $ok] = $fit($size);
        }
        $lh = $size * 1.2;
        if ($cut) {
            $maxLines = max(1, (int) floor($h / $lh));
            if (count($lines) > $maxLines) {
                $lines = array_slice($lines, 0, $maxLines);
                $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1]) . '…';
            }
        }
        $top = count($lines) === 1 ? $y + ($h - $size) / 2 + $size * 0.8 : $y + $size * 0.95;
        foreach ($lines as $i => $ln) {
            while ($cut && Pdf::width($ln, $font, $size) > $w && mb_strlen($ln) > 1) {
                $ln = mb_substr($ln, 0, -2) . '…';
            }
            $lw = Pdf::width($ln, $font, $size);
            $dx = $it['align'] === 'center' ? ($w - $lw) / 2 : ($it['align'] === 'right' ? $w - $lw : 0);
            $pdf->text($x + $dx, $top + $i * $lh, $ln, $font, $size, $color);
        }
    }

    private static function wrap(string $text, string $font, float $size, float $w): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) ?: [] as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && Pdf::width($try, $font, $size) > $w) {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $out[] = $line;
        }
        return $out;
    }
}
