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

// Extract orders and stock items from payload
$orders = [];
if (isset($payload['orders']) && is_array($payload['orders'])) {
    $orders = $payload['orders'];
} elseif (isset($payload['action']) && $payload['action'] === 'coffee_order') {
    $orders = [$payload];
}

$items = [];
if (isset($payload['items']) && is_array($payload['items'])) {
    $items = $payload['items'];
} elseif (isset($payload['item_id'])) {
    $items = [$payload];
} elseif (array_is_list($payload) && empty($orders)) {
    $items = $payload;
}

if (empty($orders) && empty($items)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing order or stock item data in sync payload.'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    $syncedOrders = 0;
    $results = [];
    $discrepancies = 0;

    // -------------------------------------------------------------
    // 1. PROCESS OFFLINE COFFEE ORDERS
    // -------------------------------------------------------------
    if (!empty($orders)) {
        $stmtCheckOrder = $pdo->prepare("SELECT id FROM coffee_orders WHERE order_number = ? LIMIT 1");
        $stmtInsertOrder = $pdo->prepare("
            INSERT INTO coffee_orders (order_number, order_type, total_amount, items_count, notes, served_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtInsertItem = $pdo->prepare("
            INSERT INTO coffee_order_items (order_id, menu_item_id, item_name, quantity, price, subtotal, deductions_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtInsertSale = $pdo->prepare("
            INSERT INTO sales_records (sale_date, gross_sales, source, notes, recorded_by, created_at)
            VALUES (?, ?, 'Offline POS Sync', ?, ?, ?)
        ");
        $stmtLockStock = $pdo->prepare("SELECT id, NAME, NAME AS name, quantity, unit FROM stock_items WHERE id = ? FOR UPDATE");
        $stmtUpdateStock = $pdo->prepare("UPDATE stock_items SET quantity = ? WHERE id = ?");
        $stmtAuditSale = $pdo->prepare("
            INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
            VALUES (?, ?, ?, 'order_sale', ?, ?)
        ");

        foreach ($orders as $ord) {
            $orderNum = trim($ord['order_number'] ?? ('ORD-SYNC-' . date('Ymd') . '-' . substr(uniqid(), -4)));
            $uuid = trim($ord['uuid'] ?? '');
            
            // Idempotency check: Skip if already inserted
            $stmtCheckOrder->execute([$orderNum]);
            if ($stmtCheckOrder->fetchColumn()) {
                continue;
            }

            $orderType = $ord['order_type'] ?? 'coffee_drink';
            $totalAmount = max(0, (float)($ord['total_amount'] ?? 0));
            $itemsCount = max(1, (int)($ord['items_count'] ?? 1));
            $notes = trim($ord['notes'] ?? 'Offline Terminal Order');
            $servedBy = trim($ord['served_by'] ?? $username);
            $createdAt = !empty($ord['created_at']) ? $ord['created_at'] : date('Y-m-d H:i:s');
            $saleDate = date('Y-m-d', strtotime($createdAt));

            // Insert Coffee Order
            $stmtInsertOrder->execute([$orderNum, $orderType, $totalAmount, $itemsCount, $notes, $servedBy, $createdAt]);
            $orderId = (int)$pdo->lastInsertId();

            // Insert Order Items & Aggregate Deductions
            $orderLines = $ord['items'] ?? [];
            $stockDeltas = []; // stock_id => ['name' => ..., 'needed' => ..., 'unit' => ...]

            foreach ($orderLines as $line) {
                $menuId = !empty($line['id']) ? (int)$line['id'] : null;
                $lineName = trim($line['name'] ?? 'Drink Item');
                $lineQty = max(1, (int)($line['qty'] ?? 1));
                $linePrice = max(0, (float)($line['price'] ?? 0));
                $subtotal = round($lineQty * $linePrice, 2);

                $lineDeductions = [];
                // If ingredients were included in payload
                if (!empty($line['ingredients']) && is_array($line['ingredients'])) {
                    foreach ($line['ingredients'] as $ing) {
                        $sId = (int)($ing['stock_item_id'] ?? $ing['id'] ?? 0);
                        $req = round((float)($ing['quantity_required'] ?? $ing['amount'] ?? 0) * $lineQty, 4);
                        if ($sId > 0 && $req > 0) {
                            if (!isset($stockDeltas[$sId])) {
                                $stockDeltas[$sId] = [
                                    'needed' => 0,
                                    'unit' => $ing['unit'] ?? $ing['stock_unit'] ?? 'units',
                                    'name' => $ing['stock_name'] ?? $ing['name'] ?? ''
                                ];
                            }
                            $stockDeltas[$sId]['needed'] += $req;
                            $lineDeductions[] = "-{$req} {$stockDeltas[$sId]['unit']} (ID: {$sId})";
                        }
                    }
                }

                $stmtInsertItem->execute([
                    $orderId,
                    $menuId,
                    $lineName,
                    $lineQty,
                    $linePrice,
                    $subtotal,
                    json_encode($lineDeductions)
                ]);
            }

            // Deduct stock items & record audit logs
            foreach ($stockDeltas as $stockId => $d) {
                $stmtLockStock->execute([$stockId]);
                $st = $stmtLockStock->fetch(PDO::FETCH_ASSOC);
                if ($st) {
                    $prevStock = (float)$st['quantity'];
                    $newStock = max(0, round($prevStock - $d['needed'], 4));
                    $stmtUpdateStock->execute([$newStock, $stockId]);

                    $auditReason = "Offline Order {$orderNum}: -{$d['needed']} {$st['unit']}";
                    if ($uuid !== '') $auditReason .= " [UUID: {$uuid}]";
                    if (mb_strlen($auditReason) > 255) $auditReason = mb_substr($auditReason, 0, 252) . '...';

                    $stmtAuditSale->execute([$stockId, $prevStock, $newStock, $auditReason, $servedBy]);
                }
            }

            // Record Sales Record
            $saleNotes = "Offline Order #{$orderNum} ({$itemsCount} items)";
            $stmtInsertSale->execute([$saleDate, $totalAmount, $saleNotes, $servedBy, $createdAt]);

            $syncedOrders++;
        }
    }

    // -------------------------------------------------------------
    // 2. PROCESS STOCK ITEMS (MANUAL DEDUCTION, COUNT, WASTE, DELIVERY)
    // -------------------------------------------------------------
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
            'orders_synced' => $syncedOrders,
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
