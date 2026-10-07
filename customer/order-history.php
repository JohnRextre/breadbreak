<?php
/**
 * Full Order History — reached from My Orders → "View Order History".
 *
 * My Orders only previews the three newest finished orders so the live orders
 * stay front and center. This screen is the same data set (it reuses
 * order-status.php, which detects this file by name) rendered in its
 * history-only mode: every delivered and cancelled order, with the tab
 * filter plus search and sort.
 */
require __DIR__ . '/order-status.php';
