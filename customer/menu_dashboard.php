<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('customer');

define('CUSTOMER_MENU_DASHBOARD', true);
require __DIR__ . '/../menu.php';
