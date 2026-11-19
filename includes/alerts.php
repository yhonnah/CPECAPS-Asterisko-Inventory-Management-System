<?php
/**
 * Low-Stock Reorder Point (ROP) Alerting & Discord Webhook Integration
 * Supports TC-06, TC-07, TC-32, TC-38.
 */

if (!function_exists('checkAndNotifyLowStock')) {
    /**
     * Evaluates Stock <= ROP for the given item IDs, records low_stock_alert events,
     * and dispatches Discord webhook notifications if DISCORD_WEBHOOK_URL is configured.
     *
     * @param PDO   $pdo          Active database connection
     * @param array $itemIds      List of stock_item IDs touched in the transaction
     * @param string $actingUser  Username triggering the stock change
     * @param array $prevBalances Optional map [item_id => previous_quantity] for crossing detection
     * @return array List of alerted items
     */
    function checkAndNotifyLowStock(PDO $pdo, array $itemIds, string $actingUser = 'System', array $prevBalances = []): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn($id) => $id > 0)));
        if (empty($itemIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id, NAME, category, quantity, unit, reorder_point
            FROM stock_items
            WHERE id IN ({$placeholders}) AND is_active = 1
        ");
        $stmt->execute($itemIds);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $alerted = [];
        $webhookUrl = getenv('DISCORD_WEBHOOK_URL') ?: ($_ENV['DISCORD_WEBHOOK_URL'] ?? '');

        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (item_id, previous_value, new_value, change_type, reason, acting_user)
            VALUES (?, ?, ?, 'low_stock_alert', ?, ?)
        ");
        $recentCheckStmt = $pdo->prepare("
            SELECT id FROM audit_logs
            WHERE item_id = ? AND change_type = 'low_stock_alert'
              AND created_at >= (NOW() - INTERVAL 10 MINUTE)
            LIMIT 1
        ");

        foreach ($items as $item) {
            $id = (int)$item['id'];
            $qty = round((float)$item['quantity'], 4);
            $rop = round((float)$item['reorder_point'], 4);

            // TC-38 boundary equality: Stock <= ROP triggers Low Stock
            if ($qty <= $rop) {
                $crossedThreshold = isset($prevBalances[$id]) && ((float)$prevBalances[$id] > $rop);

                if (!$crossedThreshold) {
                    $recentCheckStmt->execute([$id]);
                    if ($recentCheckStmt->fetchColumn()) {
                        continue; // Suppress duplicate alert within 10 minutes if already below ROP
                    }
                }

                $name = $item['NAME'] ?? "Item #{$id}";
                $unit = $item['unit'] ?? '';
                $prevQty = isset($prevBalances[$id]) ? round((float)$prevBalances[$id], 4) : $qty;
                $reason = "Low-Stock Alert: {$name} reached {$qty} {$unit} (ROP: {$rop} {$unit})";
                if ($webhookUrl !== '') {
                    $reason .= " [Discord Dispatched]";
                }

                $auditStmt->execute([$id, $prevQty, $qty, mb_substr($reason, 0, 255), $actingUser]);

                $alertItem = [
                    'item_id' => $id,
                    'name' => $name,
                    'category' => $item['category'] ?? 'General',
                    'quantity' => $qty,
                    'reorder_point' => $rop,
                    'unit' => $unit,
                    'triggered_at' => gmdate('c'),
                ];
                $alerted[] = $alertItem;
            }
        }

        if (!empty($alerted) && $webhookUrl !== '') {
            dispatchDiscordLowStockWebhook($webhookUrl, $alerted, $actingUser);
        }

        return $alerted;
    }
}

if (!function_exists('dispatchDiscordLowStockWebhook')) {
    function dispatchDiscordLowStockWebhook(string $webhookUrl, array $alertedItems, string $actingUser): bool
    {
        $fields = [];
        foreach (array_slice($alertedItems, 0, 25) as $item) {
            $fields[] = [
                'name' => $item['name'] . ' (' . $item['category'] . ')',
                'value' => "Stock: **{$item['quantity']} {$item['unit']}** | ROP: {$item['reorder_point']} {$item['unit']}",
                'inline' => true,
            ];
        }

        $payload = json_encode([
            'username' => 'Asterisko Inventory Bot',
            'embeds' => [[
                'title' => 'Low-Stock Reorder Alert (' . count($alertedItems) . ' item' . (count($alertedItems) > 1 ? 's' : '') . ')',
                'description' => "Stock reached or dropped below Reorder Point (triggered by **{$actingUser}**).",
                'color' => 15158332, // Red
                'fields' => $fields,
                'timestamp' => gmdate('c'),
            ]],
        ]);

        if (function_exists('curl_init')) {
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 3,
            ]);
            $res = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $res !== false && $code >= 200 && $code < 300;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 3,
                'ignore_errors' => true,
            ]
        ]);
        return @file_get_contents($webhookUrl, false, $ctx) !== false;
    }
}
