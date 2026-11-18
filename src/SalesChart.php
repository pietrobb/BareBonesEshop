<?php
/**
 * R10 UX10: the daily revenue of one currency as an inline SVG bar chart for the admin reports page and dashboard.
 * Server-side, no script (the admin CSP stays nonce-only): one bar per day in date order, each with a <title> tooltip,
 * value gridlines, day labels, a marker on days without revenue and the peak amount above its bar, so that even a
 * single day of sales reads as a chart rather than an empty plot. A visible text summary names the image; the table
 * under it on the reports page remains the full data for screen readers.
 */
final class SalesChart {
    private const WIDTH = 720;
    private const HEIGHT = 210;
    private const GUTTER = 80;  // left of the plot: value labels
    private const TOP = 28;     // above the plot: the peak label
    private const AXIS = 28;    // below the plot: day labels
    private const GRID = 4;     // gridlines above the baseline
    private const LABEL_WIDTH = 44;

    /** @param array<string,array{orders:int,revenue:int}> $days day (Y-m-d) => totals, any order */
    public static function dailyRevenue(array $days, string $currency): string {
        if ($days === []) return '';
        ksort($days);
        $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = static fn(float $x, float $y, string $anchor, string $value, string $extra = ''): string => sprintf(
            '<text x="%.1f" y="%.1f" font-size="11" fill="#64748b" text-anchor="%s"%s>%s</text>', $x, $y, $anchor, $extra, $h($value));
        $count = count($days);
        $peakDay = (string)array_key_first($days);
        $total = 0;
        foreach ($days as $day => $row) {
            $total += (int)$row['revenue'];
            if ((int)$row['revenue'] > (int)$days[$peakDay]['revenue']) $peakDay = (string)$day;
        }
        $peak = (int)$days[$peakDay]['revenue'];
        $scale = self::niceCeiling(max(1, $peak));
        $plotWidth = self::WIDTH - self::GUTTER;
        $base = self::HEIGHT - self::AXIS;
        $plotHeight = $base - self::TOP;
        $slot = $plotWidth / $count;
        $barWidth = max(0.5, min(36.0, $slot * 0.62));

        $grid = '';
        for ($i = 0; $i <= self::GRID; $i++) {
            $y = $base - $plotHeight * $i / self::GRID;
            $grid .= sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="%s"/>', self::GUTTER, $y, self::WIDTH, $y, $i === 0 ? '#cbd5e1' : '#eef1f5')
                . $text(self::GUTTER - 8, $y + 4, 'end', self::axisPrice((int)round($scale * $i / self::GRID), $scale, $currency), ' class="chart-axis"');
        }
        // Day labels from the most recent day backwards, as many as fit side by side.
        $labelStep = max(1, (int)ceil(self::LABEL_WIDTH / $slot));
        $bars = $marks = $labels = $peakLabel = '';
        $index = 0;
        foreach ($days as $day => $row) {
            $day = (string)$day;
            $revenue = (int)$row['revenue'];
            $center = self::GUTTER + ($index + 0.5) * $slot;
            $height = $revenue > 0 ? max(1.0, round($revenue / $scale * $plotHeight, 1)) : 0.0;
            $title = I18n::date($day) . ': ' . I18n::price($revenue, $currency) . ' (' . I18n::format('admin.reports.chart_orders', ['count' => (int)$row['orders']]) . ')';
            $bars .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="%.1f" fill="#2563eb"><title>%s</title></rect>',
                $center - $barWidth / 2, $base - $height, $barWidth, $height, min(3.0, $barWidth / 2), $h($title));
            if ($revenue === 0 && $slot >= 12) $marks .= sprintf('<circle cx="%.1f" cy="%d" r="2.5" fill="#cbd5e1"/>', $center, $base);
            if (($count - 1 - $index) % $labelStep === 0) {
                // A phone keeps only the first and the last day label and no value labels (the admin stylesheet hides the rest).
                $class = 'chart-day' . ($index < $labelStep ? ' chart-day-key' : '') . ($index === $count - 1 ? ' chart-day-key chart-day-last' : '');
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
                $labels .= $text($center, self::HEIGHT - 4, 'middle', $date !== false ? $date->format(I18n::t('format.day_month')) : $day, ' class="' . $class . '"');
            }
            if ($day === $peakDay && $peak > 0) {
                $anchor = $center > self::WIDTH - 40 ? 'end' : ($center < self::GUTTER + 40 ? 'start' : 'middle');
                $x = $anchor === 'end' ? $center + $barWidth / 2 : ($anchor === 'start' ? $center - $barWidth / 2 : $center);
                $peakLabel = $text($x, $base - $height - 6, $anchor, I18n::price($peak, $currency), ' font-weight="600" class="chart-peak"');
            }
            $index++;
        }
        $first = (string)array_key_first($days);
        $last = (string)array_key_last($days);
        $label = I18n::format('admin.reports.chart_label', [
            'currency' => strtoupper($currency), 'from' => I18n::date($first), 'to' => I18n::date($last), 'total' => I18n::price($total, $currency),
            'peak' => I18n::price($peak, $currency), 'peak_day' => I18n::date($peakDay),
        ]);
        $id = 'report-chart-' . preg_replace('/[^a-z0-9]/', '', strtolower($currency));
        return '<figure class="report-chart" style="margin:.5rem 0 1rem">'
            . sprintf('<svg viewBox="0 0 %d %d" width="100%%" style="max-width:%dpx;height:auto" role="img" aria-labelledby="%s" data-report-chart="%s">',
                self::WIDTH, self::HEIGHT, self::WIDTH, $id, $h($currency))
            . $grid . $marks . $bars . $peakLabel . $labels
            . '</svg><figcaption id="' . $id . '" class="muted">' . $h($label) . '</figcaption></figure>';
    }

    /** The smallest 1/2/2.5/5 × 10^n at or above $value: round gridline steps. */
    private static function niceCeiling(int $value): int {
        $magnitude = 10 ** (int)floor(log10($value));
        foreach ([1, 2, 2.5, 5] as $step) {
            if ($step * $magnitude >= $value) return (int)round($step * $magnitude);
        }
        return 10 * $magnitude;
    }

    /** A gridline amount: whole currency units when every step of the scale is whole, otherwise the full price. */
    private static function axisPrice(int $cents, int $scale, string $currency): string {
        if ($scale % (self::GRID * 100) !== 0) return I18n::price($cents, $currency);
        $format = I18n::priceFormat($currency);
        $amount = number_format($cents / 100, 0, $format['decimal'], $format['thousands']);
        return $format['position'] === 'before' ? $format['symbol'] . $amount : $amount . ' ' . $format['symbol'];
    }
}
