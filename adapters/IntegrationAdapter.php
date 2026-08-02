<?php
/**
 * BareBones Shop — Integration (ERP) Adapter Interface
 * Default: null (standalone shop, no integration)
 * Optional: MoneyErpAdapter, PohodaAdapter, FlexiBeeAdapter,
 *           WooCommerceAdapter, CsvFileAdapter
 */
interface IntegrationAdapter extends ErpAdapter, PimAdapter, WmsAdapter
{
}
