<?php
declare(strict_types=1);

namespace Align\Reports;

/** Small HTML builders shared by the report templates. All text is escaped here. */
final class Ui
{
    /** Section header. $num is shown in the QBR pack ("01") and left out elsewhere. */
    public static function head(string $title, ?string $num = null, string $note = '', bool $pageBreak = false): string
    {
        return '<div class="sec-head' . ($pageBreak ? ' page-break' : '') . '">' . ($num ? '<span class="sec-num">' . e($num) . '</span>' : '')
            . '<h2>' . e($title) . '</h2>' . ($note !== '' ? '<span class="sec-note">' . e($note) . '</span>' : '') . '</div>';
    }

    /** KPI tile. $tone: '', bad, warn, ok, muted. */
    public static function kpi(string $value, string $label, string $sub = '', string $tone = ''): string
    {
        return '<div class="kpi' . ($tone ? ' t-' . e($tone) : '') . '"><div class="kpi-val">' . e($value) . '</div><div class="kpi-lbl">' . e($label) . '</div>'
            . ($sub !== '' ? '<div class="kpi-sub">' . e($sub) . '</div>' : '') . '</div>';
    }

    public static function pill(string $label, string $tone = 'muted'): string
    {
        return '<span class="pill pill-' . e($tone) . '">' . e($label) . '</span>';
    }

    /** Stacked horizontal bar. $parts: [[value, class], ...] */
    public static function hbar(array $parts, ?float $total = null): string
    {
        $total ??= array_sum(array_column($parts, 0));
        $h = '<div class="hbar">';
        foreach ($parts as [$v, $cls]) {
            if ($total > 0 && $v > 0) {
                $h .= '<i class="' . e($cls) . '" style="width:' . round($v / $total * 100, 2) . '%"></i>';
            }
        }
        return $h . '</div>';
    }

    /** <colgroup> from relative weights (null = column not shown); widths always add up to 100%. */
    public static function cols(array $weights): string
    {
        $w = array_filter($weights, fn($v) => $v !== null && $v > 0);
        $sum = array_sum($w) ?: 1;
        return '<colgroup>' . implode('', array_map(fn($v) => '<col style="width:' . round($v / $sum * 100, 2) . '%">', $w)) . '</colgroup>';
    }

    /** Status tone of a lifecycle device mapped to a pill tone. */
    public static function tone(string $t): string
    {
        return ['bad' => 'bad', 'warn' => 'warn', 'ok' => 'ok'][$t] ?? 'muted';
    }

    /** Compact currency for chart labels: $12.4k, $1.2M. */
    public static function k(float $v): string
    {
        return \Align\Fmt::moneyShort($v);
    }

    /**
     * Simple vertical bar chart (SVG) for 12 quarters, grouped into 3 years.
     * $bars: list of ['label' => 'Q1', 'value' => float, 'year' => 0..2, 'past' => bool, 'current' => bool]
     */
    public static function quarterChart(array $bars, array $yearLabels, bool $showValues = true, ?callable $fmt = null): string
    {
        $fmt ??= fn(float $v) => self::k($v);
        $n = count($bars);
        if (!$n) {
            return '';
        }
        $w = 700; $h = 150; $top = 26; $bottom = 30;
        $slot = $w / $n; $bw = $slot * .58;
        $max = max(1.0, ...array_map(fn($b) => (float) $b['value'], $bars));
        // Optional stacked parts per bar: 'parts' => [[value, colour], ...] (bottom first)
        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . ($h + $top + $bottom) . '" role="img" aria-label="By quarter">';
        foreach ($bars as $i => $b) {
            if (!empty($b['current'])) {
                $svg .= '<rect class="now" x="' . round($i * $slot, 1) . '" y="' . ($top - 6) . '" width="' . round($slot, 1) . '" height="' . ($h + 12) . '" rx="4"/>';
            }
        }
        for ($y = 1; $y < 3; $y++) {
            $x = round($y * 4 * $slot, 1);
            $svg .= '<line class="sep" x1="' . $x . '" x2="' . $x . '" y1="4" y2="' . ($h + $top + $bottom - 4) . '"/>';
        }
        foreach ($yearLabels as $y => $lbl) {
            $svg .= '<text class="ylbl" x="' . round(($y * 4 + 2) * $slot, 1) . '" y="12" text-anchor="middle">' . e($lbl) . '</text>';
        }
        $svg .= '<line class="axis" x1="0" x2="' . $w . '" y1="' . ($h + $top) . '" y2="' . ($h + $top) . '"/>';
        foreach ($bars as $i => $b) {
            $v = (float) $b['value'];
            $bh = $v > 0 ? max(2, $v / $max * ($h - 6)) : 0;
            $x = $i * $slot + ($slot - $bw) / 2;
            $y = $h + $top - $bh;
            $fill = !empty($b['past']) ? '#c3cad3' : 'var(--brand)';
            if ($bh && !empty($b['parts'])) {
                $yy = $h + $top;
                foreach ($b['parts'] as [$pv, $pc]) {
                    $ph = $v > 0 ? $pv / $v * $bh : 0;
                    if ($ph <= 0) {
                        continue;
                    }
                    $yy -= $ph;
                    $svg .= '<rect x="' . round($x, 1) . '" y="' . round($yy, 1) . '" width="' . round($bw, 1) . '" height="' . round($ph, 1) . '" style="fill:' . (!empty($b['past']) ? '#c3cad3' : (preg_match('/^#[0-9a-fA-F]{3,8}$|^var\(--[a-z0-9-]+\)$/', (string) $pc) ? $pc : '#888')) . '"/>';
                }
                if ($showValues) {
                    $svg .= '<text class="val" x="' . round($x + $bw / 2, 1) . '" y="' . round($y - 3, 1) . '" text-anchor="middle">' . e($fmt($v)) . '</text>';
                }
            } elseif ($bh) {
                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '" rx="2.5" style="fill:' . $fill . '"/>';
                if ($showValues) {
                    $svg .= '<text class="val" x="' . round($x + $bw / 2, 1) . '" y="' . round($y - 3, 1) . '" text-anchor="middle">' . e($fmt($v)) . '</text>';
                }
            }
            $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h + $top + 13) . '" text-anchor="middle">' . e($b['label']) . '</text>';
            if (isset($b['sub'])) {
                $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h + $top + 24) . '" text-anchor="middle">' . e($b['sub']) . '</text>';
            }
        }
        return $svg . '</svg>';
    }

    /**
     * Month-by-month share of SLA targets met (one scale, 0-100%), with the goal as a dashed line.
     * Bars are coloured by status against the goal; the ticket count sits under each month.
     * $months: Sla::monthly(). Text uses currentColor so it works on screen (light/dark) and in print.
     */
    public static function slaChart(array $months, int $target): string
    {
        $n = count($months);
        if (!$n) {
            return '';
        }
        $w = 700; $h = 120; $top = 26; $bottom = 32;
        $slot = $w / $n; $bw = min(34, $slot * .56);
        $y = fn(float $p) => $top + $h - $p / 100 * $h;
        $col = ['success' => '#2f9e62', 'warning' => '#d69a16', 'danger' => '#d64541'];
        $svg = '<svg class="chart sla-chart" viewBox="0 0 ' . $w . ' ' . ($h + $top + $bottom) . '" role="img" aria-label="Share of SLA targets met by month">';
        foreach ([0, 50, 100] as $g) {
            $svg .= '<line x1="30" x2="' . $w . '" y1="' . round($y($g), 1) . '" y2="' . round($y($g), 1) . '" style="stroke:currentColor;stroke-opacity:.12;stroke-width:1"/>'
                . '<text x="0" y="' . round($y($g) + 3.5, 1) . '" style="fill:currentColor;fill-opacity:.6;font-size:10px">' . $g . '%</text>';
        }
        $i = 0;
        foreach ($months as $ym => $m) {
            $x = 30 + $i * (($w - 30) / $n) + ((($w - 30) / $n) - $bw) / 2;
            $cx = $x + $bw / 2;
            $p = $m['overall_pct'];
            $label = date('M', strtotime($ym . '-01'));
            $tip = \Align\Fmt::date($ym . '-01', 'monthlong') . ': ' . ($p === null ? 'no SLA results' : self::pctText($p) . ' of targets met (' . ($m['resp_met'] + $m['res_met']) . ' of ' . ($m['resp_met'] + $m['resp_missed'] + $m['res_met'] + $m['res_missed']) . ')') . ' · ' . $m['tickets'] . ' ticket' . ($m['tickets'] == 1 ? '' : 's');
            $svg .= '<g><title>' . e($tip) . '</title><rect x="' . round($x - 3, 1) . '" y="0" width="' . round($bw + 6, 1) . '" height="' . ($h + $top + $bottom) . '" style="fill:transparent"/>';
            if ($p !== null) {
                $bh = max(2, $p / 100 * $h);
                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($top + $h - $bh, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '" rx="3" style="fill:' . $col[\Align\Service\Sla::tone($p)] . '"/>'
                    . '<text x="' . round($cx, 1) . '" y="' . round($top + $h - $bh - 3, 1) . '" text-anchor="middle" style="fill:currentColor;font-size:9.5px">' . e((string) round($p)) . '</text>';
            } else {
                $svg .= '<text x="' . round($cx, 1) . '" y="' . ($top + $h - 4) . '" text-anchor="middle" style="fill:currentColor;fill-opacity:.45;font-size:10px">—</text>';
            }
            $svg .= '<text x="' . round($cx, 1) . '" y="' . ($top + $h + 13) . '" text-anchor="middle" style="fill:currentColor;font-size:10px">' . e($label) . '</text>'
                . '<text x="' . round($cx, 1) . '" y="' . ($top + $h + 25) . '" text-anchor="middle" style="fill:currentColor;fill-opacity:.55;font-size:9px">' . (int) $m['tickets'] . '</text></g>';
            $i++;
        }
        $ty = round($y($target), 1);
        $svg .= '<line x1="30" x2="' . $w . '" y1="' . $ty . '" y2="' . $ty . '" style="stroke:currentColor;stroke-opacity:.55;stroke-width:1.5;stroke-dasharray:5 4"/>'
            . '<line x1="' . ($w - 78) . '" x2="' . ($w - 60) . '" y1="6" y2="6" style="stroke:currentColor;stroke-opacity:.55;stroke-width:1.5;stroke-dasharray:5 4"/>'
            . '<text x="' . ($w - 2) . '" y="9.5" text-anchor="end" style="fill:currentColor;fill-opacity:.75;font-size:10px">Goal ' . $target . '%</text>';
        return $svg . '</svg>';
    }

    private static function pctText(float $p): string
    {
        return \Align\Fmt::trim($p, 1) . '%';
    }
}
