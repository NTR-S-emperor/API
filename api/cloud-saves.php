<?php
/**
 * Cloud Saves API
 * Place this file on your PRIVATE server
 *
 * Endpoints:
 *   POST ?action=save   → Save to cloud, returns {"success": true, "code": "ABCDE"}
 *   POST ?action=load   → Load from cloud, body: {"code": "ABCDE"}
 *   POST ?action=delete → Delete from cloud, body: {"code": "ABCDE"}
 */

// ============================================================================
// CONFIGURATION - MODIFY THESE VALUES
// ============================================================================

$DB_HOST = 'localhost';
$DB_NAME = 'X';
$DB_USER = 'X';
$DB_PASS = 'X';

// Limits
$MAX_SAVES_TOTAL = 15000;            // Maximum saves in database
$MAX_SAVES_PER_HOUR = 5;             // Flood protection (per IP+device combo)
$MAX_LOADS_PER_HOUR = 10;            // Load requests per IP per hour
$MAX_FAILED_CODES_PER_HOUR = 6;      // Failed code attempts per IP per hour
$GLOBAL_MAX_REQUESTS_PER_HOUR = 3000;   // Circuit breaker - disable API if exceeded
$GLOBAL_MAX_REQUESTS_PER_MINUTE = 1000; // Burst protection
$EXPIRATION_DAYS = 120;              // 4 months = ~120 days
$MAX_SAVE_SIZE_KB = 200;             // Maximum save size in KB

// CORS - Add your domain(s) here
$ALLOWED_ORIGINS = [
    'https://X',
    'http://localhost',
    'http://127.0.0.1'
];

// ============================================================================
// DO NOT MODIFY BELOW THIS LINE
// ============================================================================

// CORS Headers
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $ALLOWED_ORIGINS)) {
    header("Access-Control-Allow-Origin: $origin");
}
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get action
$action = $_GET['action'] ?? '';

// Get request body
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Database connection
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Get hashed IP (for privacy, we don't store the actual IP)
$ip_hash = hash('sha256', $_SERVER['REMOTE_ADDR'] . 'cloud_saves_salt_2024');

// Get device ID from request and hash it
$device_id = $input['deviceId'] ?? '';
$device_hash = $device_id ? hash('sha256', $device_id . 'device_salt_2024') : '';

// Create combined identifier (IP + Device for better rate limiting)
$combined_hash = hash('sha256', $ip_hash . $device_hash . 'combined_salt');

// Clean up old saves and request logs (run occasionally)
if (rand(1, 100) === 1) {
    $pdo->exec("DELETE FROM cloud_saves WHERE created_at < DATE_SUB(NOW(), INTERVAL $EXPIRATION_DAYS DAY)");
    $pdo->exec("DELETE FROM request_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
}

// ============================================================================
// GLOBAL CIRCUIT BREAKER - Disable API if too many requests
// ============================================================================

// Check per-minute limit (burst protection)
$stmt = $pdo->query("SELECT COUNT(*) FROM request_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
$requests_per_minute = $stmt->fetchColumn();

// Check per-hour limit
$stmt = $pdo->query("SELECT COUNT(*) FROM request_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$requests_per_hour = $stmt->fetchColumn();

if ($requests_per_minute >= $GLOBAL_MAX_REQUESTS_PER_MINUTE || $requests_per_hour >= $GLOBAL_MAX_REQUESTS_PER_HOUR) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'api.offline',
        'message' => 'The database is offline for 1 hour due to potentially fraudulent usage by some users.'
    ]);
    exit;
}

// Log this request
$pdo->prepare("INSERT INTO request_log (ip_hash, action) VALUES (?, ?)")->execute([$ip_hash, $action]);

// ============================================================================
// ACTIONS
// ============================================================================

switch ($action) {
    case 'save':
        handleSave($pdo, $input, $ip_hash, $device_hash, $combined_hash);
        break;
    case 'load':
        handleLoad($pdo, $input, $ip_hash);
        break;
    // DELETE disabled - saves are only cleaned up by expiration (4 months)
    // case 'delete':
    //     handleDelete($pdo, $input);
    //     break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

// ============================================================================
// HANDLERS
// ============================================================================

function handleSave($pdo, $input, $ip_hash, $device_hash, $combined_hash) {
    global $MAX_SAVES_TOTAL, $MAX_SAVES_PER_HOUR, $MAX_SAVE_SIZE_KB;

    // Validate input
    if (empty($input['data'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No save data provided']);
        return;
    }

    $save_data = $input['data'];

    // Check size
    $size_kb = strlen($save_data) / 1024;
    if ($size_kb > $MAX_SAVE_SIZE_KB) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Save too large']);
        return;
    }

    // Check flood - by IP
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cloud_saves WHERE ip_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$ip_hash]);
    $ip_saves = $stmt->fetchColumn();

    // Check flood - by device (if provided)
    $device_saves = 0;
    if ($device_hash) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cloud_saves WHERE device_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->execute([$device_hash]);
        $device_saves = $stmt->fetchColumn();
    }

    // Use the highest count (catches both IP hoppers and device clearers)
    $recent_saves = max($ip_saves, $device_saves);

    if ($recent_saves >= $MAX_SAVES_PER_HOUR) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many saves. Please wait before saving again.']);
        return;
    }

    // Check total saves limit
    $stmt = $pdo->query("SELECT COUNT(*) FROM cloud_saves");
    $total_saves = $stmt->fetchColumn();

    if ($total_saves >= $MAX_SAVES_TOTAL) {
        // Delete oldest save to make room
        $pdo->exec("DELETE FROM cloud_saves ORDER BY created_at ASC LIMIT 1");
    }

    // Generate unique code
    $code = generateUniqueCode($pdo);

    if (!$code) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not generate code']);
        return;
    }

    // Insert save with both hashes and size
    $stmt = $pdo->prepare("INSERT INTO cloud_saves (code, save_data, save_size_kb, ip_hash, device_hash) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$code, $save_data, round($size_kb, 2), $ip_hash, $device_hash]);

    echo json_encode(['success' => true, 'code' => $code]);
}

function handleLoad($pdo, $input, $ip_hash) {
    global $MAX_LOADS_PER_HOUR, $MAX_FAILED_CODES_PER_HOUR;

    // Check load rate limit (per IP)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM request_log WHERE ip_hash = ? AND action = 'load' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$ip_hash]);
    $load_count = $stmt->fetchColumn();

    if ($load_count > $MAX_LOADS_PER_HOUR) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait before trying again.']);
        return;
    }

    // Check failed attempts rate limit (anti brute-force)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM request_log WHERE ip_hash = ? AND action = 'load_failed' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$ip_hash]);
    $failed_count = $stmt->fetchColumn();

    if ($failed_count >= $MAX_FAILED_CODES_PER_HOUR) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many failed attempts. Please wait before trying again.']);
        return;
    }

    // Validate code
    $code = strtoupper(trim($input['code'] ?? ''));

    // Only allow valid characters (exclude I, L, O)
    if (!preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ]{5}$/', $code)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid code format']);
        return;
    }

    // Find save
    $stmt = $pdo->prepare("SELECT save_data FROM cloud_saves WHERE code = ?");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Log failed attempt
        $pdo->prepare("INSERT INTO request_log (ip_hash, action) VALUES (?, 'load_failed')")->execute([$ip_hash]);
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Save not found']);
        return;
    }

    echo json_encode(['success' => true, 'data' => $row['save_data']]);
}

function handleDelete($pdo, $input) {
    // Validate code
    $code = strtoupper(trim($input['code'] ?? ''));

    // Only allow valid characters (exclude I, L, O)
    if (!preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ]{5}$/', $code)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid code format']);
        return;
    }

    // Delete save
    $stmt = $pdo->prepare("DELETE FROM cloud_saves WHERE code = ?");
    $stmt->execute([$code]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Save not found']);
    }
}

function generateUniqueCode($pdo) {
    // Exclude ambiguous characters: I, L, O (and 0, 1 if numbers were used)
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    $chars_len = strlen($chars);
    $max_attempts = 100;

    for ($i = 0; $i < $max_attempts; $i++) {
        $code = '';
        for ($j = 0; $j < 5; $j++) {
            $code .= $chars[random_int(0, $chars_len - 1)];
        }

        // Check if code exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cloud_saves WHERE code = ?");
        $stmt->execute([$code]);

        if ($stmt->fetchColumn() == 0) {
            return $code;
        }
    }

    return null;
}
