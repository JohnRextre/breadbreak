<?php
/**
 * Delivery history — opened from the rider dashboard's "Delivery history" button.
 *
 * The dashboard only keeps the live trips on screen now, so this screen is the
 * same data set (it reuses dashboard.php, which detects this file by name)
 * rendered in its history-only mode: every completed trip, with the tab-free
 * search and sort on top.
 */
require __DIR__ . '/dashboard.php';
