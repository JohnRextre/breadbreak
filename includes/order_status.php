<?php
// Shared order-status helpers for confirmation, tracking, and staff updates.

function ensureOrderStatusEnum(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec(
            "ALTER TABLE orders
             MODIFY COLUMN status ENUM(
                'pending',
                'processing',
                'out_for_delivery',
                'ready_for_pickup',
                'completed',
                'cancelled'
             ) NOT NULL DEFAULT 'pending'"
        );
    } catch (Throwable) {
        // Column already matches, or the account cannot ALTER — ignore.
    }
}

function orderFulfillmentSteps(string $fulfillmentType): array
{
    if ($fulfillmentType === 'pickup') {
        return [
            ['key' => 'pending', 'label' => 'Received', 'icon' => 'receipt'],
            ['key' => 'processing', 'label' => 'Baking', 'icon' => 'bread-slice'],
            ['key' => 'ready_for_pickup', 'label' => 'Ready', 'icon' => 'store'],
            ['key' => 'completed', 'label' => 'Picked Up', 'icon' => 'bag-shopping'],
        ];
    }

    return [
        ['key' => 'pending', 'label' => 'Received', 'icon' => 'receipt'],
        ['key' => 'processing', 'label' => 'Baking', 'icon' => 'bread-slice'],
        ['key' => 'out_for_delivery', 'label' => 'On the Way', 'icon' => 'bicycle'],
        ['key' => 'completed', 'label' => 'Delivered', 'icon' => 'house-chimney'],
    ];
}

function orderStatusStepIndex(string $status): int
{
    return match ($status) {
        'pending' => 0,
        'processing' => 1,
        'out_for_delivery', 'ready_for_pickup' => 2,
        'completed' => 3,
        default => -1,
    };
}

function orderStatusCustomerMessage(string $status, string $fulfillmentType = 'delivery'): string
{
    if ($fulfillmentType === 'pickup') {
        return match ($status) {
            'pending' => 'We received your order and will start preparing it shortly.',
            'processing' => 'Your breads are in the oven. We’ll notify you when they’re ready to pick up.',
            'ready_for_pickup' => 'Your order is ready. Please visit BreadBreak Estrella Village to claim it.',
            'completed' => 'This order has been picked up. Salamat, and enjoy!',
            'cancelled' => 'This order was cancelled. Contact us if you need help placing a new one.',
            default => 'We’re updating your order. Please check back in a moment.',
        };
    }

    return match ($status) {
        'pending' => 'We received your order and will start baking it shortly.',
        'processing' => 'Your breads are being prepared. A rider will head out once they’re packed.',
        'out_for_delivery' => 'Your order is on the way. Please keep your phone nearby.',
        'completed' => 'Your order has been delivered. Salamat, and enjoy!',
        'cancelled' => 'This order was cancelled. Contact us if you need help placing a new one.',
        default => 'We’re updating your order. Please check back in a moment.',
    };
}

function orderStatusCustomerLabel(string $status, string $fulfillmentType = 'delivery'): string
{
    if ($fulfillmentType === 'pickup') {
        return match ($status) {
            'pending' => 'Order received',
            'processing' => 'Now baking',
            'ready_for_pickup' => 'Ready for pickup',
            'completed' => 'Picked up',
            'cancelled' => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    return match ($status) {
        'pending' => 'Order received',
        'processing' => 'Now baking',
        'out_for_delivery' => 'Out for delivery',
        'completed' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function staffOrderStatusOptions(string $fulfillmentType): array
{
    if ($fulfillmentType === 'pickup') {
        return [
            'pending' => 'Received',
            'processing' => 'Baking',
            'ready_for_pickup' => 'Ready for Pickup',
            'completed' => 'Picked Up',
            'cancelled' => 'Cancelled',
        ];
    }

    return [
        'pending' => 'Received',
        'processing' => 'Baking',
        'out_for_delivery' => 'On the Way',
        'completed' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];
}

function allowedOrderStatuses(): array
{
    return ['pending', 'processing', 'out_for_delivery', 'ready_for_pickup', 'completed', 'cancelled'];
}

function renderOrderStatusTracker(string $status, string $fulfillmentType, string $size = 'default'): string
{
    $steps = orderFulfillmentSteps($fulfillmentType);
    $current = orderStatusStepIndex($status);
    $cancelled = $status === 'cancelled';
    $html = '<ol class="bb-tracker bb-tracker-' . htmlspecialchars($size) . ($cancelled ? ' is-cancelled' : '') . '" aria-label="Order progress">';

    foreach ($steps as $i => $step) {
        $state = 'is-upcoming';
        if ($cancelled) {
            $state = 'is-cancelled-step';
        } elseif ($current >= 0) {
            if ($i < $current) {
                $state = 'is-done';
            } elseif ($i === $current) {
                $state = 'is-current';
            }
        }

        $html .= '<li class="' . $state . '">';
        $html .= '<span class="bb-tracker-icon" aria-hidden="true"><i class="fa-solid fa-' . htmlspecialchars($step['icon']) . '"></i></span>';
        $html .= '<span class="bb-tracker-label">' . htmlspecialchars($step['label']) . '</span>';
        $html .= '</li>';
    }

    $html .= '</ol>';
    if ($cancelled) {
        $html .= '<p class="bb-tracker-cancelled-note">This order was cancelled.</p>';
    }

    return $html;
}
