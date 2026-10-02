<?php
// =============================================================================
// includes/reports.php – Shared Reports & Analytics builder
// Used by both admin/reports.php and staff/reports.php so both screens share
// one definition of the numbers, the filters, and the sorting.
// =============================================================================

/**
 * Builds the WHERE fragment + params for the reporting period and any
 * extra filters. Returns [sqlFragment, params].
 *
 * $dateColumn is the timestamp column the period applies to.
 */
function reportsPeriodClause(array $query, string $dateColumn = 'o.created_at'): array
{
    $range = trim((string) ($query['range'] ?? 'all_time'));
    $params = [];

    if ($range === 'today') {
        return [" AND DATE($dateColumn) = CURDATE()", $params];
    }
    if ($range === '7_days') {
        return [" AND $dateColumn >= DATE_SUB(NOW(), INTERVAL 7 DAY)", $params];
    }
    if ($range === '30_days') {
        return [" AND $dateColumn >= DATE_SUB(NOW(), INTERVAL 30 DAY)", $params];
    }
    return ["", $params];
}

/** Whitelisted ORDER BY for the top-products table. */
function reportsTopProductsSort(string $sort): array
{
    switch ($sort) {
        case 'revenue': return ['total_sales DESC, units_sold DESC', 'Highest revenue first'];
        case 'name':    return ['product_name ASC, service_size ASC', 'Product name (A–Z)'];
        case 'qty':     return ['units_sold DESC, total_sales DESC', 'Quantity sold'];
        default:        return ['units_sold DESC, total_sales DESC', 'Quantity sold'];
    }
}

/** Whitelisted ORDER BY for the category table. */
function reportsCategorySort(string $sort): array
{
    switch ($sort) {
        case 'value':  return ['inventory_value DESC, category_name ASC', 'Estimated value'];
        case 'items':  return ['product_count DESC, category_name ASC', 'Number of items'];
        case 'name':   return ['category_name ASC', 'Category name (A–Z)'];
        case 'stock':  return ['total_stock DESC, category_name ASC', 'Stock units'];
        default:       return ['total_stock DESC, category_name ASC', 'Stock units'];
    }
}

/** Whitelisted ORDER BY for the recent-transactions table. */
function reportsPaymentsSort(string $sort): array
{
    switch ($sort) {
        case 'amount_high': return ['p.amount DESC, p.id DESC', 'Amount (high → low)'];
        case 'amount_low':  return ['p.amount ASC, p.id DESC', 'Amount (low → high)'];
        case 'customer':    return ['u.last_name ASC, u.first_name ASC', 'Customer name'];
        default:            return ['p.id DESC', 'Most recent first'];
    }
}

/** Trims a free-text search term down to something safe to bind. */
function reportsSearchTerm(array $query, string $key): string
{
    $term = trim((string) ($query[$key] ?? ''));
    return mb_substr($term, 0, 60);
}

/** Builds a URL that keeps the active filters and swaps one parameter. */
function reportsFilterUrl(array $current, array $overrides): string
{
    $query = array_merge($current, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    $base = strtok($_SERVER['REQUEST_URI'] ?? 'reports.php', '?');
    return $base . '?' . http_build_query($query);
}

/**
 * Runs every report query and returns a ready-to-render dataset.
 */
function reportsBuildDataset(PDO $pdo, array $query): array
{
    // ── Reporting period ──
    [$periodSql, $params] = reportsPeriodClause($query);

    $kpiStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN p.status = 'paid' THEN p.amount ELSE 0 END), 0) AS total_revenue,
            COUNT(DISTINCT o.id) AS total_orders,
            COUNT(DISTINCT CASE WHEN p.status = 'paid' THEN o.id ELSE NULL END) AS paid_orders,
            COUNT(DISTINCT CASE WHEN o.status = 'processing' THEN o.id ELSE NULL END) AS processing_orders,
            COUNT(DISTINCT CASE WHEN o.status = 'completed' THEN o.id ELSE NULL END) AS completed_orders,
            COALESCE(AVG(CASE WHEN p.status = 'paid' THEN p.amount ELSE NULL END), 0) AS average_order_value,
            COALESCE(SUM(CASE WHEN p.status = 'paid' THEN o.delivery_fee ELSE 0 END), 0) AS delivery_fee_collected,
            COALESCE(SUM(CASE WHEN p.status = 'paid' THEN o.voucher_discount ELSE 0 END), 0) AS voucher_savings,
            COUNT(DISTINCT CASE WHEN p.status = 'paid' AND o.voucher_code IS NOT NULL AND o.voucher_code <> '' THEN o.id END) AS voucher_redemptions,
            COALESCE(SUM(CASE WHEN p.status = 'paid' AND o.fulfillment_type = 'delivery' THEN 1 ELSE 0 END), 0) AS delivery_orders,
            COALESCE(SUM(CASE WHEN p.status = 'paid' AND o.fulfillment_type = 'pickup' THEN 1 ELSE 0 END), 0) AS pickup_orders
        FROM orders o
        LEFT JOIN payments p ON p.order_id = o.id
        WHERE 1=1 $periodSql
    ");
    $kpiStmt->execute($params);
    $kpi = $kpiStmt->fetch() ?: [];

    // ── Units sold (period only) ──
    $unitsStmt = $pdo->prepare("
        SELECT COALESCE(SUM(oi.quantity), 0)
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN payments p ON p.order_id = o.id
        WHERE p.status = 'paid' $periodSql
    ");
    $unitsStmt->execute($params);
    $unitsSold = (int) $unitsStmt->fetchColumn();

    // ── Top products (search + sort) ──
    $productSearch = reportsSearchTerm($query, 'q');
    [$productOrder, $productSortLabel] = reportsTopProductsSort(trim((string) ($query['sort_products'] ?? '')));

    $productWhere = " WHERE p.status = 'paid' $periodSql";
    $productParams = $params;
    if ($productSearch !== '') {
        // Native prepared statements (EMULATE_PREPARES = off) reject a repeated
        // named placeholder, so each LIKE gets its own bind key.
        $productWhere .= " AND (oi.product_name LIKE :prod_name OR oi.service_size LIKE :prod_size OR oi.sku LIKE :prod_sku)";
        $productParams['prod_name'] = '%' . $productSearch . '%';
        $productParams['prod_size'] = '%' . $productSearch . '%';
        $productParams['prod_sku']  = '%' . $productSearch . '%';
    }

    $topProductsStmt = $pdo->prepare("
        SELECT oi.product_name, oi.service_size, oi.sku,
               SUM(oi.quantity) AS units_sold,
               SUM(oi.line_total) AS total_sales,
               AVG(oi.unit_price) AS unit_price
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN payments p ON p.order_id = o.id
        $productWhere
        GROUP BY oi.product_name, oi.service_size, oi.sku
        ORDER BY $productOrder
        LIMIT 25
    ");
    $topProductsStmt->execute($productParams);
    $topProducts = $topProductsStmt->fetchAll();
    $productTotal = count($topProducts);

    // ── Categories (search + sort) ──
    $categorySearch = reportsSearchTerm($query, 'q_category');
    [$categoryOrder, $categorySortLabel] = reportsCategorySort(trim((string) ($query['sort_categories'] ?? '')));

    $categoryWhere = '';
    $categoryParams = [];
    if ($categorySearch !== '') {
        $categoryWhere = ' WHERE c.name LIKE :cat_search';
        $categoryParams['cat_search'] = '%' . $categorySearch . '%';
    }

    $catStmt = $pdo->prepare("
        SELECT c.name AS category_name,
               COUNT(DISTINCT i.id) AS product_count,
               COALESCE(SUM(v.quantity), 0) AS total_stock,
               COALESCE(SUM(v.quantity * v.price), 0) AS inventory_value
        FROM menu_categories c
        LEFT JOIN inventory_items i ON i.category_id = c.id
        LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id
        $categoryWhere
        GROUP BY c.id, c.name
        ORDER BY $categoryOrder
    ");
    $catStmt->execute($categoryParams);
    $categorySummary = $catStmt->fetchAll();
    $categoryTotal = count($categorySummary);

    // Inventory valuation is a stock snapshot — it must not shrink when the
    // admin filters the category list, so it is measured on the unfiltered set.
    $allCatsStmt = $pdo->query("
        SELECT COALESCE(SUM(v.quantity * v.price), 0) AS inventory_value,
               COALESCE(SUM(v.quantity), 0) AS total_stock
        FROM menu_categories c
        LEFT JOIN inventory_items i ON i.category_id = c.id
        LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id
    ");
    $allCats = $allCatsStmt->fetch() ?: [];
    $totalInventoryValue = (float) ($allCats['inventory_value'] ?? 0);
    $totalStockUnits = (int) ($allCats['total_stock'] ?? 0);

    // ── Recent payments (search + channel/status filter + sort) ──
    $paymentSearch = reportsSearchTerm($query, 'q_payments');
    $channelFilter = trim((string) ($query['channel'] ?? ''));
    $payStatusFilter = trim((string) ($query['pay_status'] ?? ''));
    [$paymentOrder, $paymentSortLabel] = reportsPaymentsSort(trim((string) ($query['sort_payments'] ?? '')));

    $paymentWhere = ' WHERE 1=1';
    $paymentParams = [];
    if ($paymentSearch !== '') {
        // Each LIKE needs a unique bind key — see the note in reportsBuildDataset.
        $paymentWhere .= ' AND (o.reference_id LIKE :pay_ref OR u.first_name LIKE :pay_first OR u.last_name LIKE :pay_last)';
        $paymentParams['pay_ref']   = '%' . $paymentSearch . '%';
        $paymentParams['pay_first'] = '%' . $paymentSearch . '%';
        $paymentParams['pay_last']  = '%' . $paymentSearch . '%';
    }
    if ($channelFilter !== '') {
        $paymentWhere .= ' AND p.payment_channel = :channel';
        $paymentParams['channel'] = $channelFilter;
    }
    if (in_array($payStatusFilter, ['paid', 'pending', 'failed'], true)) {
        $paymentWhere .= ' AND p.status = :pay_status';
        $paymentParams['pay_status'] = $payStatusFilter;
    }

    $paymentsStmt = $pdo->prepare("
        SELECT o.reference_id, u.first_name, u.last_name,
               p.amount, p.payment_method, p.payment_channel,
               p.status AS payment_status, o.status AS order_status, o.created_at
        FROM payments p
        JOIN orders o ON o.id = p.order_id
        JOIN users u ON u.id = o.customer_id
        $paymentWhere
        ORDER BY $paymentOrder
        LIMIT 50
    ");
    $paymentsStmt->execute($paymentParams);
    $recentPayments = $paymentsStmt->fetchAll();

    // Distinct channels for the filter dropdown.
    $channelsStmt = $pdo->query("SELECT DISTINCT payment_channel FROM payments WHERE payment_channel IS NOT NULL AND payment_channel <> '' ORDER BY payment_channel");
    $channels = array_column($channelsStmt->fetchAll(), 'payment_channel');

    // ── Derived revenue composition ──
    $totalRevenue = (float) ($kpi['total_revenue'] ?? 0);
    $deliveryFeeCollected = (float) ($kpi['delivery_fee_collected'] ?? 0);
    $voucherSavings = (float) ($kpi['voucher_savings'] ?? 0);
    $voucherRedemptions = (int) ($kpi['voucher_redemptions'] ?? 0);
    $productsRevenue = $totalRevenue - $deliveryFeeCollected;
    $deliveryFeeSharePct = $totalRevenue > 0 ? ($deliveryFeeCollected / $totalRevenue) * 100 : 0.0;
    $voucherSharePct = ($deliveryFeeCollected + $voucherSavings) > 0
        ? ($voucherSavings / ($deliveryFeeCollected + $voucherSavings)) * 100
        : 0.0;

    return [
        'kpi' => [
            'total_revenue' => $totalRevenue,
            'total_orders' => (int) ($kpi['total_orders'] ?? 0),
            'paid_orders' => (int) ($kpi['paid_orders'] ?? 0),
            'processing_orders' => (int) ($kpi['processing_orders'] ?? 0),
            'completed_orders' => (int) ($kpi['completed_orders'] ?? 0),
            'average_order_value' => (float) ($kpi['average_order_value'] ?? 0),
            'delivery_fee_collected' => $deliveryFeeCollected,
            'voucher_savings' => $voucherSavings,
            'voucher_redemptions' => $voucherRedemptions,
            'delivery_orders' => (int) ($kpi['delivery_orders'] ?? 0),
            'pickup_orders' => (int) ($kpi['pickup_orders'] ?? 0),
            'units_sold' => $unitsSold,
            'total_inventory_value' => $totalInventoryValue,
            'total_stock_units' => $totalStockUnits,
        ],
        'revenue' => [
            'products' => $productsRevenue,
            'delivery_fees' => $deliveryFeeCollected,
            'total' => $totalRevenue,
            'voucher_waived' => $voucherSavings,
            'could_have_earned' => $deliveryFeeCollected + $voucherSavings,
            'delivery_fee_share_pct' => $deliveryFeeSharePct,
            'voucher_share_pct' => $voucherSharePct,
        ],
        'top_products' => $topProducts,
        'categories' => $categorySummary,
        'payments' => $recentPayments,
        'channels' => $channels,
        'counts' => [
            'products' => $productTotal,
            'categories' => $categoryTotal,
            'payments' => count($recentPayments),
        ],
        'filters' => [
            'q_products' => $productSearch,
            'q_category' => $categorySearch,
            'q_payments' => $paymentSearch,
            'sort_products' => trim((string) ($query['sort_products'] ?? '')),
            'sort_categories' => trim((string) ($query['sort_categories'] ?? '')),
            'sort_payments' => trim((string) ($query['sort_payments'] ?? '')),
            'channel' => $channelFilter,
            'pay_status' => $payStatusFilter,
        ],
        'sort_labels' => [
            'products' => $productSortLabel,
            'categories' => $categorySortLabel,
            'payments' => $paymentSortLabel,
        ],
    ];
}