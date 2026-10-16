<?php

// CR-M16 (code review 2026-10-15): handleInvoiceIssue ignored MailService::send()'s result, so a
// failed invoice e-mail completed the job and the customer never got the invoice. A failed send
// must fail the job so the queue retries it (invoice issue itself is idempotent).
echo "\n--- CR-M16: a failed invoice e-mail fails the invoice_issue job ---\n";
require_once TEST_CLI_DIR . '/../cli/worker.php';
$imfDb = Db::get();
$imfOrder = $imfDb->query("SELECT o.id, o.customer_email FROM orders o JOIN invoices i ON i.order_id = o.id
    WHERE o.store_id = 'default' ORDER BY o.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_true(is_array($imfOrder), 'CR-M16 fixture: an invoiced order exists');
$imfMailer = static fn(string $status): MailAdapter => new class($status) implements MailAdapter {
    public function __construct(private string $status) {}
    public function send(array $msg): array { return ['status' => $this->status, 'message' => $this->status === 'sent' ? '' : 'SMTP 451', 'raw' => null]; }
    public function adapterId(): string { return 'cr-m16'; }
};
$imfEmail = 'cr-m16-' . bin2hex(random_bytes(4)) . '@example.test';
try {
    $imfDb->prepare('UPDATE orders SET customer_email = ? WHERE id = ?')->execute([$imfEmail, $imfOrder['id']]);
    MailService::useAdapter($imfMailer('failed'));
    $imfError = null;
    try {
        handleInvoiceIssue(['order_id' => (int)$imfOrder['id']], []);
    } catch (Throwable $e) {
        $imfError = $e;
    }
    assert_true($imfError instanceof RuntimeException, 'CR-M16: a failed invoice e-mail throws, so the job is retried instead of completed');
    $imfLog = $imfDb->prepare("SELECT status FROM email_log WHERE recipient = ? AND template = 'invoice_issued' ORDER BY id DESC LIMIT 1");
    $imfLog->execute([$imfEmail]);
    assert_equals('failed', $imfLog->fetchColumn(), 'CR-M16: the failed attempt is logged');

    MailService::useAdapter($imfMailer('sent'));
    $imfError = null;
    try {
        handleInvoiceIssue(['order_id' => (int)$imfOrder['id']], []);
    } catch (Throwable $e) {
        $imfError = $e;
    }
    assert_true($imfError === null, 'CR-M16: the retry that delivers the e-mail completes the job');
} finally {
    MailService::useAdapter(null);
    $imfDb->prepare('UPDATE orders SET customer_email = ? WHERE id = ?')->execute([$imfOrder['customer_email'] ?? '', $imfOrder['id'] ?? 0]);
}
