<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJson(['ok' => true]);
}

function doNormalizeItems(array $rawItems): array {
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

function doValidatePayload(array $payload): array {
    $partyId = (int) ($payload['partyId'] ?? 0);
    $purchaseOrderId = !empty($payload['purchaseOrderId']) ? (int) $payload['purchaseOrderId'] : null;
    $deliveryDate = trim((string) ($payload['deliveryDate'] ?? ''));
    $status = (string) ($payload['status'] ?? 'Pending');
    $remarks = trim((string) ($payload['remarks'] ?? ''));
    $items = doNormalizeItems(is_array($payload['items'] ?? null) ? $payload['items'] : []);

    if ($partyId <= 0) {
        throw new RuntimeException('Party is required.');
    }
    if ($deliveryDate === '') {
        $deliveryDate = date('Y-m-d');
    }
    $dateCheck = DateTime::createFromFormat('Y-m-d', $deliveryDate);
    if (!$dateCheck || $dateCheck->format('Y-m-d') !== $deliveryDate) {
        throw new RuntimeException('Delivery date must be YYYY-MM-DD.');
    }
    if (!in_array($status, ['Pending', 'Dispatched', 'Delivered', 'Cancelled'], true)) {
        throw new RuntimeException('Invalid delivery order status.');
    }

    $totalAmount = 0;
    foreach ($items as $item) {
        $totalAmount += $item['amount'];
    }

    return [
        'partyId' => $partyId,
        'purchaseOrderId' => $purchaseOrderId,
        'deliveryDate' => $deliveryDate,
        'status' => $status,
        'remarks' => $remarks,
        'items' => $items,
        'totalAmount' => round($totalAmount, 2)
    ];
}

function doFetchItems(PDO $pdo, int $doId): array {
    $stmt = $pdo->prepare(
        'SELECT doi.id, doi.inventory_id, i.inventory_name,
                i.packing_id, COALESCE(pk.name, i.packing) AS packing_name, pk.weight AS packing_weight,
                doi.quantity, doi.rate, doi.amount
         FROM delivery_order_items doi
         LEFT JOIN inventory i ON doi.inventory_id = i.id
         LEFT JOIN packings pk ON pk.id = i.packing_id
         WHERE doi.delivery_order_id = ?
         ORDER BY doi.id ASC'
    );
    $stmt->execute([$doId]);
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

function doMapRow(array $row, array $items = []): array {
    return [
        'id' => (string) $row['id'],
        'partyId' => (int) $row['party_id'],
        'partyName' => $row['party_name'] ?? 'Unknown',
        'purchaseOrderId' => $row['purchase_order_id'] ? (int) $row['purchase_order_id'] : null,
        'purchaseOrderRef' => $row['purchase_order_id']
            ? 'PO-' . str_pad((string) $row['purchase_order_id'], 4, '0', STR_PAD_LEFT)
            : null,
        'deliveryDate' => $row['delivery_date'],
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

function doInsertItems(PDO $pdo, int $doId, array $items): void {
    $stmt = $pdo->prepare(
        'INSERT INTO delivery_order_items (delivery_order_id, inventory_id, quantity, rate, amount)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($items as $item) {
        $inv = $pdo->prepare('SELECT id FROM inventory WHERE id = ?');
        $inv->execute([$item['inventoryId']]);
        if (!$inv->fetch()) {
            throw new RuntimeException('Selected inventory not found.');
        }
        $stmt->execute([
            $doId,
            $item['inventoryId'],
            $item['quantity'],
            $item['rate'],
            $item['amount']
        ]);
    }
}

function doApplyStock(PDO $pdo, int $doId, string $stockDate): void {
    $doStmt = $pdo->prepare('SELECT stock_applied FROM delivery_orders WHERE id = ? FOR UPDATE');
    $doStmt->execute([$doId]);
    $row = $doStmt->fetch();
    if (!$row || (int) $row['stock_applied'] === 1) {
        return;
    }

    $items = $pdo->prepare(
        'SELECT inventory_id, quantity, rate FROM delivery_order_items WHERE delivery_order_id = ?'
    );
    $items->execute([$doId]);
    $rows = $items->fetchAll();

    $stockInsert = $pdo->prepare(
        'INSERT INTO stock (inventory_id, price, piece, stock_date) VALUES (?, ?, ?, ?)'
    );
    $invUpdate = $pdo->prepare(
        'UPDATE inventory SET piece = piece + ?, rate = ? WHERE id = ?'
    );

    foreach ($rows as $item) {
        $qty = (float) $item['quantity'];
        $rate = (float) $item['rate'];
        $inventoryId = (int) $item['inventory_id'];
        if ($qty <= 0) {
            continue;
        }
        $stockInsert->execute([$inventoryId, $rate, $qty, $stockDate]);
        $invUpdate->execute([$qty, $rate, $inventoryId]);
    }

    $pdo->prepare('UPDATE delivery_orders SET stock_applied = 1 WHERE id = ?')->execute([$doId]);
}

function doReverseStock(PDO $pdo, int $doId): void {
    $doStmt = $pdo->prepare('SELECT stock_applied FROM delivery_orders WHERE id = ? FOR UPDATE');
    $doStmt->execute([$doId]);
    $row = $doStmt->fetch();
    if (!$row || (int) $row['stock_applied'] !== 1) {
        return;
    }

    $items = $pdo->prepare(
        'SELECT inventory_id, quantity FROM delivery_order_items WHERE delivery_order_id = ?'
    );
    $items->execute([$doId]);
    $rows = $items->fetchAll();

    $invUpdate = $pdo->prepare('UPDATE inventory SET piece = piece - ? WHERE id = ?');
    foreach ($rows as $item) {
        $invUpdate->execute([(float) $item['quantity'], (int) $item['inventory_id']]);
    }

    $pdo->prepare('UPDATE delivery_orders SET stock_applied = 0 WHERE id = ?')->execute([$doId]);
}

try {
    $pdo = getDbConnection();
    $input = readJsonInput();
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'list':
            $rows = $pdo->query(
                'SELECT d.id, d.party_id, p.name AS party_name, d.purchase_order_id, d.delivery_date,
                        d.status, d.remarks, d.total_amount, d.stock_applied, d.created_at, d.updated_at,
                        (SELECT COUNT(*) FROM delivery_order_items doi WHERE doi.delivery_order_id = d.id) AS item_count
                 FROM delivery_orders d
                 LEFT JOIN parties p ON d.party_id = p.id
                 ORDER BY d.id DESC'
            )->fetchAll();
            $data = array_map(function ($row) {
                return doMapRow($row);
            }, $rows);
            sendJson(['ok' => true, 'data' => $data]);
            break;

        case 'get':
            $id = (int) ($input['id'] ?? 0);
            $stmt = $pdo->prepare(
                'SELECT d.id, d.party_id, p.name AS party_name, d.purchase_order_id, d.delivery_date,
                        d.status, d.remarks, d.total_amount, d.stock_applied, d.created_at, d.updated_at
                 FROM delivery_orders d
                 LEFT JOIN parties p ON d.party_id = p.id
                 WHERE d.id = ?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                sendJson(['ok' => true, 'data' => null]);
            }
            sendJson(['ok' => true, 'data' => doMapRow($row, doFetchItems($pdo, $id))]);
            break;

        case 'create':
            $data = doValidatePayload($input['payload'] ?? []);
            $pdo->beginTransaction();
            try {
                $partyStmt = $pdo->prepare('SELECT id FROM parties WHERE id = ?');
                $partyStmt->execute([$data['partyId']]);
                if (!$partyStmt->fetch()) {
                    throw new RuntimeException('Selected party not found.');
                }
                if ($data['purchaseOrderId']) {
                    $poStmt = $pdo->prepare('SELECT id FROM purchase_orders WHERE id = ?');
                    $poStmt->execute([$data['purchaseOrderId']]);
                    if (!$poStmt->fetch()) {
                        throw new RuntimeException('Linked purchase order not found.');
                    }
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO delivery_orders
                     (party_id, purchase_order_id, delivery_date, status, remarks, total_amount)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $data['partyId'],
                    $data['purchaseOrderId'],
                    $data['deliveryDate'],
                    $data['status'],
                    $data['remarks'],
                    $data['totalAmount']
                ]);
                $doId = (int) $pdo->lastInsertId();
                doInsertItems($pdo, $doId, $data['items']);

                if ($data['status'] === 'Delivered') {
                    doApplyStock($pdo, $doId, $data['deliveryDate']);
                }

                $pdo->commit();
            } catch (Throwable $txError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txError;
            }
            sendJson(['ok' => true, 'data' => ['id' => (string) $doId]]);
            break;

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid delivery order id.'], 400);
            }
            $data = doValidatePayload($input['payload'] ?? []);
            $pdo->beginTransaction();
            try {
                $existingStmt = $pdo->prepare(
                    'SELECT id, stock_applied FROM delivery_orders WHERE id = ? FOR UPDATE'
                );
                $existingStmt->execute([$id]);
                $existing = $existingStmt->fetch();
                if (!$existing) {
                    throw new RuntimeException('Delivery order not found.');
                }

                $partyStmt = $pdo->prepare('SELECT id FROM parties WHERE id = ?');
                $partyStmt->execute([$data['partyId']]);
                if (!$partyStmt->fetch()) {
                    throw new RuntimeException('Selected party not found.');
                }
                if ($data['purchaseOrderId']) {
                    $poStmt = $pdo->prepare('SELECT id FROM purchase_orders WHERE id = ?');
                    $poStmt->execute([$data['purchaseOrderId']]);
                    if (!$poStmt->fetch()) {
                        throw new RuntimeException('Linked purchase order not found.');
                    }
                }

                if ((int) $existing['stock_applied'] === 1 && $data['status'] !== 'Delivered') {
                    doReverseStock($pdo, $id);
                }

                $pdo->prepare('DELETE FROM delivery_order_items WHERE delivery_order_id = ?')->execute([$id]);
                doInsertItems($pdo, $id, $data['items']);

                $stmt = $pdo->prepare(
                    'UPDATE delivery_orders
                     SET party_id = ?, purchase_order_id = ?, delivery_date = ?, status = ?,
                         remarks = ?, total_amount = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $data['partyId'],
                    $data['purchaseOrderId'],
                    $data['deliveryDate'],
                    $data['status'],
                    $data['remarks'],
                    $data['totalAmount'],
                    $id
                ]);

                if ($data['status'] === 'Delivered') {
                    doApplyStock($pdo, $id, $data['deliveryDate']);
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
                sendJson(['ok' => false, 'message' => 'Invalid delivery order id.'], 400);
            }
            $pdo->beginTransaction();
            try {
                $existingStmt = $pdo->prepare(
                    'SELECT id, stock_applied FROM delivery_orders WHERE id = ? FOR UPDATE'
                );
                $existingStmt->execute([$id]);
                $existing = $existingStmt->fetch();
                if ($existing) {
                    if ((int) $existing['stock_applied'] === 1) {
                        doReverseStock($pdo, $id);
                    }
                    $pdo->prepare('DELETE FROM delivery_orders WHERE id = ?')->execute([$id]);
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
