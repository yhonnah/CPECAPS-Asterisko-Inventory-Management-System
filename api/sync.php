<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/authguard.php';
require_once __DIR__ . '/../includes/alerts.php';

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
$batchSupervisorAck = !empty($payload['supervisor_acknowledged']);

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

if (empty($items)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Items list is empty.'
    ]);
    exit;
}

try {
    $pdo = ensureDbConnection();
    $pdo->beginTransaction();

    $results = [];
    $discrepancies = 0;
    $touchedIds = [];
    $prevBalances = [];

    $selectStmt = $pdo->prepare("SELECT id, NAME, NAME AS name, quantity, unit, reorder_point FROM stock_items WHERE id = ? FOR UPDATE");
    $updateStmt = $pdo->prepare("UPDATE stock_items SET quantity = ? WHERE id = ?");
    $auditStmt  = $pdo->prepare("
        INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $checkDupe = $pdo->prepare("SELECT id FROM audit_logs WHERE item_id = ? AND reason LIKE ? LIMIT 1");

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

        $prev_qty = round((float)$item['quantity'], 4);
        $change_type = $entry['change_type'] ?? 'count_correction';
        $uuid = !empty($entry['uuid']) ? trim((string)$entry['uuid']) : '';

        // Idempotency Check (scoped per item_id + UUID so multi-ingredient orders never collide)
        if ($uuid !== '') {
            $checkDupe->execute([$item_id, "%[UUID: {$uuid}]%"]);
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

        // Conflict detection when cached baseline stock is supplied
        $isConflict = false;
        if (isset($entry['cached_stock']) && abs((float)$entry['cached_stock'] - $prev_qty) >= 0.0001) {
            $isConflict = true;
        }

        if (isset($entry['physical_count'])) {
            if (!is_numeric($entry['physical_count'])) {
                throw new Exception("Quantity must be a positive number.");
            }
            $new_qty = round((float)$entry['physical_count'], 4);
            if ($new_qty < 0) {
                throw new Exception("Quantity must be a positive number.");
            }

            $variance = round($new_qty - $prev_qty, 4);
            $has_discrepancy = (abs($variance) >= 0.0001);
            if ($has_discrepancy) {
                $discrepancies++;
            }

            $item_note = trim($entry['reason'] ?? $entry['notes'] ?? '');
            $supervisorAck = !empty($entry['supervisor_acknowledged']) || $batchSupervisorAck;

            // TC-47: Extreme variance (>100%) safeguard
            $isExtremeVariance = ($prev_qty > 0 && (abs($variance) / $prev_qty) > 1.0);
            if ($isExtremeVariance && (!$supervisorAck || ($item_note === '' && $batch_reason === ''))) {
                $itemName = $item['NAME'] ?? $item['name'] ?? "Item #{$item_id}";
                throw new Exception("Extreme variance (>100%) detected for '{$itemName}'. Supervisor acknowledgment and reason note are required.", 422);
            }

            $variance_sign = ($variance > 0) ? "+{$variance}" : "{$variance}";
            $reason = $has_discrepancy
                ? "Count variance: {$variance_sign} {$item['unit']} (Physical: {$new_qty}, Recorded: {$prev_qty})"
                : "Physical count verified: {$new_qty} {$item['unit']}";
            $effectiveNote = $item_note !== '' ? $item_note : $batch_reason;
            if ($effectiveNote !== '') {
                $reason .= " | {$effectiveNote}";
            }
            if ($isExtremeVariance && $supervisorAck) {
                $reason .= " [SUPERVISOR ACK]";
            }
            if ($isConflict) {
                $reason .= " [OFFLINE SYNC CONFLICT]";
            }
            $change_type = 'count_correction';

        } elseif (
            isset($entry['deduction_amount']) ||
            in_array($change_type, ['deduction', 'waste', 'order_sale', 'manual_deduction'], true)
        ) {
            $rawAmount = $entry['amount'] ?? $entry['deduction_amount'] ?? 0;
            if (!is_numeric($rawAmount) || (float)$rawAmount <= 0) {
                throw new Exception("Quantity must be a positive number for item ID {$item_id}.");
            }
            $amount = round((float)$rawAmount, 4);

            // Commutative Delta Sync (TC-36): subtract delta from live locked balance
            $new_qty = max(0, round($prev_qty - $amount, 4));
            $variance = -$amount;
            $note = trim($entry['reason'] ?? 'Offline shift deduction');
            $label = match ($change_type) {
                'waste' => 'Offline Waste',
                'order_sale' => 'Offline Order Sale',
                'manual_deduction' => 'Offline Manual Deduction',
                default => 'Offline Deduction',
            };
            $reason = "{$label} (-{$amount} {$item['unit']}) | {$note}";
            if ($isConflict) {
                $reason .= " [OFFLINE SYNC CONFLICT]";
            }

        } elseif ($change_type === 'delivery') {
            $rawAmount = $entry['amount'] ?? 0;
            if (!is_numeric($rawAmount) || (float)$rawAmount <= 0) {
                throw new Exception("Quantity must be a positive number for item ID {$item_id}.");
            }
            $amount = round((float)$rawAmount, 4);
            $new_qty = round($prev_qty + $amount, 4);
            $variance = $amount;
            $reason = "Offline Delivery (+{$amount} {$item['unit']}) | " . trim($entry['reason'] ?? '');
            if ($isConflict) {
                $reason .= " [OFFLINE SYNC CONFLICT]";
            }
        } else {
            throw new Exception("Unknown sync operation for item ID {$item_id}.");
        }

        // Preserve UUID tag completely within the 255-char audit_logs.reason column (TC-44)
        $uuidSuffix = ($uuid !== '') ? " [UUID: {$uuid}]" : '';
        $maxBodyLen = 255 - mb_strlen($uuidSuffix);
        if (mb_strlen($reason) > $maxBodyLen) {
            $reason = mb_substr($reason, 0, max(0, $maxBodyLen - 3)) . '...';
        }
        $reason .= $uuidSuffix;

        // Apply update
        $updateStmt->execute([$new_qty, $item_id]);

        // Insert audit log
        $auditStmt->execute([$item_id, $prev_qty, $new_qty, $change_type, $reason, $username]);

        if (!isset($prevBalances[$item_id])) {
            $prevBalances[$item_id] = $prev_qty;
        }
        $touchedIds[] = $item_id;

        $results[] = [
            'item_id' => $item_id,
            'name' => $item['NAME'] ?? $item['name'] ?? '',
            'unit' => $item['unit'],
            'previous_stock' => $prev_qty,
            'physical_count' => $new_qty,
            'variance' => $variance,
            'is_conflict' => $isConflict,
            'is_duplicate' => false,
            'change_type' => $change_type,
            'reorder_point' => round((float)$item['reorder_point'], 4),
            'is_low_stock' => ($new_qty <= round((float)$item['reorder_point'], 4))
        ];
    }

    $pdo->commit();

    // Check low-stock thresholds & dispatch Discord alerts outside transaction lock
    $lowStockAlerts = checkAndNotifyLowStock($pdo, $touchedIds, $username, $prevBalances);

    $duplicateCount = count(array_filter($results, fn($r) => !empty($r['is_duplicate'])));

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Entries synchronized and logged to audit trail.',
        'synced_at' => date('Y-m-d H:i:s'),
        'acting_user' => $username,
        'summary' => [
            'total_items' => count($results),
            'discrepancies' => $discrepancies,
            'duplicates' => $duplicateCount,
            'low_stock_alerts_triggered' => count($lowStockAlerts)
        ],
        'low_stock_alerts' => $lowStockAlerts,
        'results' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $httpCode = ($e->getCode() === 422) ? 422 : 400;
    http_response_code($httpCode);
    $resp = [
        'success' => false,
        'error' => "Transaction failed: " . $e->getMessage()
    ];
    if ($httpCode === 422) {
        $resp['requires_confirmation'] = true;
    }
    echo json_encode($resp);
}
