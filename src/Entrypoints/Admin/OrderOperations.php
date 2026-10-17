<?php
/** Bulk order operations: CSV export, status change through OrderWorkflow and delivery notes. */

/** POST admin.php?p=orders&action=export — the filtered orders of the current store as CSV. */
function handleOrdersExport(): void {
    Security::adminRequire('orders.view');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="orders-' . StoreContext::current()->id() . '-' . date('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    try {
        OrderExport::writeCsv($out, $_POST);
    } catch (CsvExportTruncated) {
        CsvExport::abortResponse(); // logged + incident already; an already started download stays incomplete
    }
    fclose($out);
    exit;
}

/** @return int[] order ids posted as order_ids[] (non-array or nested input yields none) */
function postedOrderIds(): array {
    return Security::intList($_POST['order_ids'] ?? []);
}

function handleOrdersBulkStatus(): void {
    Security::adminRequire('orders.edit');
    $ids = postedOrderIds();
    $to = Security::str($_POST['new_status'] ?? '');
    $note = trim(Security::str($_POST['note'] ?? ''));
    $back = 'admin.php?' . http_build_query(['p' => 'orders', 'status' => Security::str($_POST['status'] ?? ''), 'payment_status' => Security::str($_POST['payment_status'] ?? '')]);
    // The form's empty "choose a status" option; any other unusable value is refused per order below.
    if (!$ids || ($_POST['new_status'] ?? '') === '') {
        setFlash('err', I18n::t($ids ? 'admin.orders.bulk.choose_status' : 'admin.orders.bulk.none_selected'));
        header('Location: ' . $back);
        exit;
    }
    $result = OrderWorkflow::bulkTransition($ids, $to, $note !== '' ? $note : 'Admin bulk status change', Security::adminCan('orders.refund'));
    $message = strtr(I18n::t('admin.orders.bulk.changed'), ['{count}' => (string)count($result['changed']), '{status}' => $to]);
    if ($result['changed']) $message .= ' (' . implode(', ', $result['changed']) . ')';
    if ($result['rejected']) {
        $reasons = [];
        foreach ($result['rejected'] as $orderId => $reason) $reasons[] = '#' . $orderId . ': ' . I18n::t('admin.orders.reason.' . $reason);
        $message .= ' ' . strtr(I18n::t('admin.orders.bulk.rejected'), ['{count}' => (string)count($result['rejected'])]) . ' ' . implode('; ', $reasons);
    }
    setFlash($result['rejected'] ? 'err' : 'ok', $message);
    header('Location: ' . $back);
    exit;
}

/** GET ?p=delivery_note&id=N (order detail) or POST order_ids[] (bulk); format=html|pdf. Never shows prices. */
function pageDeliveryNotes(): void {
    Security::adminRequire('orders.print');
    $posted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($posted && !Security::csrfValidate()) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }
    $notes = DeliveryNote::forOrders($posted ? postedOrderIds() : [Security::int($_GET['id'] ?? 0)]);
    if (!$notes) {
        http_response_code(404);
        echo esc(I18n::t('admin.delivery_note.none'));
        return;
    }
    $format = Security::str($posted ? ($_POST['format'] ?? '') : ($_GET['format'] ?? ''));
    $name = 'delivery-notes-' . (count($notes) === 1 ? preg_replace('/[^A-Za-z0-9_-]/', '', (string)$notes[0]['order']['order_number']) : date('Y-m-d'));
    if ($format === 'pdf') {
        $pdf = DeliveryNote::pdf($notes);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $name . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        return;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo DeliveryNote::html($notes);
}
