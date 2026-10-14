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
/** Label of a stored code in the admin language ($prefix . $code), or the code itself when no label exists for it. */
function adminCodeLabel(string $prefix, string $code): string {
    $text = I18n::t($prefix . $code);
    return $text === $prefix . $code ? $code : $text;
}
function pagination(int $current, int $total, string $baseUrl): void {
    if ($total <= 1) return;
    echo '<div class="pagination">';
    for ($i = 1; $i <= $total; $i++) {
        $sep = str_contains($baseUrl, '?') ? '&' : '?';
        $active = $i === $current ? ' btn-primary' : '';
        echo '<a href="' . esc($baseUrl . $sep . 'page=' . $i) . '" class="btn btn-sm' . $active . '">' . $i . '</a>';
    }
    echo '</div>';
}

// ============================================================
// Warehouses
// ============================================================
