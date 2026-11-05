<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJson(['ok' => true]);
}

function poNormalizeItems(array $rawItems): array {
    $items = [];
    foreach ($rawItems as $item) {
        $inventoryId = (int) ($item['inventoryId'] ?? 0);
        $quantity = (float) ($item['quantity'] ?? 0);
        $rate = (float) ($item['rate'] ?? 0);
        $hasAny = $inventoryId > 0
            || trim((string) ($item['quantity'] ?? '')) !== ''
            || trim((string) ($item['rate'] ?? '')) !== '';
        if (!$hasAny) {
            continue;
        }
        if ($inventoryId <= 0) {
            throw new RuntimeException('Inventory item is required for each product row.');
        }
        if (!is_numeric((string) ($item['quantity'] ?? '')) || $quantity <= 0) {
            throw new RuntimeException('Quantity must be greater than 0 for each product.');
        }
        if (!is_numeric((string) ($item['rate'] ?? '')) || $rate < 0) {
            throw new RuntimeException('Rate must be a valid number 0 or greater.');
        }
        $amount = round($quantity * $rate, 2);
        $items[] = [
            'inventoryId' => $inventoryId,
            'quantity' => $quantity,
            'rate' => $rate,
            'amount' => $amount
        ];
    }
    if (count($items) === 0) {
        throw new RuntimeException('Please add at least one product line.');
    }
    return $items;
}

function poValidatePayload(array $payload): array {
    $partyId = (int) ($payload['partyId'] ?? 0);
    $orderDate = trim((string) ($payload['orderDate'] ?? ''));
    $status = (string) ($payload['status'] ?? 'Draft');
    $remarks = trim((string) ($payload['remarks'] ?? ''));
    $items = poNormalizeItems(is_array($payload['items'] ?? null) ? $payload['items'] : []);

    if ($partyId <= 0) {
        throw new RuntimeException('Supplier / party is required.');
    }
    if ($orderDate === '') {
        $orderDate = date('Y-m-d');
    }
    $dateCheck = DateTime::createFromFormat('Y-m-d', $orderDate);
    if (!$dateCheck || $dateCheck->format('Y-m-d') !== $orderDate) {
        throw new RuntimeException('Order date must be YYYY-MM-DD.');
    }
    if (!in_array($status, ['Draft', 'Ordered', 'Partial', 'Received', 'Cancelled'], true)) {
        throw new RuntimeException('Invalid purchase order status.');
    }

    $totalAmount = 0;
    foreach ($items as $item) {
        $totalAmount += $item['amount'];
    }

    return [
        'partyId' => $partyId,
        'orderDate' => $orderDate,
        'status' => $status,
        'remarks' => $remarks,
        'items' => $items,
        'totalAmount' => round($totalAmount, 2)
    ];
}

function poFetchItems(PDO $pdo, int $poId): array {
    $stmt = $pdo->prepare(
        'SELECT poi.id, poi.inventory_id, i.inventory_name,
                i.packing_id, COALESCE(pk.name, i.packing) AS packing_name, pk.weight AS packing_weight,
                poi.quantity, poi.rate, poi.amount
         FROM purchase_order_items poi
         LEFT JOIN inventory i ON poi.inventory_id = i.id
         LEFT JOIN packings pk ON pk.id = i.packing_id
         WHERE poi.purchase_order_id = ?
         ORDER BY poi.id ASC'
    );
    $stmt->execute([$poId]);
    return array_map(function ($row) {
        return [
            'id' => (string) $row['id'],
            'inventoryId' => (int) $row['inventory_id'],
            'inventoryName' => $row['inventory_name'] ?? 'Unknown',
            'packingId' => !empty($row['packing_id']) ? (string) $row['packing_id'] : null,
            'packing' => $row['packing_name'] ?? '',
            'packingWeight' => isset($row['packing_weight']) && $row['packing_weight'] !== null
                ? (float) $row['packing_weight']
                : null,
            'quantity' => (float) $row['quantity'],
            'rate' => (float) $row['rate'],
            'amount' => (float) $row['amount']
        ];
    }, $stmt->fetchAll());
}

function poMapRow(array $row, array $items = []): array {
    return [
        'id' => (string) $row['id'],
        'partyId' => (int) $row['party_id'],
        'partyName' => $row['party_name'] ?? 'Unknown',
        'orderDate' => $row['order_date'],
        'status' => $row['status'],
        'remarks' => $row['remarks'] ?? '',
        'totalAmount' => (float) $row['total_amount'],
        'stockApplied' => (bool) $row['stock_applied'],
        'itemCount' => isset($row['item_count']) ? (int) $row['item_count'] : count($items),
        'items' => $items,
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at']
    ];
}

function poInsertItems(PDO $pdo, int $poId, array $items): void {
    $stmt = $pdo->prepare(
        'INSERT INTO purchase_order_items (purchase_order_id, inventory_id, quantity, rate, amount)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($items as $item) {
        $inv = $pdo->prepare('SELECT id FROM inventory WHERE id = ?');
        $inv->execute([$item['inventoryId']]);
        if (!$inv->fetch()) {
            throw new RuntimeException('Selected inventory not found.');
        }
        $stmt->execute([
            $poId,
            $item['inventoryId'],
            $item['quantity'],
            $item['rate'],
            $item['amount']
        ]);
    }
}

function poApplyStock(PDO $pdo, int $poId, string $stockDate): void {
    $poStmt = $pdo->prepare('SELECT stock_applied, status FROM purchase_orders WHERE id = ? FOR UPDATE');
    $poStmt->execute([$poId]);
    $po = $poStmt->fetch();
    if (!$po || (int) $po['stock_applied'] === 1) {
        return;
    }

    $items = $pdo->prepare(
        'SELECT inventory_id, quantity, rate FROM purchase_order_items WHERE purchase_order_id = ?'
    );
    $items->execute([$poId]);
    $rows = $items->fetchAll();

    $stockInsert = $pdo->prepare(
        'INSERT INTO stock (inventory_id, price, piece, stock_date) VALUES (?, ?, ?, ?)'
    );
    $invUpdate = $pdo->prepare(
        'UPDATE inventory SET piece = piece + ?, rate = ? WHERE id = ?'
    );

    foreach ($rows as $row) {
        $qty = (float) $row['quantity'];
        $rate = (float) $row['rate'];
        $inventoryId = (int) $row['inventory_id'];
        if ($qty <= 0) {
            continue;
        }
        $stockInsert->execute([$inventoryId, $rate, $qty, $stockDate]);
        $invUpdate->execute([$qty, $rate, $inventoryId]);
    }

    $pdo->prepare('UPDATE purchase_orders SET stock_applied = 1 WHERE id = ?')->execute([$poId]);
}

function poReverseStock(PDO $pdo, int $poId): void {
    $poStmt = $pdo->prepare('SELECT stock_applied FROM purchase_orders WHERE id = ? FOR UPDATE');
    $poStmt->execute([$poId]);
    $po = $poStmt->fetch();
    if (!$po || (int) $po['stock_applied'] !== 1) {
        return;
    }

    $items = $pdo->prepare(
        'SELECT inventory_id, quantity FROM purchase_order_items WHERE purchase_order_id = ?'
    );
    $items->execute([$poId]);
    $rows = $items->fetchAll();

    $invUpdate = $pdo->prepare('UPDATE inventory SET piece = piece - ? WHERE id = ?');
    foreach ($rows as $row) {
        $invUpdate->execute([(float) $row['quantity'], (int) $row['inventory_id']]);
    }

    $pdo->prepare('UPDATE purchase_orders SET stock_applied = 0 WHERE id = ?')->execute([$poId]);
}

try {
    $pdo = getDbConnection();
    $input = readJsonInput();
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'list':
            $rows = $pdo->query(
                'SELECT po.id, po.party_id, p.name AS party_name, po.order_date, po.status,
                        po.remarks, po.total_amount, po.stock_applied, po.created_at, po.updated_at,
                        (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS item_count
                 FROM purchase_orders po
                 LEFT JOIN parties p ON po.party_id = p.id
                 ORDER BY po.id DESC'
            )->fetchAll();
            $data = array_map(function ($row) {
                return poMapRow($row);
            }, $rows);
            sendJson(['ok' => true, 'data' => $data]);
            break;

        case 'get':
            $id = (int) ($input['id'] ?? 0);
            $stmt = $pdo->prepare(
                'SELECT po.id, po.party_id, p.name AS party_name, po.order_date, po.status,
                        po.remarks, po.total_amount, po.stock_applied, po.created_at, po.updated_at
                 FROM purchase_orders po
                 LEFT JOIN parties p ON po.party_id = p.id
                 WHERE po.id = ?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                sendJson(['ok' => true, 'data' => null]);
            }
            sendJson(['ok' => true, 'data' => poMapRow($row, poFetchItems($pdo, $id))]);
            break;

        case 'create':
            $data = poValidatePayload($input['payload'] ?? []);
            $pdo->beginTransaction();
            try {
                $partyStmt = $pdo->prepare('SELECT id FROM parties WHERE id = ?');
                $partyStmt->execute([$data['partyId']]);
                if (!$partyStmt->fetch()) {
                    throw new RuntimeException('Selected party not found.');
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO purchase_orders (party_id, order_date, status, remarks, total_amount)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $data['partyId'],
                    $data['orderDate'],
                    $data['status'],
                    $data['remarks'],
                    $data['totalAmount']
                ]);
                $poId = (int) $pdo->lastInsertId();
                poInsertItems($pdo, $poId, $data['items']);

                if ($data['status'] === 'Received') {
                    poApplyStock($pdo, $poId, $data['orderDate']);
                }

                $pdo->commit();
            } catch (Throwable $txError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txError;
            }
            sendJson(['ok' => true, 'data' => ['id' => (string) $poId]]);
            break;

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid purchase order id.'], 400);
            }
            $data = poValidatePayload($input['payload'] ?? []);
            $pdo->beginTransaction();
            try {
                $existingStmt = $pdo->prepare(
                    'SELECT id, status, stock_applied FROM purchase_orders WHERE id = ? FOR UPDATE'
                );
                $existingStmt->execute([$id]);
                $existing = $existingStmt->fetch();
                if (!$existing) {
                    throw new RuntimeException('Purchase order not found.');
                }

                $partyStmt = $pdo->prepare('SELECT id FROM parties WHERE id = ?');
                $partyStmt->execute([$data['partyId']]);
                if (!$partyStmt->fetch()) {
                    throw new RuntimeException('Selected party not found.');
                }

                if ((int) $existing['stock_applied'] === 1 && $data['status'] !== 'Received') {
                    poReverseStock($pdo, $id);
                }

                $pdo->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$id]);
                poInsertItems($pdo, $id, $data['items']);

                $stmt = $pdo->prepare(
                    'UPDATE purchase_orders
                     SET party_id = ?, order_date = ?, status = ?, remarks = ?, total_amount = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $data['partyId'],
                    $data['orderDate'],
                    $data['status'],
                    $data['remarks'],
                    $data['totalAmount'],
                    $id
                ]);

                if ($data['status'] === 'Received') {
                    poApplyStock($pdo, $id, $data['orderDate']);
                }

                $pdo->commit();
            } catch (Throwable $txError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txError;
            }
            sendJson(['ok' => true]);
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid purchase order id.'], 400);
            }
            $pdo->beginTransaction();
            try {
                $existingStmt = $pdo->prepare(
                    'SELECT id, stock_applied FROM purchase_orders WHERE id = ? FOR UPDATE'
                );
                $existingStmt->execute([$id]);
                $existing = $existingStmt->fetch();
                if ($existing) {
                    if ((int) $existing['stock_applied'] === 1) {
                        poReverseStock($pdo, $id);
                    }
                    $pdo->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$id]);
                }
                $pdo->commit();
            } catch (Throwable $txError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txError;
            }
            sendJson(['ok' => true]);
            break;

        default:
            sendJson(['ok' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    sendJson(['ok' => false, 'message' => $e->getMessage()], 500);
}
