<?php
/** Shared admin presentation helpers. */

function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function flash(): void {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        echo '<div class="flash flash-' . esc($f['type']) . '">' . esc($f['msg']) . '</div>';
        unset($_SESSION['flash']);
    }
}
/**
 * Shutdown guard for the buffered admin page: a fatal error mid-render (memory or time limit, uncaught error) discards
 * the half-rendered page and answers 500, instead of HTTP 200 with a truncated table that looks like real data.
 */
function adminFatalPageGuard(): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    try {
        $message = I18n::t('admin.error.page_failed');
    } catch (Throwable) {
        $message = 'The page could not be rendered.';
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>500</title></head><body><h1>500</h1><p>' . esc($message) . '</p></body></html>';
}
/** Label of a stored code in the admin language ($prefix . $code), or the code itself when no label exists for it. */
function adminCodeLabel(string $prefix, string $code): string {
    $text = I18n::t($prefix . $code);
    return $text === $prefix . $code ? $code : $text;
}
/** Windowed pager: first, last and two pages either side of the current one, gaps as "…" (never one link per page). */
function pagination(int $current, int $total, string $baseUrl): void {
    if ($total <= 1) return;
    $sep = str_contains($baseUrl, '?') ? '&' : '?';
    $current = max(1, min($current, $total));
    $pages = array_unique(array_merge([1, $total], range(max(1, $current - 2), min($total, $current + 2))));
    sort($pages);
    echo '<div class="pagination">';
    $previous = 0;
    foreach ($pages as $i) {
        if ($i - $previous > 1) echo '<span class="muted">…</span>';
        echo '<a href="' . esc($baseUrl . $sep . 'page=' . $i) . '" class="btn btn-sm' . ($i === $current ? ' btn-primary' : '') . '">' . $i . '</a>';
        $previous = $i;
    }
    echo '</div>';
}

// ============================================================
// Warehouses
// ============================================================
