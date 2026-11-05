<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJson(['ok' => true]);
}

try {
    $pdo = getDbConnection();
    $input = readJsonInput();
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'list':
            $inventoryId = (int) ($input['inventoryId'] ?? 0);
            $startDate = trim((string) ($input['startDate'] ?? ''));
            $endDate = trim((string) ($input['endDate'] ?? ''));

            $query = 'SELECT id, inventory_id, price, piece, stock_date, created_at FROM stock WHERE 1=1';
            $params = [];

            if ($inventoryId > 0) {
                $query .= ' AND inventory_id = ?';
                $params[] = $inventoryId;
            }
            if ($startDate) {
                $query .= ' AND stock_date >= ?';
                $params[] = $startDate;
            }
            if ($endDate) {
                $query .= ' AND stock_date <= ?';
                $params[] = $endDate;
            }

            $query .= ' ORDER BY id DESC';
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $data = array_map(function ($row) {
                return [
                    'id' => (string) $row['id'],
                    'inventoryId' => (int) $row['inventory_id'],
                    'price' => (float) $row['price'],
                    'piece' => (float) $row['piece'],
                    'date' => $row['stock_date'],
                    'createdAt' => $row['created_at']
                ];
            }, $rows);
            sendJson(['ok' => true, 'data' => $data]);
            break;

        case 'create':
            $payload = $input['payload'] ?? [];
            $inventoryId = (int) ($payload['inventoryId'] ?? 0);
            $priceRaw = (string) ($payload['price'] ?? '0');
            $pieceRaw = (string) ($payload['piece'] ?? '0');
            $price = (float) $priceRaw;
            $piece = (float) $pieceRaw;
            $stockDate = trim((string) ($payload['date'] ?? ''));

            if ($inventoryId <= 0) {
                sendJson(['ok' => false, 'message' => 'Inventory is required.'], 400);
            }
            if ($piece <= 0) {
                sendJson(['ok' => false, 'message' => 'Stock piece must be greater than 0.'], 400);
            }
            if (!is_numeric($priceRaw) || $price < 0) {
                sendJson(['ok' => false, 'message' => 'Stock price must be a valid number 0 or greater.'], 400);
            }
            if (!is_numeric($pieceRaw)) {
                sendJson(['ok' => false, 'message' => 'Stock piece must be a valid number.'], 400);
            }
            if (!$stockDate) {
                sendJson(['ok' => false, 'message' => 'Stock date is required.'], 400);
            }
            $dateCheck = DateTime::createFromFormat('Y-m-d', $stockDate);
            if (!$dateCheck || $dateCheck->format('Y-m-d') !== $stockDate) {
                sendJson(['ok' => false, 'message' => 'Stock date must be in YYYY-MM-DD format.'], 400);
            }

            $pdo->beginTransaction();
            try {
                $inventoryStmt = $pdo->prepare('SELECT id FROM inventory WHERE id = ? FOR UPDATE');
                $inventoryStmt->execute([$inventoryId]);
                $inventory = $inventoryStmt->fetch();
                if (!$inventory) {
                    throw new RuntimeException('Selected inventory not found.');
                }

                $insertStmt = $pdo->prepare('INSERT INTO stock (inventory_id, price, piece, stock_date) VALUES (?, ?, ?, ?)');
                $insertStmt->execute([$inventoryId, $price, $piece, $stockDate]);

                $updateInventory = $pdo->prepare('UPDATE inventory SET piece = piece + ?, rate = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $updateInventory->execute([$piece, $price, $inventoryId]);

                $pdo->commit();
            } catch (Throwable $txError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txError;
            }

            sendJson(['ok' => true, 'data' => ['id' => (string) $pdo->lastInsertId()]]);
            break;

        default:
            sendJson(['ok' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    sendJson(['ok' => false, 'message' => $e->getMessage()], 500);
}
