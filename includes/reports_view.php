<?php
// =============================================================================
// includes/reports_view.php – Shared markup for Reports & Analytics
// Admin and Staff render the exact same page; only the header and links differ.
// Expects: $reports (see reportsBuildDataset), $query, $ordersUrl
// =============================================================================

/** Reads a sort value that is valid for the given table. */
function reportsSortValue(string $key, array $allowed): string
{
    $value = trim((string) ($_GET[$key] ?? ''));
    return in_array($value, $allowed, true) ? $value : '';
}
?>

<!-- ══ Reporting Period + Table Filters ══ -->
<section class="panel reports-period-bar" style="background: #fff; border: 1px solid var(--admin-line); border-radius: 12px; padding: 14px 20px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <span style="font-size: 13px; font-weight: 700; color: var(--admin-brown-dark);">
        <i class="fa-solid fa-calendar-days" style="margin-right: 6px; color: var(--admin-muted);"></i> Reporting Period
    </span>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <?php foreach (['all_time' => 'All Time', 'today' => 'Today', '7_days' => 'Last 7 Days', '30_days' => 'Last 30 Days'] as $key => $label): ?>
            <a href="<?php echo htmlspecialchars(reportsFilterUrl($query, ['range' => $key])); ?>"
               class="admin-button <?php echo ($query['range'] ?? 'all_time') === $key ? 'primary' : 'secondary'; ?>"
               style="height: 34px; font-size: 11px; padding: 0 14px; border-radius: 6px;"><?php echo $label; ?></a>
        <?php endforeach; ?>
    </div>
</section>

<!-- ══ KPI Cards ══ -->
<section class="orders-summary-grid is-triplet">
    <article class="summary-card">
        <div class="summary-top">
            <span>Total Revenue (Paid)</span>
            <div class="summary-icon" style="background: rgba(40, 160, 103, 0.12); color: #28a067;">
                <i class="fa-solid fa-peso-sign"></i>
            </div>
        </div>
        <div class="summary-value" style="color: #28a067;">₱<?php echo number_format($reports['kpi']['total_revenue'], 2); ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            From <?php echo $reports['kpi']['paid_orders']; ?> paid customer transactions
        </small>
    </article>

    <article class="summary-card">
        <div class="summary-top">
            <span>Average Order Value</span>
            <div class="summary-icon" style="background: rgba(32, 82, 168, 0.1); color: #2052a8;">
                <i class="fa-solid fa-chart-pie"></i>
            </div>
        </div>
        <div class="summary-value" style="color: #2052a8;">₱<?php echo number_format($reports['kpi']['average_order_value'], 2); ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            Per completed transaction
        </small>
    </article>

    <article class="summary-card">
        <div class="summary-top">
            <span>Units Sold</span>
            <div class="summary-icon" style="background: rgba(201, 121, 64, 0.12); color: #c97940;">
                <i class="fa-solid fa-bag-shopping"></i>
            </div>
        </div>
        <div class="summary-value" style="color: var(--admin-brown);"><?php echo $reports['kpi']['units_sold']; ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            Total bakery items fulfilled
        </small>
    </article>

    <article class="summary-card">
        <div class="summary-top">
            <span>Inventory Valuation</span>
            <div class="summary-icon" style="background: rgba(123, 82, 59, 0.1); color: var(--admin-brown);">
                <i class="fa-solid fa-boxes-stacked"></i>
            </div>
        </div>
        <div class="summary-value">₱<?php echo number_format($reports['kpi']['total_inventory_value'], 2); ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            Across <?php echo $reports['kpi']['total_stock_units']; ?> current stock units
        </small>
    </article>

    <article class="summary-card">
        <div class="summary-top">
            <span>Delivery Fees Collected</span>
            <div class="summary-icon" style="background: rgba(32, 82, 168, 0.1); color: #2052a8;">
                <i class="fa-solid fa-truck-fast"></i>
            </div>
        </div>
        <div class="summary-value" style="color: #2052a8;">₱<?php echo number_format($reports['revenue']['delivery_fees'], 2); ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            <?php echo number_format($reports['revenue']['delivery_fee_share_pct'], 1); ?>% of total revenue
            · <?php echo $reports['kpi']['delivery_orders']; ?> delivery <?php echo $reports['kpi']['delivery_orders'] === 1 ? 'order' : 'orders'; ?>
        </small>
    </article>

    <article class="summary-card">
        <div class="summary-top">
            <span>Vouchers Given Away</span>
            <div class="summary-icon" style="background: rgba(201, 121, 64, 0.12); color: #c97940;">
                <i class="fa-solid fa-ticket"></i>
            </div>
        </div>
        <div class="summary-value" style="color: #c97940;">₱<?php echo number_format($reports['revenue']['voucher_waived'], 2); ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            <?php echo $reports['kpi']['voucher_redemptions']; ?> <?php echo $reports['kpi']['voucher_redemptions'] === 1 ? 'redemption' : 'redemptions'; ?> · waived delivery fees
        </small>
    </article>
</section>

<!-- ══ Revenue Breakdown ══ -->
<section class="panel" style="background: #fff; border: 1px solid var(--admin-line); border-radius: 14px; padding: 22px; margin-bottom: 24px; box-shadow: 0 4px 20px rgba(39, 29, 23, 0.04);">
    <div class="panel-heading" style="margin-bottom: 16px;">
        <div>
            <h3 style="font-size: 16px; margin: 0; color: var(--admin-ink);">Revenue Breakdown</h3>
            <p style="margin: 3px 0 0; color: var(--admin-muted); font-size: 12px;">
                Where every peso of paid revenue came from, and what vouchers gave back.
            </p>
        </div>
        <i class="fa-solid fa-chart-column" style="color: var(--admin-brown); font-size: 18px;"></i>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 26px;">
        <div>
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 11px 0; border-bottom: 1px dashed var(--admin-line);">
                <span style="font-size: 13px; color: var(--admin-ink);">
                    <i class="fa-solid fa-bread-slice" style="width: 18px; color: var(--admin-brown); margin-right: 8px;"></i> Products Revenue
                </span>
                <strong style="font-family: 'Manrope', sans-serif; font-size: 15px;">₱<?php echo number_format($reports['revenue']['products'], 2); ?></strong>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 11px 0; border-bottom: 1px dashed var(--admin-line);">
                <span style="font-size: 13px; color: var(--admin-ink);">
                    <i class="fa-solid fa-truck-fast" style="width: 18px; color: #2052a8; margin-right: 8px;"></i> Delivery Fees Collected
                </span>
                <strong style="font-family: 'Manrope', sans-serif; font-size: 15px; color: #2052a8;">₱<?php echo number_format($reports['revenue']['delivery_fees'], 2); ?></strong>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 0; border-top: 2px solid var(--admin-line); border-bottom: none;">
                <strong style="font-size: 14px;">Total Revenue (Paid)</strong>
                <strong style="font-family: 'Manrope', sans-serif; font-size: 18px; color: #28a067;">₱<?php echo number_format($reports['revenue']['total'], 2); ?></strong>
            </div>
        </div>

        <div>
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 11px 0; border-bottom: 1px dashed var(--admin-line);">
                <span style="font-size: 13px; color: var(--admin-ink);">
                    <i class="fa-solid fa-ticket" style="width: 18px; color: #c97940; margin-right: 8px;"></i> Voucher Delivery Waived
                </span>
                <strong style="font-family: 'Manrope', sans-serif; font-size: 15px; color: #c97940;">−₱<?php echo number_format($reports['revenue']['voucher_waived'], 2); ?></strong>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 11px 0; border-bottom: 1px dashed var(--admin-line);">
                <span style="font-size: 13px; color: var(--admin-ink);">
                    <i class="fa-solid fa-gift" style="width: 18px; color: var(--admin-brown); margin-right: 8px;"></i> Free Delivery Could Have Earned
                </span>
                <strong style="font-family: 'Manrope', sans-serif; font-size: 15px;">₱<?php echo number_format($reports['revenue']['could_have_earned'], 2); ?></strong>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 0; border-top: 2px solid var(--admin-line); border-bottom: none;">
                <span style="font-size: 13px; color: var(--admin-muted);">
                    <?php if ($reports['kpi']['voucher_redemptions'] > 0): ?>
                        Vouchers cost you <?php echo number_format($reports['revenue']['voucher_share_pct'], 1); ?>% of potential delivery income
                    <?php else: ?>
                        No vouchers redeemed yet — full delivery income collected
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>
</section>

<!-- ══ Top Selling Items + Category Breakdown ══ -->
<div style="display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 20px; margin-bottom: 24px;">

    <!-- Top Selling Products -->
    <section class="panel" id="reports-products" data-reports-section style="background: #fff; border: 1px solid var(--admin-line); border-radius: 14px; padding: 22px; box-shadow: 0 4px 20px rgba(39, 29, 23, 0.04);">
        <div class="panel-heading" style="margin-bottom: 16px;">
            <div>
                <h3 style="font-size: 16px; margin: 0; color: var(--admin-ink);">Top Selling Bakery Items</h3>
                <p style="margin: 3px 0 0; color: var(--admin-muted); font-size: 12px;">Best-selling products by quantity and revenue generated.</p>
            </div>
            <i class="fa-solid fa-fire" style="color: #c97940; font-size: 18px;"></i>
        </div>

        <form method="GET" class="reports-toolbar" data-reports-focus="reports-products">
            <div class="reports-toolbar-left">
                <label class="reports-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" name="q" value="<?php echo htmlspecialchars($reports['filters']['q_products']); ?>"
                           placeholder="Search product or SKU…" aria-label="Search products" />
                </label>
                <?php if ($reports['filters']['q_products'] !== ''): ?>
                    <a href="<?php echo htmlspecialchars(reportsFilterUrl($query, ['q' => ''])); ?>" class="reports-chip is-clear">
                        <i class="fa-solid fa-xmark"></i> Clear
                    </a>
                <?php endif; ?>
            </div>
            <div class="reports-toolbar-right">
                <span class="reports-count"><?php echo $reports['counts']['products']; ?> items</span>
                <select name="sort_products" class="reports-sort" onchange="this.form.submit()" aria-label="Sort products">
                    <option value="">Sort: Quantity sold</option>
                    <option value="revenue" <?php echo reportsSortValue('sort_products', ['revenue', 'name', 'qty']) === 'revenue' ? 'selected' : ''; ?>>Sort: Highest revenue</option>
                    <option value="qty" <?php echo reportsSortValue('sort_products', ['revenue', 'name', 'qty']) === 'qty' ? 'selected' : ''; ?>>Sort: Quantity sold</option>
                    <option value="name" <?php echo reportsSortValue('sort_products', ['revenue', 'name', 'qty']) === 'name' ? 'selected' : ''; ?>>Sort: Name (A–Z)</option>
                </select>
            </div>
            <?php foreach (['range' => $query['range'] ?? '', 'q_category' => $reports['filters']['q_category'], 'sort_categories' => $reports['filters']['sort_categories'], 'q_payments' => $reports['filters']['q_payments'], 'channel' => $reports['filters']['channel'], 'pay_status' => $reports['filters']['pay_status'], 'sort_payments' => $reports['filters']['sort_payments']] as $hidden => $value): ?>
                <?php if ($value !== '' && $value !== null): ?>
                    <input type="hidden" name="<?php echo $hidden; ?>" value="<?php echo htmlspecialchars($value); ?>" />
                <?php endif; ?>
            <?php endforeach; ?>
        </form>

        <?php if (empty($reports['top_products'])): ?>
            <div class="table-wrap">
                <table class="orders-table">
                    <tbody>
                        <tr class="reports-empty-row">
                            <td>
                                <i class="fa-solid fa-<?php echo $reports['filters']['q_products'] !== '' ? 'magnifying-glass-minus' : 'receipt'; ?>"></i>
                                <?php echo $reports['filters']['q_products'] !== ''
                                    ? 'No products match “' . htmlspecialchars($reports['filters']['q_products']) . '”.'
                                    : 'No sales recorded for this reporting period.'; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Product &amp; Size</th>
                            <th>SKU</th>
                            <th style="text-align: right;">Qty Sold</th>
                            <th style="text-align: right;">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports['top_products'] as $tp): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($tp['product_name']); ?></strong>
                                <span style="display: block; font-size: 11px; color: var(--admin-muted);">
                                    <?php echo htmlspecialchars($tp['service_size']); ?> · ₱<?php echo number_format((float) $tp['unit_price'], 2); ?>
                                </span>
                            </td>
                            <td><span style="font-family: monospace; font-size: 11px; color: var(--admin-muted);"><?php echo htmlspecialchars($tp['sku']); ?></span></td>
                            <td style="text-align: right;"><strong style="color: var(--admin-brown);"><?php echo (int) $tp['units_sold']; ?></strong></td>
                            <td style="text-align: right;"><strong style="color: #28a067;">₱<?php echo number_format((float) $tp['total_sales'], 2); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- Category Valuation Breakdown -->
    <section class="panel" id="reports-categories" data-reports-section style="background: #fff; border: 1px solid var(--admin-line); border-radius: 14px; padding: 22px; box-shadow: 0 4px 20px rgba(39, 29, 23, 0.04);">
        <div class="panel-heading" style="margin-bottom: 16px;">
            <div>
                <h3 style="font-size: 16px; margin: 0; color: var(--admin-ink);">Category Stock &amp; Value</h3>
                <p style="margin: 3px 0 0; color: var(--admin-muted); font-size: 12px;">Inventory units and valuation by menu category.</p>
            </div>
            <i class="fa-solid fa-layer-group" style="color: var(--admin-brown); font-size: 18px;"></i>
        </div>

        <form method="GET" class="reports-toolbar" data-reports-focus="reports-categories">
            <div class="reports-toolbar-left">
                <label class="reports-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" name="q_category" value="<?php echo htmlspecialchars($reports['filters']['q_category']); ?>"
                           placeholder="Search category…" aria-label="Search categories" />
                </label>
                <?php if ($reports['filters']['q_category'] !== ''): ?>
                    <a href="<?php echo htmlspecialchars(reportsFilterUrl($query, ['q_category' => ''])); ?>" class="reports-chip is-clear">
                        <i class="fa-solid fa-xmark"></i> Clear
                    </a>
                <?php endif; ?>
            </div>
            <div class="reports-toolbar-right">
                <span class="reports-count"><?php echo $reports['counts']['categories']; ?> categories</span>
                <select name="sort_categories" class="reports-sort" onchange="this.form.submit()" aria-label="Sort categories">
                    <option value="">Sort: Stock units</option>
                    <option value="stock" <?php echo reportsSortValue('sort_categories', ['stock', 'value', 'items', 'name']) === 'stock' ? 'selected' : ''; ?>>Sort: Stock units</option>
                    <option value="value" <?php echo reportsSortValue('sort_categories', ['stock', 'value', 'items', 'name']) === 'value' ? 'selected' : ''; ?>>Sort: Estimated value</option>
                    <option value="items" <?php echo reportsSortValue('sort_categories', ['stock', 'value', 'items', 'name']) === 'items' ? 'selected' : ''; ?>>Sort: Number of items</option>
                    <option value="name" <?php echo reportsSortValue('sort_categories', ['stock', 'value', 'items', 'name']) === 'name' ? 'selected' : ''; ?>>Sort: Name (A–Z)</option>
                </select>
            </div>
            <?php foreach (['range' => $query['range'] ?? '', 'q' => $reports['filters']['q_products'], 'sort_products' => $reports['filters']['sort_products'], 'q_payments' => $reports['filters']['q_payments'], 'channel' => $reports['filters']['channel'], 'pay_status' => $reports['filters']['pay_status'], 'sort_payments' => $reports['filters']['sort_payments']] as $hidden => $value): ?>
                <?php if ($value !== '' && $value !== null): ?>
                    <input type="hidden" name="<?php echo $hidden; ?>" value="<?php echo htmlspecialchars($value); ?>" />
                <?php endif; ?>
            <?php endforeach; ?>
        </form>

        <?php if (empty($reports['categories'])): ?>
            <div class="table-wrap">
                <table class="orders-table">
                    <tbody>
                        <tr class="reports-empty-row">
                            <td>
                                <i class="fa-solid fa-magnifying-glass-minus"></i>
                                No categories match “<?php echo htmlspecialchars($reports['filters']['q_category']); ?>”.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th style="text-align: right;">Items</th>
                            <th style="text-align: right;">Stock Units</th>
                            <th style="text-align: right;">Est. Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports['categories'] as $cat): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($cat['category_name']); ?></strong></td>
                            <td style="text-align: right;"><?php echo (int) $cat['product_count']; ?></td>
                            <td style="text-align: right;"><span class="stock-badge <?php echo (int) $cat['total_stock'] <= 10 ? 'stock-low' : 'stock-in'; ?>"><?php echo (int) $cat['total_stock']; ?></span></td>
                            <td style="text-align: right; font-weight: 700; color: var(--admin-ink);">₱<?php echo number_format((float) $cat['inventory_value'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<!-- ══ Recent Transactions ══ -->
<section class="panel" id="reports-payments" data-reports-section style="background: #fff; border: 1px solid var(--admin-line); border-radius: 14px; padding: 24px; box-shadow: 0 4px 20px rgba(39, 29, 23, 0.04);">
    <div class="panel-heading" style="margin-bottom: 18px;">
        <div>
            <h3 style="font-size: 16px; margin: 0; color: var(--admin-ink);">Recent Payment Transactions</h3>
            <p style="margin: 3px 0 0; color: var(--admin-muted); font-size: 12px;">Real-time payment logs and verification statuses from Xendit.</p>
        </div>
        <a href="<?php echo htmlspecialchars($ordersUrl); ?>" class="admin-button secondary" style="height: 36px; padding: 0 14px; font-size: 11px;">
            View All Orders
        </a>
    </div>

    <form method="GET" class="reports-toolbar" data-reports-focus="reports-payments">
        <div class="reports-toolbar-left">
            <label class="reports-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" name="q_payments" value="<?php echo htmlspecialchars($reports['filters']['q_payments']); ?>"
                       placeholder="Search reference or customer…" aria-label="Search transactions" />
            </label>
            <select name="channel" class="reports-sort" onchange="this.form.submit()" aria-label="Filter by channel">
                <option value="">All channels</option>
                <?php foreach ($reports['channels'] as $channel): ?>
                    <option value="<?php echo htmlspecialchars($channel); ?>" <?php echo $reports['filters']['channel'] === $channel ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($channel); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="pay_status" class="reports-sort" onchange="this.form.submit()" aria-label="Filter by payment status">
                <option value="">All payment statuses</option>
                <?php foreach (['paid' => 'Paid', 'pending' => 'Pending', 'failed' => 'Failed'] as $value => $label): ?>
                    <option value="<?php echo $value; ?>" <?php echo $reports['filters']['pay_status'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="reports-toolbar-right">
            <?php if ($reports['filters']['q_payments'] !== '' || $reports['filters']['channel'] !== '' || $reports['filters']['pay_status'] !== ''): ?>
                <a href="<?php echo htmlspecialchars(reportsFilterUrl($query, ['q_payments' => '', 'channel' => '', 'pay_status' => ''])); ?>" class="reports-chip is-clear">
                    <i class="fa-solid fa-filter-circle-xmark"></i> Clear filters
                </a>
            <?php endif; ?>
            <span class="reports-count"><?php echo $reports['counts']['payments']; ?> shown</span>
            <select name="sort_payments" class="reports-sort" onchange="this.form.submit()" aria-label="Sort transactions">
                <option value="">Sort: Most recent</option>
                <option value="amount_high" <?php echo reportsSortValue('sort_payments', ['amount_high', 'amount_low', 'customer']) === 'amount_high' ? 'selected' : ''; ?>>Sort: Amount (high → low)</option>
                <option value="amount_low" <?php echo reportsSortValue('sort_payments', ['amount_high', 'amount_low', 'customer']) === 'amount_low' ? 'selected' : ''; ?>>Sort: Amount (low → high)</option>
                <option value="customer" <?php echo reportsSortValue('sort_payments', ['amount_high', 'amount_low', 'customer']) === 'customer' ? 'selected' : ''; ?>>Sort: Customer name</option>
            </select>
        </div>
        <?php foreach (['range' => $query['range'] ?? '', 'q' => $reports['filters']['q_products'], 'sort_products' => $reports['filters']['sort_products'], 'q_category' => $reports['filters']['q_category'], 'sort_categories' => $reports['filters']['sort_categories']] as $hidden => $value): ?>
            <?php if ($value !== '' && $value !== null): ?>
                <input type="hidden" name="<?php echo $hidden; ?>" value="<?php echo htmlspecialchars($value); ?>" />
            <?php endif; ?>
        <?php endforeach; ?>
    </form>

    <?php if (empty($reports['payments'])): ?>
        <div class="table-wrap" style="border-radius: 10px; border: 1px solid var(--admin-line);">
            <table class="orders-table">
                <tbody>
                    <tr class="reports-empty-row">
                        <td>
                            <i class="fa-solid fa-magnifying-glass-minus"></i>
                            No transactions match the current filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="table-wrap" style="border-radius: 10px; border: 1px solid var(--admin-line);">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Channel</th>
                        <th>Payment Status</th>
                        <th>Order Status</th>
                        <th>Date &amp; Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports['payments'] as $rp):
                        $pStatus = strtolower((string) $rp['payment_status']);
                        $oStatus = strtolower((string) $rp['order_status']);
                    ?>
                    <tr>
                        <td>
                            <span class="order-ref-badge">#<?php echo htmlspecialchars($rp['reference_id']); ?></span>
                        </td>
                        <td><strong><?php echo htmlspecialchars($rp['first_name'] . ' ' . $rp['last_name']); ?></strong></td>
                        <td>
                            <strong style="color: var(--admin-brown); font-size: 14px;">₱<?php echo number_format((float) $rp['amount'], 2); ?></strong>
                        </td>
                        <td>
                            <span class="order-pay-channel">
                                <i class="fa-solid fa-mobile-screen"></i> <?php echo htmlspecialchars($rp['payment_channel'] ?? 'GCash'); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($pStatus === 'paid'): ?>
                                <span class="status-chip is-paid"><i class="fa-solid fa-circle-check"></i> Paid</span>
                            <?php elseif ($pStatus === 'failed'): ?>
                                <span class="status-chip is-failed"><i class="fa-solid fa-circle-xmark"></i> Failed</span>
                            <?php else: ?>
                                <span class="status-chip is-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($oStatus === 'processing'): ?>
                                <span class="status-chip is-processing"><i class="fa-solid fa-rotate"></i> Processing</span>
                            <?php elseif ($oStatus === 'completed'): ?>
                                <span class="status-chip is-completed"><i class="fa-solid fa-check"></i> Completed</span>
                            <?php elseif ($oStatus === 'cancelled'): ?>
                                <span class="status-chip is-cancelled"><i class="fa-solid fa-ban"></i> Cancelled</span>
                            <?php else: ?>
                                <span class="status-chip is-pending"><i class="fa-solid fa-hourglass-half"></i> Pending</span>
                            <?php endif; ?>
                        </td>
                        <td style="color: var(--admin-muted); font-size: 12px;"><?php echo date('M d, Y · g:i A', strtotime($rp['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<script>
(function () {
    'use strict';

    const forms = document.querySelectorAll('[data-reports-focus]');

    // Keep the user on the section they filtered instead of bouncing to the top:
    // the form submits to <page>?filters#<section>, so the browser lands back
    // exactly where they were working.
    forms.forEach(function (form) {
        form.addEventListener('submit', function () {
            form.action = window.location.pathname + '#' + form.getAttribute('data-reports-focus');
        });
    });

    // Filter as the user types — paused once the browser starts navigating so a
    // pending timer can't fire a second request mid-submit.
    let isNavigating = false;
    window.addEventListener('beforeunload', function () { isNavigating = true; });

    document.querySelectorAll('.reports-search input[type="search"]').forEach(function (input) {
        let timer = null;
        input.addEventListener('input', function () {
            if (isNavigating) return;
            clearTimeout(timer);
            timer = setTimeout(function () {
                const form = input.closest('form');
                if (form) form.submit();
            }, 700);
        });
    });
})();
</script>