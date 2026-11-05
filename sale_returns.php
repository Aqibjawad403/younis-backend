<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJson(['ok' => true]);
}

function ensureSaleReturnsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sale_returns (
          id           INT AUTO_INCREMENT PRIMARY KEY,
          sale_id      INT            NOT NULL,
          invoice_id   INT            NOT NULL,
          party_id     INT            NOT NULL,
          inventory_id INT            NOT NULL,
          weight       DECIMAL(12,2)  NOT NULL DEFAULT 0,
          price        DECIMAL(15,2)  NOT NULL DEFAULT 0,
          amount       DECIMAL(15,2)  NOT NULL DEFAULT 0,
          return_date  DATE           NOT NULL,
          details      TEXT           NULL,
          stock_id     INT            NULL,
          created_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
          updated_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_sr_sale (sale_id),
          INDEX idx_sr_invoice (invoice_id),
          INDEX idx_sr_party (party_id),
          INDEX idx_sr_date (return_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM sale_returns')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('stock_id', $cols, true)) {
            $pdo->exec('ALTER TABLE sale_returns ADD COLUMN stock_id INT NULL AFTER details');
        }
    } catch (Throwable $e) {
        // Column may already exist
    }
}

function packingKgFromRow(?array $row): float
{
    if (!$row) {
        return 1.0;
    }
    if (isset($row['packing_weight']) && $row['packing_weight'] !== null
        && (float) $row['packing_weight'] > 0) {
        return (float) $row['packing_weight'];
    }
    return 1.0;
}

function mapSaleReturnRow(array $row): array
{
    return [
        'id' => (string) $row['id'],
        'saleId' => (int) $row['sale_id'],
        'invoiceId' => (int) $row['invoice_id'],
        'partyId' => (int) $row['party_id'],
        'partyName' => $row['party_name'] ?? 'Unknown',
        'inventoryId' => (int) $row['inventory_id'],
        'inventoryName' => $row['inventory_name'] ?? 'Unknown',
        'packingId' => !empty($row['packing_id']) ? (string) $row['packing_id'] : null,
        'packing' => $row['packing_name'] ?? $row['packing'] ?? '',
        'packingWeight' => isset($row['packing_weight']) && $row['packing_weight'] !== null
            ? (float) $row['packing_weight']
            : null,
        'weight' => (float) $row['weight'],
        'price' => (float) $row['price'],
        'amount' => (float) $row['amount'],
        'date' => $row['return_date'],
        'details' => $row['details'] ?? '',
        'stockId' => !empty($row['stock_id']) ? (int) $row['stock_id'] : null,
        'invoiceRef' => 'INV-' . str_pad((string) $row['invoice_id'], 4, '0', STR_PAD_LEFT),
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at'] ?? $row['created_at']
    ];
}

function fetchSaleReturnById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT sr.*, p.name AS party_name, i.inventory_name,
                i.packing_id, COALESCE(pk.name, i.packing) AS packing_name, pk.weight AS packing_weight
         FROM sale_returns sr
         LEFT JOIN parties p ON sr.party_id = p.id
         LEFT JOIN inventory i ON sr.inventory_id = i.id
         LEFT JOIN packings pk ON pk.id = i.packing_id
         WHERE sr.id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? mapSaleReturnRow($row) : null;
}

/**
 * Build returnable product list for an invoice.
 * Original qty comes from sales.weight; returnable = original - SUM(sale_returns).
 */
function buildInvoiceReturnables(PDO $pdo, int $invoiceId): array
{
    $invStmt = $pdo->prepare(
        "SELECT inv.id, inv.sale_id, inv.bill_id, inv.party_id, inv.amount, inv.invoice_date,
                p.name AS party_name
         FROM invoices inv
         LEFT JOIN parties p ON p.id = inv.party_id
         WHERE inv.id = ?"
    );
    $invStmt->execute([$invoiceId]);
    $invoice = $invStmt->fetch();
    if (!$invoice) {
        throw new RuntimeException('Invoice not found.');
    }

    $received = getInvoiceReceivedAmount($pdo, $invoiceId);
    $billId = !empty($invoice['bill_id'])
        ? (int) $invoice['bill_id']
        : (int) $invoice['sale_id'];

    $saleStmt = $pdo->prepare(
        "SELECT s.id AS sale_id, s.inventory_id, s.weight AS original_weight, s.price,
                i.inventory_name, i.packing_id,
                COALESCE(pk.name, i.packing) AS packing_name, pk.weight AS packing_weight
         FROM sales s
         LEFT JOIN inventory i ON i.id = s.inventory_id
         LEFT JOIN packings pk ON pk.id = i.packing_id
         WHERE COALESCE(s.bill_id, s.id) = ?
         ORDER BY s.id ASC"
    );
    $saleStmt->execute([$billId]);
    $sales = $saleStmt->fetchAll();

    // Legacy fallback: invoice_items / single sale
    if (!$sales) {
        $saleId = (int) $invoice['sale_id'];
        $legacy = $pdo->prepare(
            "SELECT s.id AS sale_id, s.inventory_id, s.weight AS original_weight, s.price,
                    i.inventory_name, i.packing_id,
                    COALESCE(pk.name, i.packing) AS packing_name, pk.weight AS packing_weight
             FROM sales s
             LEFT JOIN inventory i ON i.id = s.inventory_id
             LEFT JOIN packings pk ON pk.id = i.packing_id
             WHERE s.id = ?"
        );
        $legacy->execute([$saleId]);
        $row = $legacy->fetch();
        $sales = $row ? [$row] : [];
    }

    $returnedStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(weight), 0), COALESCE(SUM(amount), 0) FROM sale_returns WHERE sale_id = ?'
    );
    $products = [];

    foreach ($sales as $sale) {
        $saleId = (int) $sale['sale_id'];
        $originalWeight = round((float) $sale['original_weight'], 2);
        $price = (float) $sale['price'];
        $packingKg = packingKgFromRow($sale);
        $originalAmount = round($packingKg * $originalWeight * $price, 2);

        $returnedStmt->execute([$saleId]);
        $retRow = $returnedStmt->fetch(PDO::FETCH_NUM);
        $returnedWeight = round((float) ($retRow[0] ?? 0), 2);
        $returnedAmount = round((float) ($retRow[1] ?? 0), 2);
        $returnableWeight = round(max(0, $originalWeight - $returnedWeight), 2);
        $remainingAmount = round(max(0, $originalAmount - $returnedAmount), 2);

        $products[] = [
            'saleId' => $saleId,
            'inventoryId' => (int) $sale['inventory_id'],
            'inventoryName' => $sale['inventory_name'] ?? 'Unknown',
            'packingId' => !empty($sale['packing_id']) ? (string) $sale['packing_id'] : null,
            'packing' => $sale['packing_name'] ?? '',
            'packingWeight' => isset($sale['packing_weight']) && $sale['packing_weight'] !== null
                ? (float) $sale['packing_weight']
                : null,
            'price' => $price,
            'originalWeight' => $originalWeight,
            'returnedWeight' => $returnedWeight,
            'returnableWeight' => $returnableWeight,
            'originalAmount' => $originalAmount,
            'returnedAmount' => $returnedAmount,
            'remainingAmount' => $remainingAmount,
        ];
    }

    return [
        'invoiceId' => (int) $invoice['id'],
        'billId' => $billId,
        'partyId' => (int) $invoice['party_id'],
        'partyName' => $invoice['party_name'] ?? 'Unknown',
        'date' => $invoice['invoice_date'],
        'amount' => round((float) $invoice['amount'], 2),
        'receivedAmount' => $received,
        'remainingAmount' => max(0, round((float) $invoice['amount'] - $received, 2)),
        'invoiceRef' => 'INV-' . str_pad((string) $invoice['id'], 4, '0', STR_PAD_LEFT),
        'products' => $products,
    ];
}

/**
 * Process one product return inside an open transaction.
 * Returns mapped sale_return row data array.
 */
function processSaleReturnLine(
    PDO $pdo,
    array $invoice,
    int $saleId,
    float $weight,
    string $returnDate,
    string $details
): array {
    $weight = round($weight, 2);
    if ($weight <= 0) {
        throw new RuntimeException('Return piece must be greater than 0.');
    }

    $invoiceId = (int) $invoice['id'];
    $partyId = (int) $invoice['party_id'];

    $saleStmt = $pdo->prepare(
        "SELECT s.id, s.inventory_id, s.weight, s.price,
                pk.weight AS packing_weight
         FROM sales s
         LEFT JOIN inventory i ON i.id = s.inventory_id
         LEFT JOIN packings pk ON pk.id = i.packing_id
         WHERE s.id = ?
         FOR UPDATE"
    );
    $saleStmt->execute([$saleId]);
    $sale = $saleStmt->fetch();
    if (!$sale) {
        throw new RuntimeException('Sale line not found for return.');
    }

    // Ensure sale belongs to this invoice's bill
    $billId = !empty($invoice['bill_id']) ? (int) $invoice['bill_id'] : (int) $invoice['sale_id'];
    $belongStmt = $pdo->prepare(
        'SELECT id FROM sales WHERE id = ? AND COALESCE(bill_id, id) = ?'
    );
    $belongStmt->execute([$saleId, $billId]);
    if (!$belongStmt->fetch()) {
        // Legacy: invoice.sale_id link
        if ((int) $invoice['sale_id'] !== $saleId) {
            throw new RuntimeException('Selected product is not part of this invoice.');
        }
    }

    $inventoryId = (int) $sale['inventory_id'];
    $originalWeight = round((float) $sale['weight'], 2);
    $price = (float) $sale['price'];
    $packingKg = packingKgFromRow($sale);

    $returnedStmt = $pdo->prepare('SELECT COALESCE(SUM(weight), 0) FROM sale_returns WHERE sale_id = ?');
    $returnedStmt->execute([$saleId]);
    $alreadyReturned = round((float) $returnedStmt->fetchColumn(), 2);
    $returnable = round($originalWeight - $alreadyReturned, 2);

    if ($weight > $returnable + 0.0001) {
        throw new RuntimeException(
            'Return piece cannot exceed remaining sale quantity (' . $returnable . ').'
        );
    }

    $amount = round($packingKg * $weight * $price, 2);

    // Re-read invoice amount under lock
    $invLock = $pdo->prepare('SELECT amount FROM invoices WHERE id = ? FOR UPDATE');
    $invLock->execute([$invoiceId]);
    $invoiceAmount = round((float) $invLock->fetchColumn(), 2);

    if ($amount > $invoiceAmount + 0.0001) {
        $amount = $invoiceAmount;
    }

    $received = getInvoiceReceivedAmount($pdo, $invoiceId);
    $newInvoiceAmount = round(max($received, $invoiceAmount - $amount), 2);
    $actualBillReduction = round($invoiceAmount - $newInvoiceAmount, 2);
    if ($actualBillReduction <= 0) {
        throw new RuntimeException(
            'Cannot record this return because the bill cannot go below the amount already received. Reduce the payment first.'
        );
    }
    // Line and bill total must drop by the same amount. Never reduce only the line.
    $recordAmount = $actualBillReduction;

    // 1) Restore inventory stock
    $pdo->prepare('UPDATE inventory SET piece = piece + ? WHERE id = ?')
        ->execute([$weight, $inventoryId]);

    // 2) Stock ledger inward entry
    $pdo->prepare(
        'INSERT INTO stock (inventory_id, price, piece, stock_date) VALUES (?, ?, ?, ?)'
    )->execute([$inventoryId, $price, $weight, $returnDate]);
    $stockId = (int) $pdo->lastInsertId();

    // 3) Reduce invoice bill amount → party ledger credit drops
    $pdo->prepare('UPDATE invoices SET amount = ? WHERE id = ?')
        ->execute([$newInvoiceAmount, $invoiceId]);
    recalculateInvoiceStatus($pdo, $invoiceId);

    // 4) Update invoice_items (remaining products / amounts on invoice)
    $itemStmt = $pdo->prepare(
        'SELECT id, weight, amount FROM invoice_items WHERE invoice_id = ? AND sale_id = ? FOR UPDATE'
    );
    $itemStmt->execute([$invoiceId, $saleId]);
    $item = $itemStmt->fetch();
    if ($item) {
        $newItemWeight = round(max(0, (float) $item['weight'] - $weight), 2);
        $newItemAmount = round(max(0, (float) $item['amount'] - $recordAmount), 2);
        if ($newItemWeight <= 0.0001) {
            // Fully returned product — remove from invoice lines
            $pdo->prepare('DELETE FROM invoice_items WHERE id = ?')->execute([(int) $item['id']]);
        } else {
            $pdo->prepare('UPDATE invoice_items SET weight = ?, amount = ? WHERE id = ?')
                ->execute([$newItemWeight, $newItemAmount, (int) $item['id']]);
        }
    }

    // 5) Sales return ledger record
    $ins = $pdo->prepare(
        'INSERT INTO sale_returns
         (sale_id, invoice_id, party_id, inventory_id, weight, price, amount, return_date, details, stock_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $saleId,
        $invoiceId,
        $partyId,
        $inventoryId,
        $weight,
        $price,
        $recordAmount,
        $returnDate,
        $details,
        $stockId > 0 ? $stockId : null,
    ]);

    return fetchSaleReturnById($pdo, (int) $pdo->lastInsertId());
}

try {
    $pdo = getDbConnection();
    ensureSaleReturnsTable($pdo);
    $input = readJsonInput();
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'list':
            $partyId = !empty($input['partyId']) ? (int) $input['partyId'] : null;
            $invoiceId = !empty($input['invoiceId']) ? (int) $input['invoiceId'] : null;
            $startDate = trim((string) ($input['startDate'] ?? ''));
            $endDate = trim((string) ($input['endDate'] ?? ''));

            $sql = "SELECT sr.*, p.name AS party_name, i.inventory_name,
                           i.packing_id, COALESCE(pk.name, i.packing) AS packing_name, pk.weight AS packing_weight
                    FROM sale_returns sr
                    LEFT JOIN parties p ON sr.party_id = p.id
                    LEFT JOIN inventory i ON sr.inventory_id = i.id
                    LEFT JOIN packings pk ON pk.id = i.packing_id
                    WHERE 1=1";
            $params = [];

            if ($partyId) {
                $sql .= ' AND sr.party_id = ?';
                $params[] = $partyId;
            }
            if ($invoiceId) {
                $sql .= ' AND sr.invoice_id = ?';
                $params[] = $invoiceId;
            }
            if ($startDate !== '') {
                $sql .= ' AND sr.return_date >= ?';
                $params[] = $startDate;
            }
            if ($endDate !== '') {
                $sql .= ' AND sr.return_date <= ?';
                $params[] = $endDate;
            }

            $sql .= ' ORDER BY sr.return_date DESC, sr.id DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            sendJson(['ok' => true, 'data' => array_map('mapSaleReturnRow', $rows)]);
            break;

        case 'get':
            $id = (int) ($input['id'] ?? 0);
            sendJson(['ok' => true, 'data' => fetchSaleReturnById($pdo, $id)]);
            break;

        case 'invoiceProducts':
            $invoiceId = (int) ($input['invoiceId'] ?? 0);
            if ($invoiceId <= 0) {
                sendJson(['ok' => false, 'message' => 'Invoice is required.'], 400);
            }
            sendJson(['ok' => true, 'data' => buildInvoiceReturnables($pdo, $invoiceId)]);
            break;

        case 'create':
            $payload = $input['payload'] ?? [];
            $invoiceId = (int) ($payload['invoiceId'] ?? 0);
            $returnDate = trim((string) ($payload['date'] ?? date('Y-m-d')));
            $details = trim((string) ($payload['details'] ?? ''));

            if ($invoiceId <= 0) {
                sendJson(['ok' => false, 'message' => 'Invoice is required.'], 400);
            }
            if ($returnDate === '') {
                $returnDate = date('Y-m-d');
            }
            if ($details === '') {
                $details = 'Sale return';
            }

            // Support multi-item: items[{saleId, weight}] OR legacy single saleId+weight
            $items = [];
            if (!empty($payload['items']) && is_array($payload['items'])) {
                foreach ($payload['items'] as $item) {
                    $sid = (int) ($item['saleId'] ?? 0);
                    $w = round((float) ($item['weight'] ?? 0), 2);
                    if ($sid > 0 && $w > 0) {
                        $items[] = ['saleId' => $sid, 'weight' => $w];
                    }
                }
            } else {
                $saleIdPayload = (int) ($payload['saleId'] ?? 0);
                $weight = round((float) ($payload['weight'] ?? 0), 2);
                if ($saleIdPayload > 0 && $weight > 0) {
                    $items[] = ['saleId' => $saleIdPayload, 'weight' => $weight];
                } elseif ($weight > 0) {
                    // Single-product invoice — resolve saleId later
                    $items[] = ['saleId' => 0, 'weight' => $weight];
                }
            }

            if (!$items) {
                sendJson(['ok' => false, 'message' => 'Add at least one product return quantity.'], 400);
            }

            $pdo->beginTransaction();
            try {
                $invStmt = $pdo->prepare(
                    "SELECT inv.id, inv.sale_id, inv.bill_id, inv.party_id, inv.inventory_id, inv.amount
                     FROM invoices inv
                     WHERE inv.id = ?
                     FOR UPDATE"
                );
                $invStmt->execute([$invoiceId]);
                $invoice = $invStmt->fetch();
                if (!$invoice) {
                    throw new RuntimeException('Invoice not found.');
                }

                // Resolve saleId=0 for single-product invoices
                $returnables = buildInvoiceReturnables($pdo, $invoiceId);
                $products = $returnables['products'];
                foreach ($items as &$itemRef) {
                    if ($itemRef['saleId'] <= 0) {
                        if (count($products) === 1) {
                            $itemRef['saleId'] = (int) $products[0]['saleId'];
                        } else {
                            throw new RuntimeException('Please select which product to return.');
                        }
                    }
                }
                unset($itemRef);

                $created = [];
                foreach ($items as $item) {
                    $created[] = processSaleReturnLine(
                        $pdo,
                        $invoice,
                        (int) $item['saleId'],
                        (float) $item['weight'],
                        $returnDate,
                        $details
                    );
                }

                $pdo->commit();

                sendJson([
                    'ok' => true,
                    'data' => count($created) === 1 ? $created[0] : $created,
                    'count' => count($created),
                ]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Return ID is required.'], 400);
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT * FROM sale_returns WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) {
                    throw new RuntimeException('Sale return not found.');
                }

                $weight = (float) $row['weight'];
                $amount = (float) $row['amount'];
                $price = (float) $row['price'];
                $inventoryId = (int) $row['inventory_id'];
                $invoiceId = (int) $row['invoice_id'];
                $saleId = (int) $row['sale_id'];
                $stockId = !empty($row['stock_id']) ? (int) $row['stock_id'] : 0;

                // Reverse stock restore
                $invCheck = $pdo->prepare('SELECT piece FROM inventory WHERE id = ? FOR UPDATE');
                $invCheck->execute([$inventoryId]);
                $invRow = $invCheck->fetch();
                if (!$invRow) {
                    throw new RuntimeException('Inventory not found.');
                }
                if ((float) $invRow['piece'] < $weight) {
                    throw new RuntimeException('Cannot delete return: inventory stock is insufficient to reverse.');
                }

                $pdo->prepare('UPDATE inventory SET piece = piece - ? WHERE id = ?')
                    ->execute([$weight, $inventoryId]);

                // Remove linked stock ledger row (or best-effort match)
                if ($stockId > 0) {
                    $pdo->prepare('DELETE FROM stock WHERE id = ?')->execute([$stockId]);
                } else {
                    $findStock = $pdo->prepare(
                        'SELECT id FROM stock
                         WHERE inventory_id = ? AND piece = ? AND price = ? AND stock_date = ?
                         ORDER BY id DESC LIMIT 1'
                    );
                    $findStock->execute([
                        $inventoryId,
                        $weight,
                        $price,
                        $row['return_date'],
                    ]);
                    $matchedStockId = (int) $findStock->fetchColumn();
                    if ($matchedStockId > 0) {
                        $pdo->prepare('DELETE FROM stock WHERE id = ?')->execute([$matchedStockId]);
                    }
                }

                // Restore invoice bill amount
                $pdo->prepare('UPDATE invoices SET amount = amount + ? WHERE id = ?')
                    ->execute([$amount, $invoiceId]);
                recalculateInvoiceStatus($pdo, $invoiceId);

                // Restore invoice_items line (re-create if fully returned earlier)
                $itemStmt = $pdo->prepare(
                    'SELECT id, weight, amount FROM invoice_items WHERE invoice_id = ? AND sale_id = ? FOR UPDATE'
                );
                $itemStmt->execute([$invoiceId, $saleId]);
                $item = $itemStmt->fetch();
                if ($item) {
                    $pdo->prepare('UPDATE invoice_items SET weight = weight + ?, amount = amount + ? WHERE id = ?')
                        ->execute([$weight, $amount, (int) $item['id']]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO invoice_items (invoice_id, sale_id, inventory_id, weight, price, amount)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    )->execute([$invoiceId, $saleId, $inventoryId, $weight, $price, $amount]);
                }

                $pdo->prepare('DELETE FROM sale_returns WHERE id = ?')->execute([$id]);

                $pdo->commit();
                sendJson(['ok' => true]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            break;

        default:
            sendJson(['ok' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    sendJson(['ok' => false, 'message' => $e->getMessage()], 500);
}
