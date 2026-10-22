<?php
require 'db.php';

header('Content-Type: application/json; charset=UTF-8');

// Ensure request is POST for background sync
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed. Expected POST request.'
    ]);
    exit;
}

// 1. Parse incoming payload (JSON or form POST)
$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE || empty($payload)) {
    $payload = !empty($_POST) ? $_POST : null;
}

if (!$payload) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid or empty payload. Provide valid JSON.'
    ]);
    exit;
}

// Acting user matches session pattern or payload
$username = $_SESSION['username'] ?? $payload['acting_user'] ?? $payload['username'] ?? 'barista';
$batch_reason = trim($payload['reason'] ?? 'Physical count background sync');

// Normalize items array (single item or list)
$items = [];
if (isset($payload['items']) && is_array($payload['items'])) {
    $items = $payload['items'];
} elseif (isset($payload['item_id']) && isset($payload['physical_count'])) {
    $items = [$payload];
} elseif (array_is_list($payload)) {
    $items = $payload;
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing item data. Expected items array or item_id with physical_count.'
    ]);
    exit;
}

try {
    // ACID Transaction (matching dashboard.php pattern)
    $pdo->beginTransaction();

    $results = [];
    $discrepancies = 0;

    $selectStmt = $pdo->prepare("SELECT id, name, quantity, unit, reorder_point FROM stock_items WHERE id = ? FOR UPDATE");
    $updateStmt = $pdo->prepare("UPDATE stock_items SET quantity = ? WHERE id = ?");
    $auditStmt  = $pdo->prepare("
        INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
        VALUES (?, ?, ?, 'count_correction', ?, ?)
    ");

    foreach ($items as $entry) {
        $item_id = isset($entry['item_id']) ? (int)$entry['item_id'] : 0;
        if ($item_id <= 0) {
            throw new Exception("Invalid item ID.");
        }

        if (!isset($entry['physical_count']) || !is_numeric($entry['physical_count'])) {
            throw new Exception("Invalid physical count for item ID {$item_id}.");
        }

        $physical_count = round((float)$entry['physical_count'], 2);
        if ($physical_count < 0) {
            throw new Exception("Physical count cannot be negative for item ID {$item_id}.");
        }

        // 1. Lock row and get current quantity
        $selectStmt->execute([$item_id]);
        $item = $selectStmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception("Item not found (ID: {$item_id}).");
        }

        $prev_qty = (float)$item['quantity'];
        $new_qty  = $physical_count;
        $variance = round($new_qty - $prev_qty, 2);
        $has_discrepancy = (abs($variance) >= 0.001);

        if ($has_discrepancy) {
            $discrepancies++;
        }

        // Format variance details for reason string
        $item_note = trim($entry['reason'] ?? $entry['notes'] ?? $batch_reason);
        $variance_sign = ($variance > 0) ? "+{$variance}" : "{$variance}";
        
        $reason = $has_discrepancy
            ? "Count variance: {$variance_sign} {$item['unit']} (Physical: {$new_qty}, Recorded: {$prev_qty})"
            : "Physical count verified: {$new_qty} {$item['unit']}";

        if ($item_note !== '') {
            $reason .= " | {$item_note}";
        }

        // Max length in audit_logs schema is 255
        if (mb_strlen($reason) > 255) {
            $reason = mb_substr($reason, 0, 252) . '...';
        }

        // 2. Update stock_items table
        $updateStmt->execute([$new_qty, $item_id]);

        // 3. Insert immutable record into audit_logs
        $auditStmt->execute([$item_id, $prev_qty, $new_qty, $reason, $username]);

        $results[] = [
            'item_id' => $item_id,
            'name' => $item['name'],
            'unit' => $item['unit'],
            'previous_stock' => $prev_qty,
            'physical_count' => $new_qty,
            'variance' => $variance,
            'variance_formatted' => ($variance > 0 ? '+' : '') . number_format($variance, 2) . " {$item['unit']}",
            'has_discrepancy' => $has_discrepancy,
            'discrepancy_type' => $variance > 0 ? 'surplus' : ($variance < 0 ? 'shortage' : 'none'),
            'reorder_point' => (float)$item['reorder_point'],
            'is_low_stock' => ($new_qty <= (float)$item['reorder_point'])
        ];
    }

    // Commit transaction
    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Stock synchronized and logged to audit trail successfully.',
        'synced_at' => date('Y-m-d H:i:s'),
        'acting_user' => $username,
        'summary' => [
            'total_items' => count($results),
            'discrepancies' => $discrepancies,
            'matches' => count($results) - $discrepancies
        ],
        'results' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => "Transaction failed: " . $e->getMessage()
    ]);
}
