# Changelog

## Unreleased

### Added
- **Merchant low / critical stock e-mail** (`StockAlerts`, worker job `stock_alert`).
  - Two thresholds `low` / `critical` (inclusive; 0 = always critical/sold out). Global default in
    config `stock_alerts`, per-product override in the admin product form and in CSV import/export
    (`stock_low_threshold`, `stock_critical_threshold`; empty = config default).
  - Source of truth is `warehouse_stock` (available = qty − reserved over the store's warehouses),
    not `products.stock`. Only active physical products with a warehouse row are tracked.
  - Every ledger movement (checkout reservation, shipment, cancellation/release, return, receipt,
    manual edit, CSV import, feed sync) marks the SKU; one `stock_alert` job per request is queued
    at shutdown, so checkout never waits for or fails on e-mail.
  - E-mail only on a downward crossing (ok → low → critical → 0), state per SKU + store in
    `stock_alert_state`; re-arms after restock above `low`. All SKUs crossing in one worker run go
    into one e-mail. Failed delivery rolls the state back and the job retries.
  - Optional daily digest of everything at/below `low` (`digest_hour`, default off).
  - Template `merchant_stock_low` in sk, cs, en, de, hu, pl, es. Recipients: `stock_alerts.recipients`,
    default `merchant_notifications.recipients`, then `admin_email`.
  - Admin dashboard and the new product-list filter "Low stock" use the same thresholds and
    availability (no hardcoded `< 10`), coloured low / critical.
- Admin product stock edits and CSV `stock` are booked on the default warehouse as ledger
  adjustments (`Warehouse::setAvailable`), keeping `products.stock` in sync.

### Changed
- `cli/worker.php` `handleStockAlert` now delegates to `StockAlerts::process()`; the old
  `products.stock` scan that only wrote `logs/stock-alerts.log` is removed. `Products::lowStockSummary()`
  is replaced by `StockAlerts::summary()`.

### Migration
- `20261008090000_stock_alerts` (idempotent): `products.stock_low_threshold`,
  `products.stock_critical_threshold`, tables `stock_alert_state`, `stock_alert_digests`.
