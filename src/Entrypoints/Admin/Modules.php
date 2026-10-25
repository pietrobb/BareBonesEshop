<?php
/**
 * Admin page => the module files its page renderer and POST handlers (admin.php?p=<page>&action=…) need,
 * listed with their dependencies so only the current route's code is loaded. Shared.php, Request.php and
 * StoreScope.php are always loaded by Admin.php; an unknown page renders the dashboard.
 * Cross-module calls: Operations/Orders -> Reports (adminRevenueToday), Orders -> Shipments,
 * Customers -> B2b (b2bCustomerCard), B2b/PricingRules/Suppliers -> Taxonomy (taxonomyRedirect),
 * Catalog -> CatalogEditor/Taxonomy/PricingRules (required by Catalog.php itself).
 */
$catalog = ['RichText', 'Catalog'];
$operations = ['Reports', 'Operations'];
$orders = ['Reports', 'Shipments', 'Orders'];
$customers = ['Taxonomy', 'B2b', 'Customers'];
return [
    'dashboard'  => $operations,
    'sales'      => $orders,
    'reports'    => ['Reports'],
    'products'   => $catalog,
    'product'    => $catalog,
    'orders'     => array_merge($orders, ['OrderOperations']),
    'order'      => array_merge($orders, ['OrderOperations', 'ManualOrders']), // + payment link (R10 UX3), line edit (UX7)
    'order_new'  => ['ManualOrders'],
    'awaiting_payment' => ['Recovery'],
    'abandoned_carts' => ['Recovery'],
    'invoice_pdf' => $orders,
    'delivery_note' => ['OrderOperations'],
    'import'     => $operations,
    'cache'      => $operations,
    'audit'      => $operations,
    'gdpr'       => $customers,
    'pages'      => $catalog,
    'page_edit'  => $catalog,
    'content'    => $catalog,
    'attributes' => $catalog,
    'attribute'  => $catalog,
    'categories' => ['Taxonomy'],
    'brands'     => ['Taxonomy'],
    'promotions' => ['Taxonomy', 'PricingRules'],
    'price_lists' => ['Taxonomy', 'PricingRules'],
    'warehouses' => $operations,
    'warehouse'  => $operations,
    'suppliers'  => ['Taxonomy', 'Suppliers'],
    'purchase_orders' => ['Taxonomy', 'Suppliers'],
    'feeds'      => $operations,
    'feed'       => $operations,
    'shipping'   => $operations,
    'payments'   => $operations,
    'digital_assets' => $catalog,
    'reviews'    => $catalog,
    'rma'        => $orders,
    'complaints' => ['Complaints'],
    'customers'  => $customers,
    'customer'   => $customers,
    'companies'  => ['Taxonomy', 'B2b'],
    'customer_groups' => ['Taxonomy', 'B2b'],
    'admin_users' => ['AdminUsers'],
    'email_templates' => ['EmailTemplates'],
    'stores'     => ['StoreSettings', 'StoreManagement'],
];
