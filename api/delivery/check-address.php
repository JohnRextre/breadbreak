<?php
// =============================================================================
// api/delivery/check-address.php  –  BreadBreak Delivery Zone Check
//
// POST /BreadBreak/api/delivery/check-address.php
// Body (JSON): { "address": "your address string" }
//
// Returns JSON:
//   { allowed, zone, delivery_fee, min_order, message,
//     distance_km?, drive_time_min?, mode_used }
// =============================================================================
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Only POST allowed ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Parse JSON body ───────────────────────────────────────────────────────────
$body    = json_decode(file_get_contents('php://input'), true);
$address = trim((string) ($body['address'] ?? ''));

if ($address === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Address is required']);
    exit;
}

$pdo = getDatabaseConnection();

// ── Load settings from DB ─────────────────────────────────────────────────────
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare('SELECT setting_value FROM delivery_settings WHERE setting_key = :k LIMIT 1');
    $stmt->execute(['k' => $key]);
    return (string) ($stmt->fetchColumn() ?: $default);
}

$mode          = getSetting($pdo, 'delivery_mode', 'simple');
$zonesJson     = getSetting($pdo, 'delivery_zones', '[]');
$areasJson     = getSetting($pdo, 'in_range_areas', '[]');
$branchLat     = (float) getSetting($pdo, 'branch_lat', '14.8310');
$branchLng     = (float) getSetting($pdo, 'branch_lng', '120.8720');
$maxDriveTime  = (int)   getSetting($pdo, 'max_drive_time_min', '25');
$geoapifyKey   = getSetting($pdo, 'geoapify_api_key', '');

$zones    = json_decode($zonesJson, true)  ?: [];
$inAreas  = json_decode($areasJson, true)  ?: [];

// ── HELPER: Zone assignment by distance ───────────────────────────────────────
function assignZone(array $zones, float $km): ?array {
    // Sort zones by max_km ascending
    usort($zones, fn($a, $b) => $a['max_km'] <=> $b['max_km']);
    foreach ($zones as $zone) {
        if ($km <= (float) $zone['max_km']) {
            return $zone;
        }
    }
    return null; // out of zone
}

// =============================================================================
// MODE: SIMPLE — keyword match, no API
// =============================================================================
function checkSimple(string $address, array $inAreas, array $zones): array {
    $normalized = mb_strtolower(trim($address));
    foreach ($inAreas as $area) {
        if (str_contains($normalized, mb_strtolower($area))) {
            // Simple mode always returns zone_1 (free) — no distance data
            $zone = $zones[0] ?? ['name' => 'zone_1', 'fee' => 0, 'min_order' => 0];
            return [
                'allowed'       => true,
                'zone'          => $zone['name'],
                'delivery_fee'  => (int) $zone['fee'],
                'min_order'     => (int) $zone['min_order'],
                'message'       => 'Delivery available to ' . ucwords($area) . '.',
                'mode_used'     => 'simple',
            ];
        }
    }
    return [
        'allowed'       => false,
        'zone'          => null,
        'delivery_fee'  => null,
        'min_order'     => null,
        'message'       => "Sorry, we don't deliver to your area yet. BreadBreak delivers within 8km of our branch in Estrella Village, Guiguinto.",
        'mode_used'     => 'simple',
    ];
}

// =============================================================================
// MODE: FULL — Nominatim geocode → OSRM driving distance (both free, no key)
// =============================================================================
function geocodeNominatim(PDO $pdo, string $address): ?array {
    $hash = hash('sha256', mb_strtolower(trim($address)));

    // Check 24-hr cache
    $cacheStmt = $pdo->prepare(
        "SELECT latitude, longitude, geocode_success, cached_at
         FROM delivery_geocode_cache
         WHERE address_hash = :h LIMIT 1"
    );
    $cacheStmt->execute(['h' => $hash]);
    $cached = $cacheStmt->fetch();

    if ($cached) {
        $age = time() - strtotime($cached['cached_at']);
        if ($age < 86400) { // 24 hours
            return $cached['geocode_success']
                ? ['lat' => (float)$cached['latitude'], 'lng' => (float)$cached['longitude']]
                : null;
        }
    }

    // Nominatim request (must include User-Agent)
    $url = 'https://nominatim.openstreetmap.org/search?'
         . http_build_query(['q' => $address . ', Philippines', 'format' => 'json', 'limit' => 1]);

    $ctx = stream_context_create(['http' => [
        'method'     => 'GET',
        'header'     => "User-Agent: BreadBreak/1.0 (breadbreak-ecommerce)\r\n",
        'timeout'    => 6,
        'ignore_errors' => true,
    ]]);

    $raw = @file_get_contents($url, false, $ctx);
    $data = $raw ? json_decode($raw, true) : null;

    $lat = null; $lng = null; $success = 0;
    if (!empty($data[0]['lat']) && !empty($data[0]['lon'])) {
        $lat = (float) $data[0]['lat'];
        $lng = (float) $data[0]['lon'];
        $success = 1;
    }

    // Upsert cache
    $pdo->prepare(
        "INSERT INTO delivery_geocode_cache
             (address_hash, address_raw, latitude, longitude, geocode_success, cached_at)
         VALUES (:h, :raw, :lat, :lng, :ok, NOW())
         ON DUPLICATE KEY UPDATE
             latitude = VALUES(latitude),
             longitude = VALUES(longitude),
             geocode_success = VALUES(geocode_success),
             cached_at = NOW()"
    )->execute(['h' => $hash, 'raw' => $address, 'lat' => $lat, 'lng' => $lng, 'ok' => $success]);

    return $success ? ['lat' => $lat, 'lng' => $lng] : null;
}

function getDrivingDistanceOSRM(float $branchLat, float $branchLng, float $destLat, float $destLng): ?array {
    // OSRM public API — free, no key, no signup
    // Format: /table/v1/driving/{lng},{lat};{lng},{lat}?annotations=distance,duration
    $url = sprintf(
        'https://router.project-osrm.org/table/v1/driving/%s,%s;%s,%s?annotations=distance,duration&sources=0&destinations=1',
        $branchLng, $branchLat,
        $destLng, $destLat
    );

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "User-Agent: BreadBreak/1.0\r\n",
        'timeout'       => 8,
        'ignore_errors' => true,
    ]]);

    $raw  = @file_get_contents($url, false, $ctx);
    $data = $raw ? json_decode($raw, true) : null;

    if (!empty($data['durations'][0][0]) || !empty($data['distances'][0][0])) {
        return [
            'distance_m'   => (float) ($data['distances'][0][0] ?? 0),
            'duration_sec' => (float) ($data['durations'][0][0] ?? 0),
        ];
    }
    return null;
}

function checkFull(PDO $pdo, string $address, array $zones, float $branchLat, float $branchLng, int $maxDriveTime): array {
    // 1. Geocode
    $geo = geocodeNominatim($pdo, $address);
    if (!$geo) {
        return [
            'allowed'       => false,
            'zone'          => null,
            'delivery_fee'  => null,
            'min_order'     => null,
            'message'       => "We couldn't find your address. Please try adding your barangay name (e.g., 'Ilang-Ilang, Guiguinto, Bulacan').",
            'mode_used'     => 'full',
        ];
    }

    // 2. Driving distance (OSRM)
    $road = getDrivingDistanceOSRM($branchLat, $branchLng, $geo['lat'], $geo['lng']);
    if (!$road) {
        // API down → fallback allowed, flag for review
        return [
            'allowed'       => true,
            'zone'          => 'unknown',
            'delivery_fee'  => 0,
            'min_order'     => 0,
            'message'       => "Distance service is temporarily unavailable. Your order will be reviewed by our team.",
            'mode_used'     => 'full',
            'api_fallback'  => true,
        ];
    }

    $distKm  = round($road['distance_m'] / 1000, 2);
    $driveMin = round($road['duration_sec'] / 60, 1);

    // 3. Assign zone
    $zone = assignZone($zones, $distKm);

    if (!$zone) {
        return [
            'allowed'          => false,
            'zone'             => null,
            'delivery_fee'     => null,
            'min_order'        => null,
            'distance_km'      => $distKm,
            'drive_time_min'   => $driveMin,
            'message'          => "Sorry, we don't deliver to your area yet. BreadBreak delivers within 8km of our branch in Estrella Village, Guiguinto.",
            'mode_used'        => 'full',
        ];
    }

    $driveWarning = $driveMin > $maxDriveTime
        ? "Heads up — delivery may take longer than usual due to traffic. Your bread will still be fresh!"
        : null;

    $feeLabel = $zone['fee'] > 0 ? '₱' . number_format($zone['fee']) . ' delivery fee' : 'Free delivery';
    $timeLabel = 'Est. ' . ceil($driveMin) . ' min';
    $message   = "Delivery to your area: {$feeLabel} · {$timeLabel}";
    if ($driveWarning) $message .= ' ⚠️';

    return [
        'allowed'        => true,
        'zone'           => $zone['name'],
        'delivery_fee'   => (int) $zone['fee'],
        'min_order'      => (int) $zone['min_order'],
        'distance_km'    => $distKm,
        'drive_time_min' => $driveMin,
        'message'        => $message,
        'drive_warning'  => $driveWarning,
        'mode_used'      => 'full',
    ];
}

// =============================================================================
// Dispatch
// =============================================================================
if ($mode === 'full') {
    $result = checkFull($pdo, $address, $zones, $branchLat, $branchLng, $maxDriveTime);
    // If full mode fails geocode, also try simple as extra fallback message hint
} else {
    $result = checkSimple($address, $inAreas, $zones);
}

echo json_encode($result);

