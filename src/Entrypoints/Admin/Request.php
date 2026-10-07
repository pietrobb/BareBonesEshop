<?php
/** POST action dispatch for admin transports. */

function handlePost(string $page, string $action): void {
    if (!Security::csrfValidate()) {
        setFlash('err', 'Neplatný CSRF token.');
        return;
    }

    match("{$page}:{$action}") {
        'product:save'   => handleProductSave(),
        'product:related_save' => handleProductRelatedSave(),
        'products:bulk'  => handleBulkAction(),
        'order:status'   => handleOrderStatus(),
        'order:payment_transition' => handleOrderPaymentTransition(),
        'order:refund_request' => handleOrderRefundRequest(),
        'order:email_resend' => handleOrderEmailResend(),
        'order:invoice_issue' => handleOrderInvoiceIssue(),
        'order:invoice_resend' => handleOrderInvoiceResend(),
        'awaiting_payment:confirm' => handleAwaitingPaymentConfirm(),
        'import:upload'  => handleImportUpload(),
        'cache:flush'    => handleCacheFlush(),
        'cache:rebuild'  => handleCacheRebuild(),
        'gdpr:anonymize' => handleGdprAnonymize(),
        'page_edit:save' => handleCmsPageSave(),
        'pages:delete'   => handleCmsPageDelete(),
        'content:save'   => handleContentSave(),
        'content:delete' => handleContentDelete(),
        'attribute:save' => handleAttributeSave(),
        'attributes:delete' => handleAttributeDelete(),
        'warehouse:save'    => handleWarehouseSave(),
        'warehouses:delete' => handleWarehouseDelete(),
        'feed:save'         => handleFeedSave(),
        'feeds:delete'      => handleFeedDelete(),
        'feeds:sync'        => handleFeedSync(),
        'shipping:method_save' => handleShippingMethodSave(),
        'shipping:method_delete' => handleShippingMethodDelete(),
        'shipping:zone_save' => handleShippingZoneSave(),
        'shipping:zone_delete' => handleShippingZoneDelete(),
        'shipping:country_save' => handleShippingCountrySave(),
        'shipping:country_delete' => handleShippingCountryDelete(),
        'shipping:rate_save' => handleShippingRateSave(),
        'shipping:rate_delete' => handleShippingRateDelete(),
        'payments:method_save' => handlePaymentMethodSave(),
        'payments:method_delete' => handlePaymentMethodDelete(),
        'payments:country_save' => handlePaymentCountrySave(),
        'payments:country_delete' => handlePaymentCountryDelete(),
        'payments:shipping_save' => handlePaymentShippingSave(),
        'payments:shipping_delete' => handlePaymentShippingDelete(),
        'digital_assets:save' => handleDigitalAssetSave(),
        'digital_assets:toggle' => handleDigitalAssetToggle(),
        'reviews:approve' => handleReviewModerate('approved'),
        'reviews:reject'  => handleReviewModerate('rejected'),
        'rma:status'      => handleRmaStatus(),
        'customer:status' => handleCustomerStatus(),
        default          => null,
    };
}
