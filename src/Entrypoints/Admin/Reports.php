<?php
/** Sales reports: totals/AOV, daily trend and top products of the current store, one block per currency. */

/** Today's revenue of the current store, one line per currency (amounts in different currencies are never added up). */
function adminRevenueToday(): string {
    $totals = SalesReport::totals(StoreContext::current()->id(), SalesReport::sinceUtc(1));
    if (!$totals) $totals = [StoreContext::current()->currency() => ['revenue' => 0]];
    $lines = [];
    foreach ($totals as $currency => $row) {
        $lines[] = '<span data-revenue-today="' . esc($currency) . '">' . esc(I18n::price((int)$row['revenue'], $currency)) . '</span>';
    }
    return implode('<br>', $lines);
}

function pageReports(): void {
    $raw = $_GET['days'] ?? '30';
    $days = is_string($raw) && in_array((int)$raw, SalesReport::PERIODS, true) ? (int)$raw : 30;
    $storeId = StoreContext::current()->id();
    $since = SalesReport::sinceUtc($days);
    $totals = SalesReport::totals($storeId, $since);
    $trend = SalesReport::trend($storeId, $days);
    $top = SalesReport::topProducts($storeId, $since);
    $th = static fn(string $key): string => '<th>' . esc(I18n::t($key)) . '</th>';
    ?>
    <h1><?= esc(I18n::t('admin.title.reports')) ?></h1>
    <div class="card">
        <form method="get" class="sales-toolbar">
            <input type="hidden" name="p" value="reports">
            <div class="form-row"><label for="report-days"><?= esc(I18n::t('admin.reports.period')) ?></label><select id="report-days" name="days">
                <?php foreach (SalesReport::PERIODS as $value): ?><option value="<?= $value ?>" <?= $value === $days ? 'selected' : '' ?>><?= esc(str_replace('{count}', (string)$value, I18n::t('admin.reports.last_days'))) ?></option><?php endforeach; ?>
            </select></div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.reports.show')) ?></button>
        </form>
        <p class="muted" style="margin-top:.5rem"><?= esc(I18n::t('admin.reports.note')) ?></p>
    </div>
    <?php if (!$totals): ?>
    <div class="card"><p><?= esc(I18n::t('admin.reports.no_data')) ?></p></div>
    <?php return; endif; ?>

    <div class="card">
        <h2><?= esc(I18n::t('admin.reports.totals')) ?></h2>
        <div class="table-scroll"><table>
            <tr><?= $th('admin.reports.currency') . $th('admin.reports.orders') . $th('admin.reports.revenue') . $th('admin.reports.aov') ?></tr>
            <?php foreach ($totals as $currency => $row): ?>
            <tr data-report="totals" data-currency="<?= esc($currency) ?>"><th scope="row"><?= esc(strtoupper($currency)) ?></th><td data-k="orders"><?= (int)$row['orders'] ?></td><td data-k="revenue"><?= esc(I18n::price($row['revenue'], $currency)) ?></td><td data-k="aov"><?= esc(I18n::price($row['aov'], $currency)) ?></td></tr>
            <?php endforeach; ?>
        </table></div>
    </div>

    <div class="card">
        <h2><?= esc(I18n::t('admin.reports.top_products')) ?></h2>
        <?php foreach ($top as $currency => $rows): ?>
        <h3><?= esc(strtoupper($currency)) ?></h3>
        <div class="table-scroll"><table>
            <tr><?= $th('admin.th.sku') . $th('admin.th.name') . $th('admin.reports.quantity') . $th('admin.reports.revenue') ?></tr>
            <?php foreach ($rows as $row): ?>
            <tr data-report="top" data-currency="<?= esc($currency) ?>" data-key="<?= esc($row['sku']) ?>"><td><?= esc($row['sku']) ?></td><td><a href="admin.php?p=product&amp;id=<?= (int)$row['product_id'] ?>"><?= esc($row['name']) ?></a></td><td data-k="quantity"><?= (int)$row['quantity'] ?></td><td data-k="revenue"><?= esc(I18n::price($row['revenue'], $currency)) ?></td></tr>
            <?php endforeach; ?>
        </table></div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2><?= esc(I18n::t('admin.reports.trend')) ?></h2>
        <?php foreach ($trend as $currency => $daysRows): $max = max(1, ...array_column($daysRows, 'revenue')); ?>
        <details <?= $currency === array_key_first($trend) ? 'open' : '' ?>>
            <summary><?= esc(strtoupper($currency)) ?></summary>
            <div class="table-scroll"><table>
                <tr><?= $th('admin.reports.day') . $th('admin.reports.orders') . $th('admin.reports.revenue') ?></tr>
                <?php foreach (array_reverse($daysRows, true) as $day => $row): ?>
                <tr data-report="trend" data-currency="<?= esc($currency) ?>" data-key="<?= esc($day) ?>"><td><?= esc($day) ?></td><td data-k="orders"><?= (int)$row['orders'] ?></td><td data-k="revenue"><?= esc(I18n::price($row['revenue'], $currency)) ?><meter class="report-bar" min="0" max="<?= $max ?>" value="<?= (int)$row['revenue'] ?>" aria-hidden="true"></meter></td></tr>
                <?php endforeach; ?>
            </table></div>
        </details>
        <?php endforeach; ?>
    </div>
    <?php
}
