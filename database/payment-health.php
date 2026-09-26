<?php
/**
 * BreadBreak payment pipeline health check.
 *
 *   php database/payment-health.php          report only (read-only)
 *   php database/payment-health.php --fix    also repair stuck online orders
 *
 * Answers the question that costs the most time when something goes wrong:
 * "why is my order still waiting for payment?" — without guessing.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/xendit.php';
require_once __DIR__ . '/../includes/payments.php';

$fix = in_array('--fix', $argv, true);

$ok = 0;
$warn = 0;
$bad = 0;

function line(string $label, string $value, string $state = 'ok'): void
{
    global $ok, $warn, $bad;
    $mark = ['ok' => '[ OK ]', 'warn' => '[WARN]', 'bad' => '[FAIL]'][$state];
    if ($state === 'ok') $ok++; elseif ($state === 'warn') $warn++; else $bad++;
    printf("  %-7s %-26s %s\n", $mark, $label, $value);
}

function head(string $title): void
{
    echo "\n" . $title . "\n" . str_repeat('-', 78) . "\n";
}

$pdo = getDatabaseConnection();

echo "BreadBreak payment pipeline health check\n";
echo 'generated ' . date('Y-m-d H:i:s T') . "\n";

// ── 1. Clocks ───────────────────────────────────────────────────────────────
head('1. Clocks (PHP vs MySQL)');
$dbNow = (string) $pdo->query('SELECT NOW()')->fetchColumn();
$phpNow = date('Y-m-d H:i:s');
$skew = time() - strtotime($dbNow);

line('PHP timezone', date_default_timezone_get(), 'ok');
line('MySQL session tz', (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn(), 'ok');
line('MySQL host tz', (string) $pdo->query('SELECT @@system_time_zone')->fetchColumn(), 'ok');
line('PHP now', $phpNow, 'ok');
line('MySQL NOW()', $dbNow, 'ok');
line(
    'skew',
    $skew === 0 ? 'in sync' : abs($skew) . ' seconds apart — date() vs NOW() comparisons are wrong',
    $skew === 0 ? 'ok' : 'bad'
);

// ── 2. Xendit config ────────────────────────────────────────────────────────
head('2. Xendit configuration');
line('secret key', defined('XENDIT_SECRET_KEY') && strlen(XENDIT_SECRET_KEY) > 20 ? 'present' : 'MISSING',
    defined('XENDIT_SECRET_KEY') && strlen(XENDIT_SECRET_KEY) > 20 ? 'ok' : 'bad');
line(
    'webhook verification token',
    !defined('XENDIT_WEBHOOK_TOKEN')
        || str_contains((string) XENDIT_WEBHOOK_TOKEN, 'YOUR_XENDIT')
        ? 'still the placeholder — anyone can forge callbacks'
        : 'set',
    (!defined('XENDIT_WEBHOOK_TOKEN') || str_contains((string) XENDIT_WEBHOOK_TOKEN, 'YOUR_XENDIT')) ? 'warn' : 'ok'
);

$webhookUrl = defined('XENDIT_WEBHOOK_URL') ? (string) XENDIT_WEBHOOK_URL : '';
line('webhook URL', $webhookUrl !== '' ? $webhookUrl : 'not set', $webhookUrl !== '' ? 'ok' : 'bad');

// ── 3. Is the tunnel / webhook actually reachable? ──────────────────────────
head('3. Webhook reachability (can Xendit deliver?)');

$agentUp = false;
$agent = @file_get_contents('http://127.0.0.1:4040/api/tunnels');
if ($agent !== false) {
    $json = json_decode($agent, true);
    $agentUp = !empty($json['tunnels']);
}
line('ngrok agent', $agentUp ? 'running' : 'not running', $agentUp ? 'ok' : 'warn');

$host = $webhookUrl !== '' ? (string) parse_url($webhookUrl, PHP_URL_HOST) : '';
$isRandomNgrok = (bool) preg_match('/\.ngrok-free\.(app|dev)$/', $host);
line(
    'tunnel type',
    $isRandomNgrok
        ? 'random ngrok subdomain — CHANGES on every restart, so the Xendit dashboard URL goes stale'
        : ($host !== '' ? 'stable host (' . $host . ')' : 'unknown'),
    $isRandomNgrok ? 'warn' : 'ok'
);

if ($webhookUrl !== '') {
    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 405/401/403 all mean "an app is listening and routed this correctly".
    $reachable = in_array($code, [200, 401, 403, 405, 500], true);
    line(
        'webhook responds',
        $reachable ? "yes (HTTP $code)" : "no (HTTP $code) — Xendit cannot deliver callbacks",
        $reachable ? 'ok' : 'bad'
    );
}

// ── 4. Last callback received ───────────────────────────────────────────────
head('4. Last callback received by the webhook');
$log = __DIR__ . '/../webhook/webhook_log.txt';
if (!is_file($log)) {
    line('webhook log', 'never created — the webhook has never been called', 'bad');
} else {
    $lines = array_values(array_filter(array_map('trim', file($log))));
    $last = $lines ? end($lines) : '';
    $age = 0;
    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $last, $m)) {
        $age = time() - strtotime($m[1]);
    }
    line('log file', basename($log), 'ok');
    line(
        'last entry',
        $age > 0
            ? humanAge($age) . ' ago — ' . substr($last, 22, 90)
            : substr($last, 0, 100),
        $age > 3600 ? 'bad' : 'ok'
    );
}

// ── 5. Stuck online orders ──────────────────────────────────────────────────
head('5. Online orders still waiting for payment');

$stuck = $pdo->query(
    "SELECT o.id, o.reference_id, o.created_at, o.total_amount, p.id AS payment_id,
            p.xendit_payment_request_id, p.status AS pay_status, p.xendit_checked_at
     FROM orders o
     JOIN payments p ON p.order_id = o.id
     WHERE UPPER(o.payment_method) <> 'CASH'
       AND p.status = 'pending'
       AND o.status IN ('pending', 'processing')
     ORDER BY o.id DESC
     LIMIT 25"
)->fetchAll();

if (!$stuck) {
    line('stuck orders', 'none', 'ok');
} else {
    line('stuck orders', count($stuck) . ' found', count($stuck) > 0 ? 'warn' : 'ok');
    echo "\n";
    foreach ($stuck as $row) {
        $age = time() - strtotime((string) $row['created_at']);
        printf(
            "    #%s  %s  PHP %s  %s  xendit=%s\n",
            $row['id'],
            $row['reference_id'],
            str_pad('₱' . number_format((float) $row['total_amount'], 2), 9),
            str_pad(humanAge($age) . ' old', 16),
            substr((string) $row['xendit_payment_request_id'], 0, 22)
        );

        if (!$fix) {
            continue;
        }

        $result = verifyOrderPaymentAtXendit($pdo, (int) $row['id'], 0);
        $note = $result['reason'] ?? '';
        if (in_array($result['status'] ?? null, ['paid'], true)) {
            printf("      -> REPAIRED: payment confirmed, order is now baking%s\n", PHP_EOL);
        } else {
            printf("      -> %s\n", $note);
        }
    }

    if (!$fix) {
        echo "\n    re-run with --fix to verify each against Xendit and repair what is payable\n";
    }
}

// ── summary ─────────────────────────────────────────────────────────────────
head('Summary');
printf("  %d ok · %d warning(s) · %d failure(s)\n", $ok, $warn, $bad);
if ($bad > 0) {
    echo "\n  Payments WILL get stuck until the failures above are fixed.\n";
    exit(1);
}
if ($warn > 0) {
    echo "\n  No hard failures, but read the warnings above.\n";
} else {
    echo "\n  Pipeline looks healthy.\n";
}

function humanAge(int $seconds): string
{
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return floor($seconds / 60) . 'm';
    if ($seconds < 86400) return floor($seconds / 3600) . 'h';
    return floor($seconds / 86400) . 'd';
}
