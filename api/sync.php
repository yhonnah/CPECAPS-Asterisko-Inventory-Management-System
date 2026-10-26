<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';

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

// Session authentication guard
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized session. Please log in to perform synchronization.'
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

// Validate CSRF token
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $payload['csrf_token'] ?? $_POST['csrf_token'] ?? null;
validateCsrfToken($csrfToken);

// Acting user strictly tied to authenticated session
$username = $_SESSION['username'] ?? 'barista';
$batch_reason = trim($payload['reason'] ?? 'Offline background sync');

// Normalize items array
$items = [];
if (isset($payload['items']) && is_array($payload['items'])) {
    $items = $payload['items'];
} elseif (isset($payload['item_id'])) {
    $items = [$payload];
} elseif (array_is_list($payload)) {
    $items = $payload;
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing item data.'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    $results = [];
    $discrepancies = 0;

    $selectStmt = $pdo->prepare("SELECT id, NAME, NAME AS name, quantity, unit, reorder_point FROM stock_items WHERE id = ? FOR UPDATE");
    $updateStmt = $pdo->prepare("UPDATE stock_items SET quantity = ? WHERE id = ?");
    $auditStmt  = $pdo->prepare("
        INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($items as $entry) {
        $item_id = isset($entry['item_id']) ? (int)$entry['item_id'] : 0;
        if ($item_id <= 0) {
            throw new Exception("Invalid item ID.");
        }

        // Lock row and fetch current quantity
        $selectStmt->execute([$item_id]);
        $item = $selectStmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new Exception("Item not found (ID: {$item_id}).");
        }

        $prev_qty = (float)$item['quantity'];
        $change_type = $entry['change_type'] ?? 'count_correction';
        $uuid = !empty($entry['uuid']) ? trim($entry['uuid']) : '';

        // Idempotency Check: if UUID was already processed, skip duplicate subtraction
        if ($uuid !== '') {
            $checkDupe = $pdo->prepare("SELECT id FROM audit_logs WHERE reason LIKE ? LIMIT 1");
            $checkDupe->execute(["%[UUID: {$uuid}]%"]);
            if ($checkDupe->fetchColumn()) {
                $results[] = [
                    'item_id' => $item_id,
                    'name' => $item['NAME'] ?? $item['name'] ?? '',
                    'unit' => $item['unit'],
                    'previous_stock' => $prev_qty,
                    'physical_count' => $prev_qty,
                    'variance' => 0,
                    'is_conflict' => false,
                    'is_duplicate' => true,
                    'change_type' => $change_type
                ];
                continue;
            }
        }

        // Check if conflict detection is needed
        $isConflict = false;
        if (isset($entry['cached_stock']) && abs((float)$entry['cached_stock'] - $prev_qty) >= 0.001) {
            $isConflict = true;
        }

        if (isset($entry['physical_count'])) {
            $new_qty = round((float)$entry['physical_count'], 2);
            $variance = round($new_qty - $prev_qty, 2);
            $has_discrepancy = (abs($variance) >= 0.001);
            if ($has_discrepancy) $discrepancies++;

            $item_note = trim($entry['reason'] ?? $entry['notes'] ?? $batch_reason);
            $variance_sign = ($variance > 0) ? "+{$variance}" : "{$variance}";
            $reason = $has_discrepancy
                ? "Count variance: {$variance_sign} {$item['unit']} (Physical: {$new_qty}, Recorded: {$prev_qty})"
                : "Physical count verified: {$new_qty} {$item['unit']}";
            if ($item_note !== '') $reason .= " | {$item_note}";
            if ($isConflict) $reason .= " [OFFLINE SYNC CONFLICT: Recorded stock changed while offline. Flagged for review]";
            $change_type = 'count_correction';

        } elseif (isset($entry['deduction_amount']) || $change_type === 'deduction' || $change_type === 'waste') {
            $amount = round((float)($entry['amount'] ?? $entry['deduction_amount'] ?? 0), 4);
            if ($amount <= 0) throw new Exception("Invalid deduction amount for item ID {$item_id}.");

            $new_qty = max(0, round($prev_qty - $amount, 4));
            $variance = -$amount;
            $has_discrepancy = false;
            $note = trim($entry['reason'] ?? 'Offline shift deduction');
            $reason = ($change_type === 'waste' ? "Offline Waste (-{$amount} {$item['unit']})" : "Offline Deduction (-{$amount} {$item['unit']})") . " | {$note}";
            if ($isConflict) $reason .= " [OFFLINE SYNC CONFLICT: Flagged for review]";

        } elseif ($change_type === 'delivery') {
            $amount = round((float)($entry['amount'] ?? 0), 2);
            $new_qty = round($prev_qty + $amount, 2);
            $variance = $amount;
            $has_discrepancy = false;
            $reason = "Offline Delivery (+{$amount} {$item['unit']}) | " . trim($entry['reason'] ?? '');
            if ($isConflict) $reason .= " [OFFLINE SYNC CONFLICT: Flagged for review]";
        } else {
            throw new Exception("Unknown sync operation for item ID {$item_id}.");
        }

        if ($uuid !== '') {
            $reason .= " [UUID: {$uuid}]";
        }

        // Limit reason length for schema
        if (mb_strlen($reason) > 255) {
            $reason = mb_substr($reason, 0, 252) . '...';
        }

        // Apply update
        $updateStmt->execute([$new_qty, $item_id]);

        // Insert audit log
        $auditStmt->execute([$item_id, $prev_qty, $new_qty, $change_type, $reason, $username]);

        $results[] = [
            'item_id' => $item_id,
            'name' => $item['NAME'] ?? $item['name'] ?? '',
            'unit' => $item['unit'],
            'previous_stock' => $prev_qty,
            'physical_count' => $new_qty,
            'variance' => $variance,
            'is_conflict' => $isConflict,
            'change_type' => $change_type
        ];
    }

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Entries synchronized and logged to audit trail.',
        'synced_at' => date('Y-m-d H:i:s'),
        'acting_user' => $username,
        'summary' => [
            'total_items' => count($results),
            'discrepancies' => $discrepancies
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
