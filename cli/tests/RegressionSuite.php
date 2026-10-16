<?php
/**
 * Regression discovery: runs every cli/tests/scenarios/*Regression.php once, after the thematic scenarios.
 *
 * - The files below run first, in this order: it is the historical order, and some regressions build on
 *   fixtures (rows, settings, sessions) left behind by an earlier one.
 * - Every other *Regression.php runs afterwards, sorted by file name, so a new regression file needs no
 *   registration. Give it its own fixtures; if it must run at a specific point, add it to this list instead.
 * - A regression a scenario already required at a specific point (e.g. PaymentsShippingScenario) is skipped.
 *
 * Plain top-level requires on purpose: regressions share the suite's global scope ($pdo, $testDbPath, ...).
 */
$regressionDir = __DIR__ . '/scenarios';
$regressionOrder = [
    'AuditPaymentsRegression', 'AuditCustomerRegression',
    'CheckoutConsentRegression', 'IncidentMonitoringRegression', 'AuditUtcRegression',
    'AuditStoreAdminRegression', 'AuditOrderAdminRegression', 'AuditStoreConfigRegression',
    'AuditStorePublicCatalogRegression', 'AuditStoreConfigUniqueRegression', 'AuditWebhookStoreRegression',
    'AuditStoreAdminRemainderRegression', 'AuditPaymentStateRegression', 'AuditCheckoutMoneyRegression',
    'AuditCouponRaceRegression', 'AuditOfflineInventoryRegression', 'AuditOfflineExpiryNativeRegression',
    'AuditPromotionNoteRegression', 'AuditRoleMatrixRegression', 'AuditPasswordResetRegression',
    'AuditSessionHardeningRegression', 'AuditAdminUsersRegression', 'AuditEmailVerificationRegression',
    'AuditApiPatchRegression', 'AuditApiDomainsRegression', 'AuditQueuePoisonRegression',
    'AuditUpdaterToctouRegression', 'AuditBackupMaintenanceRegression', 'AuditSqliteMaintenanceRegression',
    'AuditReleasesRoutingRegression', 'AuditBoundaryRedirectHostRegression', 'AuditBoundaryInputRegression',
    'AuditBoundaryOutputRegression', 'AuditInstallSmokeRegression', 'AuditDocsAccuracyRegression',
    'AuditProductEditorRegression', 'AuditTaxonomyRegression', 'AuditPricingAdminRegression',
    'AuditB2bAdminRegression', 'AuditSupplierAdminRegression', 'AuditAccountOrderRegression',
    'AuditCheckoutCouponRegression', 'AuditCheckoutAddressRegression', 'AuditCheckoutPickupRegression',
    'AuditCheckoutCompanyRegression', 'AuditCheckoutBankTransferRegression', 'AuditStorefrontResponsiveRegression',
    'AuditStorefrontA11yRegression', 'AuditSalesReportRegression', 'AuditOrderBulkRegression',
    'AuditStoreSwitcherRegression', 'AuditAdminI18nRegression', 'AuditEmailTemplateRegression',
    'AuditExtensionRegression', 'AuditExtensionDataRegression',
    'AuditGopayRegression', 'AuditComgateRegression', 'AuditThepayRegression', 'AuditShipmentAdminRegression',
    'AuditImportStoreScopeRegression', 'AuditGdprRetentionRegression', 'AuditCheckoutCodRegression',
    'AuditWarehouseFeedStockRegression', 'AuditWebhookClaimRegression', 'AuditJobLeaseRegression',
    'AuditSqliteMaintenanceStaleRegression', 'AuditBackupCompletenessRegression', 'AuditRetentionCleanupRegression',
    'AuditGdprEraseCompletenessRegression', 'AuditInvoiceMailFailureRegression', 'AuditDockerSampleRegression',
    'AuditDeadCodeRegression', 'AuditCrMinorRegression', 'AuditCrFollowupSecurityRegression',
    'AuditCrFollowupPaymentsRegression', 'AuditCrFollowupCheckoutRegression', 'AuditCrFollowupInfraRegression',
];
$regressionFiles = [];
foreach ($regressionOrder as $regressionName) $regressionFiles[] = $regressionDir . '/' . $regressionName . '.php';
$discoveredRegressions = glob($regressionDir . '/*Regression.php') ?: [];
sort($discoveredRegressions, SORT_STRING);
foreach ($discoveredRegressions as $regressionFile) {
    if (!in_array($regressionFile, $regressionFiles, true)) $regressionFiles[] = $regressionFile;
}
$regressionAlreadyRun = array_map('realpath', get_included_files());
foreach ($regressionFiles as $regressionFile) {
    if (!is_file($regressionFile)) {
        assert_true(false, 'Regression discovery: listed regression ' . basename($regressionFile) . ' exists');
        continue;
    }
    if (in_array(realpath($regressionFile), $regressionAlreadyRun, true)) continue;
    require $regressionFile;
}
