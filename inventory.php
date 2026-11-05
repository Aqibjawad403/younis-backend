<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJson(['ok' => true]);
}

function mapInventoryRow($row)
{
    return [
        'id' => (string) $row['id'],
        'inventoryName' => $row['inventory_name'],
        'packingId' => !empty($row['packing_id']) ? (string) $row['packing_id'] : null,
        'packing' => $row['packing_name'] ?? $row['packing'] ?? '',
        'packingWeight' => isset($row['packing_weight']) && $row['packing_weight'] !== null
            ? (float) $row['packing_weight']
            : null,
        'rate' => (float) $row['rate'],
        'piece' => (int) $row['piece'],
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at']
    ];
}

function resolveInventoryPacking($pdo, $payload)
{
    $packingId = !empty($payload['packingId']) ? (int) $payload['packingId'] : 0;
    if ($packingId <= 0) {
        sendJson(['ok' => false, 'message' => 'Packing is required.'], 400);
    }
    $stmt = $pdo->prepare('SELECT id, name, weight FROM packings WHERE id = ?');
    $stmt->execute([$packingId]);
    $row = $stmt->fetch();
    if (!$row) {
        sendJson(['ok' => false, 'message' => 'Selected packing not found.'], 400);
    }
    return $row;
}

try {
    $pdo = getDbConnection();
    $input = readJsonInput();
    $action = $input['action'] ?? '';

    $selectSql = 'SELECT i.id, i.inventory_name, i.packing, i.packing_id, i.rate, i.piece,
                         i.created_at, i.updated_at,
                         p.name AS packing_name, p.weight AS packing_weight
                  FROM inventory i
                  LEFT JOIN packings p ON p.id = i.packing_id';

    switch ($action) {
        case 'list':
            $rows = $pdo->query($selectSql . ' ORDER BY i.id DESC')->fetchAll();
            $data = array_map('mapInventoryRow', $rows);
            sendJson(['ok' => true, 'data' => $data]);
            break;

        case 'get':
            $id = (int) ($input['id'] ?? 0);
            $stmt = $pdo->prepare($selectSql . ' WHERE i.id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                sendJson(['ok' => true, 'data' => null]);
            }
            sendJson(['ok' => true, 'data' => mapInventoryRow($row)]);
            break;

        case 'getUsage':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid inventory id.'], 400);
            }

            $exists = $pdo->prepare('SELECT id FROM inventory WHERE id = ?');
            $exists->execute([$id]);
            if (!$exists->fetch()) {
                sendJson(['ok' => true, 'data' => null]);
            }

            $salesStmt = $pdo->prepare(
                "SELECT s.id, COALESCE(s.bill_id, s.id) AS bill_id, s.party_id, p.name AS party_name,
                        s.weight, s.price, s.payment_type,
                        COALESCE(s.sale_date, DATE(s.created_at)) AS sale_date,
                        s.created_at,
                        (
                          SELECT inv.id FROM invoices inv
                          WHERE inv.bill_id = COALESCE(s.bill_id, s.id) OR inv.sale_id = s.id
                          ORDER BY inv.id ASC LIMIT 1
                        ) AS invoice_id,
                        pk.weight AS packing_weight
                 FROM sales s
                 LEFT JOIN parties p ON p.id = s.party_id
                 LEFT JOIN inventory i ON i.id = s.inventory_id
                 LEFT JOIN packings pk ON pk.id = i.packing_id
                 WHERE s.inventory_id = ?
                 ORDER BY s.id DESC"
            );
            $salesStmt->execute([$id]);
            $salesRows = $salesStmt->fetchAll();

            $sales = array_map(function ($row) {
                $packingKg = isset($row['packing_weight']) && (float) $row['packing_weight'] > 0
                    ? (float) $row['packing_weight']
                    : 1.0;
                $weight = (float) $row['weight'];
                $price = (float) $row['price'];
                return [
                    'id' => (string) $row['id'],
                    'billId' => (string) $row['bill_id'],
                    'invoiceId' => !empty($row['invoice_id']) ? (string) $row['invoice_id'] : null,
                    'partyId' => (string) $row['party_id'],
                    'partyName' => $row['party_name'] ?? 'Unknown',
                    'weight' => $weight,
                    'price' => $price,
                    'lineTotal' => round($packingKg * $weight * $price, 2),
                    'paymentType' => $row['payment_type'],
                    'date' => $row['sale_date'],
                    'createdAt' => $row['created_at'],
                ];
            }, $salesRows);

            $poStmt = $pdo->prepare(
                "SELECT poi.id, poi.purchase_order_id, po.order_date, po.status,
                        p.name AS party_name, poi.quantity, poi.rate, poi.amount
                 FROM purchase_order_items poi
                 INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
                 LEFT JOIN parties p ON p.id = po.party_id
                 WHERE poi.inventory_id = ?
                 ORDER BY poi.id DESC"
            );
            $poRows = [];
            try {
                $poStmt->execute([$id]);
                $poRows = $poStmt->fetchAll();
            } catch (Throwable $e) {
                $poRows = [];
            }

            $purchaseOrders = array_map(function ($row) {
                return [
                    'id' => (string) $row['id'],
                    'purchaseOrderId' => (string) $row['purchase_order_id'],
                    'partyName' => $row['party_name'] ?? 'Unknown',
                    'quantity' => (float) $row['quantity'],
                    'rate' => (float) $row['rate'],
                    'amount' => (float) $row['amount'],
                    'date' => $row['order_date'],
                    'status' => $row['status'] ?? '',
                ];
            }, $poRows);

            sendJson([
                'ok' => true,
                'data' => [
                    'sales' => $sales,
                    'purchaseOrders' => $purchaseOrders,
                    'salesCount' => count($sales),
                    'purchaseOrderCount' => count($purchaseOrders),
                ],
            ]);
            break;

        case 'create':
            $payload = $input['payload'] ?? [];
            $inventoryName = trim((string) ($payload['inventoryName'] ?? ''));
            $rateRaw = (string) ($payload['rate'] ?? '0');
            $pieceRaw = (string) ($payload['piece'] ?? '0');
            $rate = (float) $rateRaw;
            $piece = (float) $pieceRaw;
            $packing = resolveInventoryPacking($pdo, $payload);

            if ($inventoryName === '') {
                sendJson(['ok' => false, 'message' => 'Inventory name is required.'], 400);
            }
            if (!is_numeric($rateRaw) || $rate < 0) {
                sendJson(['ok' => false, 'message' => 'Rate must be a valid number 0 or greater.'], 400);
            }
            if (!is_numeric($pieceRaw) || $piece < 0) {
                sendJson(['ok' => false, 'message' => 'Piece must be a valid number 0 or greater.'], 400);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO inventory (inventory_name, packing, packing_id, rate, piece) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $inventoryName,
                $packing['name'],
                (int) $packing['id'],
                $rate,
                $piece
            ]);
            sendJson(['ok' => true, 'data' => ['id' => (string) $pdo->lastInsertId()]]);
            break;

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            $payload = $input['payload'] ?? [];
            $inventoryName = trim((string) ($payload['inventoryName'] ?? ''));
            $rateRaw = (string) ($payload['rate'] ?? '0');
            $pieceRaw = (string) ($payload['piece'] ?? '0');
            $rate = (float) $rateRaw;
            $piece = (float) $pieceRaw;
            $packing = resolveInventoryPacking($pdo, $payload);

            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid inventory id.'], 400);
            }
            if ($inventoryName === '') {
                sendJson(['ok' => false, 'message' => 'Inventory name is required.'], 400);
            }
            if (!is_numeric($rateRaw) || $rate < 0) {
                sendJson(['ok' => false, 'message' => 'Rate must be a valid number 0 or greater.'], 400);
            }
            if (!is_numeric($pieceRaw) || $piece < 0) {
                sendJson(['ok' => false, 'message' => 'Piece must be a valid number 0 or greater.'], 400);
            }

            $stmt = $pdo->prepare(
                'UPDATE inventory SET inventory_name = ?, packing = ?, packing_id = ?, rate = ?, piece = ? WHERE id = ?'
            );
            $stmt->execute([
                $inventoryName,
                $packing['name'],
                (int) $packing['id'],
                $rate,
                $piece,
                $id
            ]);
            if ($stmt->rowCount() === 0) {
                $existsStmt = $pdo->prepare('SELECT id FROM inventory WHERE id = ?');
                $existsStmt->execute([$id]);
                if (!$existsStmt->fetch()) {
                    sendJson(['ok' => false, 'message' => 'Inventory not found.'], 404);
                }
            }
            sendJson(['ok' => true]);
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid inventory id.'], 400);
            }
            $stmt = $pdo->prepare('DELETE FROM inventory WHERE id = ?');
            $stmt->execute([$id]);
            sendJson(['ok' => true]);
            break;

        default:
            sendJson(['ok' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    sendJson(['ok' => false, 'message' => $e->getMessage()], 500);
}
