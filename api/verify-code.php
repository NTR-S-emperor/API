<?php
/**
 * Subscription Code Verification API
 * Place this file in your API folder
 *
 * Endpoint:
 *   POST ?action=verify → Verify a subscription code
 *   Body: {"code": "MYCODE123", "tier": "GOLD"}
 *   Response: {"success": true, "tier": "GOLD"} or {"success": false, "error": "..."}
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

$DB_HOST = 'localhost';
$DB_NAME = 'X';
$DB_USER = 'X';
$DB_PASS = 'X';

// Tier hierarchy (index = power level, higher = more access)
$TIER_HIERARCHY = [
    'BRONZE' => 1,
    'SILVER' => 2,
    'GOLD' => 3,
    'DIAMOND' => 4,
    'PLATINUM' => 5
];

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

// ============================================================================
// ACTIONS
// ============================================================================

switch ($action) {
    case 'verify':
        handleVerify($pdo, $input);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

// ============================================================================
// HANDLERS
// ============================================================================

function handleVerify($pdo, $input) {
    global $TIER_HIERARCHY;

    // Validate input
    $code = strtoupper(trim($input['code'] ?? ''));
    $requiredTier = strtoupper(trim($input['tier'] ?? ''));

    if (empty($code)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No code provided']);
        return;
    }

    if (empty($requiredTier) || !isset($TIER_HIERARCHY[$requiredTier])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid tier']);
        return;
    }

    // Find code in database
    $stmt = $pdo->prepare("SELECT tier, is_active FROM subscription_codes WHERE code = ?");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid code']);
        return;
    }

    if (!$row['is_active']) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Code expired']);
        return;
    }

    $codeTier = strtoupper($row['tier']);

    // Check if code tier is sufficient
    if (!isset($TIER_HIERARCHY[$codeTier])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Invalid tier in database']);
        return;
    }

    $codePower = $TIER_HIERARCHY[$codeTier];
    $requiredPower = $TIER_HIERARCHY[$requiredTier];

    if ($codePower >= $requiredPower) {
        // Code is valid and tier is sufficient
        echo json_encode([
            'success' => true,
            'tier' => $codeTier,
            'tierLevel' => $codePower
        ]);
    } else {
        // Code is valid but tier is not sufficient
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Tier insufficient',
            'codeTier' => $codeTier,
            'requiredTier' => $requiredTier
        ]);
    }
}
