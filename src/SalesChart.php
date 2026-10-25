<?php
/**
 * R10 UX10: the daily revenue of one currency as an inline SVG bar chart for the admin reports page. Server-side, no
 * script (the admin CSP stays nonce-only): one bar per day in date order, each with a <title> tooltip, and a visible
 * text summary that names the image. The table under it remains the full data for screen readers.
 */
final class SalesChart {
    private const WIDTH = 720;
    private const HEIGHT = 180;
    private const AXIS = 16;

    /** @param array<string,array{orders:int,revenue:int}> $days day (Y-m-d) => totals, any order */
    public static function dailyRevenue(array $days, string $currency): string {
        if ($days === []) return '';
        ksort($days);
        $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $max = max(1, ...array_map(static fn(array $row): int => (int)$row['revenue'], $days));
        $slot = self::WIDTH / count($days);
        $gap = $slot > 6 ? 1.0 : 0.0;
        $plot = self::HEIGHT - self::AXIS;
        $bars = '';
        $index = 0;
        $peakDay = (string)array_key_first($days);
        $total = 0;
        foreach ($days as $day => $row) {
            $revenue = (int)$row['revenue'];
            $total += $revenue;
            if ($revenue > (int)$days[$peakDay]['revenue']) $peakDay = (string)$day;
            $height = $revenue > 0 ? max(1.0, round($revenue / $max * ($plot - 4), 1)) : 0.0;
            $title = $day . ': ' . I18n::price($revenue, $currency) . ' (' . I18n::format('admin.reports.chart_orders', ['count' => (int)$row['orders']]) . ')';
            $bars .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="#2563eb"><title>%s</title></rect>',
                $index * $slot + $gap / 2, $plot - $height, max(0.5, $slot - $gap), $height, $h($title));
            $index++;
        }
        $first = (string)array_key_first($days);
        $last = (string)array_key_last($days);
        $label = I18n::format('admin.reports.chart_label', [
            'currency' => strtoupper($currency), 'from' => $first, 'to' => $last, 'total' => I18n::price($total, $currency),
            'peak' => I18n::price((int)$days[$peakDay]['revenue'], $currency), 'peak_day' => $peakDay,
        ]);
        $id = 'report-chart-' . preg_replace('/[^a-z0-9]/', '', strtolower($currency));
        $text = static fn(int $x, string $anchor, string $value): string => sprintf(
            '<text x="%d" y="%d" font-size="11" fill="#475569" text-anchor="%s">%s</text>', $x, self::HEIGHT - 3, $anchor, $h($value));
        return '<figure class="report-chart" style="margin:.5rem 0 1rem">'
            . sprintf('<svg viewBox="0 0 %d %d" width="100%%" style="max-width:%dpx;height:auto" role="img" aria-labelledby="%s" data-report-chart="%s">',
                self::WIDTH, self::HEIGHT, self::WIDTH, $id, $h($currency))
            . sprintf('<line x1="0" y1="%d" x2="%d" y2="%d" stroke="#cbd5e1"/>', $plot, self::WIDTH, $plot)
            . $bars . $text(0, 'start', $first) . $text(self::WIDTH, 'end', $last)
            . '</svg><figcaption id="' . $id . '" class="muted">' . $h($label) . '</figcaption></figure>';
    }
}
