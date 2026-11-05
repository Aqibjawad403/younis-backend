<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJson(['ok' => true]);
}

function saleLineSelectSql(): string
{
    return 'SELECT s.id, COALESCE(s.bill_id, s.id) AS bill_id, s.party_id, p.name AS party_name,
                   s.inventory_id, i.inventory_name,
                   i.packing_id, COALESCE(pk.name, i.packing) AS packing_name, pk.weight AS packing_weight,
                   s.weight, s.price, s.payment_type, s.satisfaction_rating,
                   COALESCE(s.sale_date, DATE(s.created_at)) AS sale_date,
                   s.created_at, s.updated_at
            FROM sales s
            LEFT JOIN parties p ON s.party_id = p.id
            LEFT JOIN inventory i ON s.inventory_id = i.id
            LEFT JOIN packings pk ON pk.id = i.packing_id';
}

function mapSaleLineRow(array $row): array
{
    $packingKg = isset($row['packing_weight']) && $row['packing_weight'] !== null
        && (float) $row['packing_weight'] > 0
        ? (float) $row['packing_weight']
        : 1.0;
    $weight = (float) $row['weight'];
    $price = (float) $row['price'];

    return [
        'id' => (string) $row['id'],
        'billId' => (string) ($row['bill_id'] ?? $row['id']),
        'partyId' => (int) $row['party_id'],
        'partyName' => $row['party_name'] ?? 'Unknown',
        'inventoryId' => (int) $row['inventory_id'],
        'inventoryName' => $row['inventory_name'] ?? 'Unknown',
        'packingId' => !empty($row['packing_id']) ? (string) $row['packing_id'] : null,
        'packing' => $row['packing_name'] ?? '',
        'packingWeight' => isset($row['packing_weight']) && $row['packing_weight'] !== null
            ? (float) $row['packing_weight']
            : null,
        'weight' => $weight,
        'price' => $price,
        'lineTotal' => round($packingKg * $weight * $price, 2),
        'paymentType' => $row['payment_type'],
        'satisfactionRating' => $row['satisfaction_rating'] !== null ? (float) $row['satisfaction_rating'] : null,
        'date' => $row['sale_date'],
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at']
    ];
}

function buildBillFromLines(array $lines): ?array
{
    if (!$lines) {
        return null;
    }

    $first = $lines[0];
    $names = [];
    $totalWeight = 0;
    $totalAmount = 0;
    foreach ($lines as $line) {
        $names[] = $line['inventoryName'];
        $totalWeight += (float) $line['weight'];
        $totalAmount += (float) $line['lineTotal'];
    }
    $uniqueNames = array_values(array_unique($names));
    $inventoryLabel = count($uniqueNames) <= 2
        ? implode(' + ', $uniqueNames)
        : $uniqueNames[0] . ' + ' . (count($uniqueNames) - 1) . ' more';

    return [
        'id' => (string) $first['billId'],
        'billId' => (string) $first['billId'],
        'partyId' => $first['partyId'],
        'partyName' => $first['partyName'],
        'inventoryId' => $first['inventoryId'],
        'inventoryName' => $inventoryLabel,
        'packing' => count($lines) === 1 ? $first['packing'] : (count($lines) . ' products'),
        'packingWeight' => count($lines) === 1 ? $first['packingWeight'] : null,
        'weight' => round($totalWeight, 2),
        'price' => count($lines) === 1 ? $first['price'] : round($totalAmount, 2),
        'paymentType' => $first['paymentType'],
        'satisfactionRating' => $first['satisfactionRating'],
        'date' => $first['date'],
        'createdAt' => $first['createdAt'],
        'updatedAt' => $first['updatedAt'],
        'itemCount' => count($lines),
        'totalAmount' => round($totalAmount, 2),
        'items' => $lines
    ];
}

function fetchBillById(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(saleLineSelectSql() . ' WHERE s.id = ? OR COALESCE(s.bill_id, s.id) = ? ORDER BY s.id ASC');
    $stmt->execute([$id, $id]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return null;
    }

    $billId = (int) ($rows[0]['bill_id'] ?? $rows[0]['id']);
    $billStmt = $pdo->prepare(saleLineSelectSql() . ' WHERE COALESCE(s.bill_id, s.id) = ? ORDER BY s.id ASC');
    $billStmt->execute([$billId]);
    $billRows = $billStmt->fetchAll();
    $lines = array_map('mapSaleLineRow', $billRows);
    $bill = buildBillFromLines($lines);
    if (!$bill) {
        return null;
    }

    $invStmt = $pdo->prepare('SELECT id FROM invoices WHERE bill_id = ? OR sale_id = ? ORDER BY id ASC LIMIT 1');
    $invStmt->execute([$billId, $billId]);
    $invoiceId = $invStmt->fetchColumn();
    $bill['invoiceId'] = $invoiceId ? (string) $invoiceId : null;

    $amountPaid = 0.0;
    $bankId = null;
    if ($invoiceId) {
        $paidStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS total_paid FROM payment_receipts WHERE invoice_id = ?'
        );
        $paidStmt->execute([(int) $invoiceId]);
        $amountPaid = round((float) $paidStmt->fetchColumn(), 2);

        $bankStmt = $pdo->prepare(
            'SELECT bank_id FROM payment_receipts
             WHERE invoice_id = ? AND bank_id IS NOT NULL
             ORDER BY id ASC LIMIT 1'
        );
        $bankStmt->execute([(int) $invoiceId]);
        $bankRow = $bankStmt->fetchColumn();
        if ($bankRow) {
            $bankId = (string) $bankRow;
        }
    }
    $bill['amountPaid'] = $amountPaid;
    $bill['bankId'] = $bankId;

    return $bill;
}

function packingKgFromInventoryRow(?array $inventory): float
{
    if (!$inventory) {
        return 1.0;
    }
    if (isset($inventory['packing_weight']) && $inventory['packing_weight'] !== null
        && (float) $inventory['packing_weight'] > 0) {
        return (float) $inventory['packing_weight'];
    }
    return 1.0;
}

function recalculateBillInvoice(PDO $pdo, int $billId): void
{
    $linesStmt = $pdo->prepare(
        'SELECT s.id, s.inventory_id, s.weight, s.price, pk.weight AS packing_weight
         FROM sales s
         LEFT JOIN inventory i ON i.id = s.inventory_id
         LEFT JOIN packings pk ON pk.id = i.packing_id
         WHERE COALESCE(s.bill_id, s.id) = ?
         ORDER BY s.id ASC'
    );
    $linesStmt->execute([$billId]);
    $lines = $linesStmt->fetchAll();
    if (!$lines) {
        return;
    }

    $total = 0;
    foreach ($lines as $line) {
        $kg = packingKgFromInventoryRow($line);
        $total += round($kg * (float) $line['weight'] * (float) $line['price'], 2);
    }
    $total = round($total, 2);
    $first = $lines[0];

    $invStmt = $pdo->prepare('SELECT id FROM invoices WHERE bill_id = ? OR sale_id = ? ORDER BY id ASC LIMIT 1');
    $invStmt->execute([$billId, $billId]);
    $invoiceId = (int) $invStmt->fetchColumn();
    if ($invoiceId <= 0) {
        return;
    }

    $updateInv = $pdo->prepare(
        'UPDATE invoices SET inventory_id = ?, amount = ?, bill_id = ?, sale_id = ? WHERE id = ?'
    );
    $updateInv->execute([
        (int) $first['inventory_id'],
        $total,
        $billId,
        (int) $first['id'],
        $invoiceId
    ]);

    $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);
    $itemIns = $pdo->prepare(
        'INSERT INTO invoice_items (invoice_id, sale_id, inventory_id, weight, price, amount)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($lines as $line) {
        $kg = packingKgFromInventoryRow($line);
        $lineTotal = round($kg * (float) $line['weight'] * (float) $line['price'], 2);
        $itemIns->execute([
            $invoiceId,
            (int) $line['id'],
            (int) $line['inventory_id'],
            (float) $line['weight'],
            (float) $line['price'],
            $lineTotal
        ]);
    }

    // Re-apply sale returns so invoice amount/items stay correct after rebuild
    $retStmt = $pdo->prepare(
        'SELECT sale_id, inventory_id, weight, price, amount
         FROM sale_returns WHERE invoice_id = ? ORDER BY id ASC'
    );
    $retStmt->execute([$invoiceId]);
    $returns = $retStmt->fetchAll();
    foreach ($returns as $ret) {
        $saleId = (int) $ret['sale_id'];
        $w = (float) $ret['weight'];
        $amt = (float) $ret['amount'];
        $itemStmt = $pdo->prepare(
            'SELECT id, weight, amount FROM invoice_items WHERE invoice_id = ? AND sale_id = ?'
        );
        $itemStmt->execute([$invoiceId, $saleId]);
        $item = $itemStmt->fetch();
        if ($item) {
            $newW = round(max(0, (float) $item['weight'] - $w), 2);
            $newA = round(max(0, (float) $item['amount'] - $amt), 2);
            if ($newW <= 0.0001) {
                $pdo->prepare('DELETE FROM invoice_items WHERE id = ?')->execute([(int) $item['id']]);
            } else {
                $pdo->prepare('UPDATE invoice_items SET weight = ?, amount = ? WHERE id = ?')
                    ->execute([$newW, $newA, (int) $item['id']]);
            }
        }
    }

    // Total is the amount column, including after returns are taken off the lines.
    $sumStmt = $pdo->prepare(
        'SELECT COALESCE(ROUND(SUM(amount), 2), 0) FROM invoice_items WHERE invoice_id = ?'
    );
    $sumStmt->execute([$invoiceId]);
    $itemSum = round((float) $sumStmt->fetchColumn(), 2);
    $pdo->prepare('UPDATE invoices SET amount = ? WHERE id = ?')->execute([$itemSum, $invoiceId]);

    recalculateInvoiceStatus($pdo, $invoiceId);
}

try {
    $pdo = getDbConnection();
    $input = readJsonInput();
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'list':
            $rows = $pdo->query(
                saleLineSelectSql() . ' ORDER BY COALESCE(s.bill_id, s.id) DESC, s.id ASC'
            )->fetchAll();

            $grouped = [];
            foreach ($rows as $row) {
                $line = mapSaleLineRow($row);
                $billId = $line['billId'];
                if (!isset($grouped[$billId])) {
                    $grouped[$billId] = [];
                }
                $grouped[$billId][] = $line;
            }

            $data = [];
            foreach ($grouped as $lines) {
                $bill = buildBillFromLines($lines);
                if ($bill) {
                    $data[] = $bill;
                }
            }

            sendJson(['ok' => true, 'data' => $data]);
            break;

        case 'get':
            $id = (int) ($input['id'] ?? 0);
            $bill = fetchBillById($pdo, $id);
            sendJson(['ok' => true, 'data' => $bill]);
            break;

        case 'getSatisfactionOverview':
            $rows = $pdo->query(
                'SELECT satisfaction_rating FROM sales
                 WHERE id = COALESCE(bill_id, id)
                 ORDER BY created_at DESC, id DESC LIMIT 10'
            )->fetchAll();
            $ratedRows = array_values(array_filter($rows, function ($row) {
                return $row['satisfaction_rating'] !== null;
            }));
            $ratedCount = count($ratedRows);
            $averageRating = 0;
            if ($ratedCount > 0) {
                $total = array_sum(array_map(function ($row) {
                    return (float) $row['satisfaction_rating'];
                }, $ratedRows));
                $averageRating = round($total / $ratedCount, 1);
            }

            sendJson([
                'ok' => true,
                'data' => [
                    'averageRating' => $averageRating,
                    'serviceCount' => count($rows),
                    'ratedCount' => $ratedCount,
                    'basedOn' => count($rows)
                ]
            ]);
            break;

        case 'create':
            $payload = $input['payload'] ?? [];
            $partyId = (int) ($payload['partyId'] ?? 0);
            $paymentType = $payload['paymentType'] ?? 'Cash';
            $bankId = !empty($payload['bankId']) ? (int) $payload['bankId'] : null;
            $amountPaid = round((float) ($payload['amountPaid'] ?? 0), 2);
            $satisfactionRating = isset($payload['satisfactionRating']) && $payload['satisfactionRating'] !== ''
                ? (float) $payload['satisfactionRating']
                : null;
            $saleDateRaw = trim((string) ($payload['date'] ?? $payload['saleDate'] ?? ''));
            $saleDate = $saleDateRaw !== '' ? $saleDateRaw : date('Y-m-d');
            $lineItems = $payload['items'] ?? null;

            if ($amountPaid < 0) {
                sendJson(['ok' => false, 'message' => 'Paid amount cannot be negative.'], 400);
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
                sendJson(['ok' => false, 'message' => 'Invalid bill date.'], 400);
            }
            $saleDateCheck = DateTime::createFromFormat('Y-m-d', $saleDate);
            if (!$saleDateCheck || $saleDateCheck->format('Y-m-d') !== $saleDate) {
                sendJson(['ok' => false, 'message' => 'Invalid bill date.'], 400);
            }
            if (!in_array($paymentType, ['Cash', 'Cheque', 'Online'], true)) {
                sendJson(['ok' => false, 'message' => 'Invalid payment type.'], 400);
            }
            if ($amountPaid > 0 && $paymentType !== 'Cash' && (!$bankId || $bankId <= 0)) {
                sendJson(['ok' => false, 'message' => 'Bank is required for Cheque and Online payments.'], 400);
            }
            if ($paymentType === 'Cash') {
                $bankId = null;
            }

            if ($satisfactionRating !== null && ($satisfactionRating < 1 || $satisfactionRating > 5)) {
                sendJson(['ok' => false, 'message' => 'Satisfaction rating must be between 1 and 5.'], 400);
            }

            if (!is_array($lineItems) || count($lineItems) === 0) {
                $lineItems = [[
                    'inventoryId' => (int) ($payload['inventoryId'] ?? 0),
                    'weight' => (float) ($payload['weight'] ?? 0),
                    'price' => (float) ($payload['price'] ?? 0)
                ]];
            }

            if ($partyId <= 0) {
                sendJson(['ok' => false, 'message' => 'Party is required.'], 400);
            }

            $pdo->beginTransaction();
            try {
                $partyStmt = $pdo->prepare('SELECT id, name, phone FROM parties WHERE id = ?');
                $partyStmt->execute([$partyId]);
                $party = $partyStmt->fetch();
                if (!$party) {
                    throw new RuntimeException('Selected party not found.');
                }

                $saleIds = [];
                $billItems = [];
                $preparedLines = [];
                $totalBillAmount = 0;
                $firstInventoryId = null;

                foreach ($lineItems as $item) {
                    $inventoryId = (int) ($item['inventoryId'] ?? 0);
                    $weight = (float) ($item['weight'] ?? 0);
                    $price = (float) ($item['price'] ?? 0);

                    if ($inventoryId <= 0) {
                        throw new RuntimeException('Inventory item is required for each product.');
                    }
                    if (!is_numeric((string) ($item['weight'] ?? '')) || $weight <= 0) {
                        throw new RuntimeException('Sale piece must be a valid number greater than 0 for each product.');
                    }
                    if (!is_numeric((string) ($item['price'] ?? '')) || $price < 0) {
                        throw new RuntimeException('Sale price must be a valid number 0 or greater for each product.');
                    }

                    $inventoryStmt = $pdo->prepare(
                        'SELECT i.inventory_name, i.piece, i.packing_id,
                                COALESCE(pk.name, i.packing) AS packing_name, pk.weight AS packing_weight
                         FROM inventory i
                         LEFT JOIN packings pk ON pk.id = i.packing_id
                         WHERE i.id = ? FOR UPDATE'
                    );
                    $inventoryStmt->execute([$inventoryId]);
                    $inventory = $inventoryStmt->fetch();
                    if (!$inventory) {
                        throw new RuntimeException('Selected inventory not found.');
                    }
                    if ($weight > (float) $inventory['piece']) {
                        throw new RuntimeException('Sale piece cannot be greater than available stock.');
                    }

                    $packingKg = packingKgFromInventoryRow($inventory);
                    $lineTotal = round($packingKg * $weight * $price, 2);
                    $totalBillAmount += $lineTotal;
                    if ($firstInventoryId === null) {
                        $firstInventoryId = $inventoryId;
                    }

                    $stmt = $pdo->prepare(
                        'INSERT INTO sales (party_id, inventory_id, weight, price, payment_type, satisfaction_rating, sale_date)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$partyId, $inventoryId, $weight, $price, $paymentType, $satisfactionRating, $saleDate]);
                    $saleId = (int) $pdo->lastInsertId();
                    $saleIds[] = $saleId;

                    $preparedLines[] = [
                        'saleId' => $saleId,
                        'inventoryId' => $inventoryId,
                        'weight' => $weight,
                        'price' => $price,
                        'lineTotal' => $lineTotal,
                        'inventory' => $inventory
                    ];

                    $billItems[] = [
                        'inventoryName' => $inventory['inventory_name'] ?? 'Unknown',
                        'packing' => $inventory['packing_name'] ?? '',
                        'packingWeight' => isset($inventory['packing_weight']) && $inventory['packing_weight'] !== null
                            ? (float) $inventory['packing_weight']
                            : null,
                        'weight' => $weight,
                        'price' => $price,
                        'lineTotal' => $lineTotal
                    ];

                    $updateInventory = $pdo->prepare('UPDATE inventory SET piece = piece - ? WHERE id = ?');
                    $updateInventory->execute([$weight, $inventoryId]);
                }

                $totalBillAmount = round($totalBillAmount, 2);
                if ($amountPaid > $totalBillAmount) {
                    throw new RuntimeException('Paid amount cannot exceed total bill amount.');
                }
                if (!$saleIds) {
                    throw new RuntimeException('Please add at least one product to the bill.');
                }

                $billId = (int) $saleIds[0];
                $setBill = $pdo->prepare('UPDATE sales SET bill_id = ? WHERE id = ?');
                foreach ($saleIds as $saleId) {
                    $setBill->execute([$billId, $saleId]);
                }

                // One invoice for the whole bill
                $invoiceStmt = $pdo->prepare(
                    'INSERT INTO invoices (bill_id, sale_id, party_id, inventory_id, amount, payment_type, payment_status, invoice_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $invoiceStmt->execute([
                    $billId,
                    $billId,
                    $partyId,
                    $firstInventoryId,
                    $totalBillAmount,
                    $paymentType,
                    'Pending',
                    $saleDate
                ]);
                $invoiceId = (int) $pdo->lastInsertId();

                $itemIns = $pdo->prepare(
                    'INSERT INTO invoice_items (invoice_id, sale_id, inventory_id, weight, price, amount)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($preparedLines as $line) {
                    $itemIns->execute([
                        $invoiceId,
                        $line['saleId'],
                        $line['inventoryId'],
                        $line['weight'],
                        $line['price'],
                        $line['lineTotal']
                    ]);
                }

                if ($amountPaid > 0) {
                    $insertReceipt = $pdo->prepare(
                        'INSERT INTO payment_receipts (party_id, invoice_id, bank_id, amount, payment_type, receipt_date, details)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $insertReceipt->execute([
                        $partyId,
                        $invoiceId,
                        $bankId,
                        $amountPaid,
                        $paymentType,
                        $saleDate,
                        'Payment on bill creation'
                    ]);
                    recalculateInvoiceStatus($pdo, $invoiceId);
                }

                $pdo->commit();
            } catch (Throwable $txError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txError;
            }

            sendJson([
                'ok' => true,
                'data' => [
                    'id' => (string) ($saleIds[0] ?? ''),
                    'billId' => (string) ($saleIds[0] ?? ''),
                    'invoiceId' => (string) $invoiceId,
                    'saleIds' => $saleIds,
                    'itemCount' => count($saleIds),
                    'bill' => [
                        'billId' => (string) ($saleIds[0] ?? ''),
                        'saleIds' => $saleIds,
                        'invoiceId' => (string) $invoiceId,
                        'partyName' => $party['name'] ?? 'Unknown',
                        'partyPhone' => $party['phone'] ?? '',
                        'date' => $saleDate,
                        'paymentType' => $paymentType,
                        'paymentStatus' => ($amountPaid >= $totalBillAmount && $totalBillAmount > 0)
                            ? 'Paid'
                            : ($amountPaid > 0 ? 'Partial' : 'Pending'),
                        'items' => $billItems,
                        'totalAmount' => $totalBillAmount,
                        'amountPaid' => $amountPaid,
                        'amountRemaining' => max(0, round($totalBillAmount - $amountPaid, 2))
                    ]
                ]
            ]);
            break;

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            $payload = $input['payload'] ?? [];
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid sale id.'], 400);
            }

            $lineItems = $payload['items'] ?? null;
            $isFullBillUpdate = is_array($lineItems);

            if ($isFullBillUpdate) {
                $partyId = (int) ($payload['partyId'] ?? 0);
                $paymentType = $payload['paymentType'] ?? 'Cash';
                $bankId = !empty($payload['bankId']) ? (int) $payload['bankId'] : null;
                $amountPaid = round((float) ($payload['amountPaid'] ?? 0), 2);
                $satisfactionRating = array_key_exists('satisfactionRating', $payload)
                    ? (($payload['satisfactionRating'] === '' || $payload['satisfactionRating'] === null)
                        ? null
                        : (float) $payload['satisfactionRating'])
                    : null;
                $saleDateRaw = trim((string) ($payload['date'] ?? $payload['saleDate'] ?? ''));
                $saleDate = $saleDateRaw !== '' ? $saleDateRaw : date('Y-m-d');

                if ($partyId <= 0) {
                    sendJson(['ok' => false, 'message' => 'Party is required.'], 400);
                }
                if ($amountPaid < 0) {
                    sendJson(['ok' => false, 'message' => 'Paid amount cannot be negative.'], 400);
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
                    sendJson(['ok' => false, 'message' => 'Invalid bill date.'], 400);
                }
                $saleDateCheck = DateTime::createFromFormat('Y-m-d', $saleDate);
                if (!$saleDateCheck || $saleDateCheck->format('Y-m-d') !== $saleDate) {
                    sendJson(['ok' => false, 'message' => 'Invalid bill date.'], 400);
                }
                if (!in_array($paymentType, ['Cash', 'Cheque', 'Online'], true)) {
                    sendJson(['ok' => false, 'message' => 'Invalid payment type.'], 400);
                }
                if ($amountPaid > 0 && $paymentType !== 'Cash' && (!$bankId || $bankId <= 0)) {
                    sendJson(['ok' => false, 'message' => 'Bank is required for Cheque and Online payments.'], 400);
                }
                if ($paymentType === 'Cash') {
                    $bankId = null;
                }
                if ($satisfactionRating !== null && ($satisfactionRating < 1 || $satisfactionRating > 5)) {
                    sendJson(['ok' => false, 'message' => 'Satisfaction rating must be between 1 and 5.'], 400);
                }
                if (count($lineItems) === 0) {
                    sendJson(['ok' => false, 'message' => 'Please add at least one product to the bill.'], 400);
                }

                $pdo->beginTransaction();
                try {
                    $partyStmt = $pdo->prepare('SELECT id, name, phone FROM parties WHERE id = ?');
                    $partyStmt->execute([$partyId]);
                    $party = $partyStmt->fetch();
                    if (!$party) {
                        throw new RuntimeException('Selected party not found.');
                    }

                    $saleStmt = $pdo->prepare(
                        'SELECT id, inventory_id, weight, COALESCE(bill_id, id) AS bill_id
                         FROM sales WHERE id = ? OR COALESCE(bill_id, id) = ? FOR UPDATE'
                    );
                    $saleStmt->execute([$id, $id]);
                    $existingRows = $saleStmt->fetchAll();
                    if (!$existingRows) {
                        throw new RuntimeException('Sale not found.');
                    }
                    $billId = (int) $existingRows[0]['bill_id'];

                    $allStmt = $pdo->prepare(
                        'SELECT id, inventory_id, weight FROM sales WHERE COALESCE(bill_id, id) = ? FOR UPDATE'
                    );
                    $allStmt->execute([$billId]);
                    $oldSales = $allStmt->fetchAll();

                    $saleIdsForCheck = array_map(fn($s) => (int) $s['id'], $oldSales);
                    if ($saleIdsForCheck) {
                        $ph = implode(',', array_fill(0, count($saleIdsForCheck), '?'));
                        $retCheck = $pdo->prepare(
                            "SELECT COUNT(*) FROM sale_returns WHERE sale_id IN ($ph)"
                        );
                        $retCheck->execute($saleIdsForCheck);
                        if ((int) $retCheck->fetchColumn() > 0) {
                            throw new RuntimeException(
                                'Cannot edit this bill because sale returns exist. Delete the returns first, then edit the bill.'
                            );
                        }
                    }

                    $restoreInventory = $pdo->prepare('UPDATE inventory SET piece = piece + ? WHERE id = ?');
                    foreach ($oldSales as $oldSale) {
                        $restoreInventory->execute([(float) $oldSale['weight'], (int) $oldSale['inventory_id']]);
                    }

                    $invFind = $pdo->prepare(
                        'SELECT id FROM invoices WHERE bill_id = ? OR sale_id = ? ORDER BY id ASC LIMIT 1'
                    );
                    $invFind->execute([$billId, $billId]);
                    $existingInvoiceId = (int) $invFind->fetchColumn();
                    if ($existingInvoiceId > 0) {
                        $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$existingInvoiceId]);
                    }

                    $preparedLines = [];
                    $totalBillAmount = 0;
                    $firstInventoryId = null;

                    foreach ($lineItems as $item) {
                        $inventoryId = (int) ($item['inventoryId'] ?? 0);
                        $weight = (float) ($item['weight'] ?? 0);
                        $price = (float) ($item['price'] ?? 0);

                        if ($inventoryId <= 0) {
                            throw new RuntimeException('Inventory item is required for each product.');
                        }
                        if (!is_numeric((string) ($item['weight'] ?? '')) || $weight <= 0) {
                            throw new RuntimeException('Sale piece must be a valid number greater than 0 for each product.');
                        }
                        if (!is_numeric((string) ($item['price'] ?? '')) || $price < 0) {
                            throw new RuntimeException('Sale price must be a valid number 0 or greater for each product.');
                        }

                        $inventoryStmt = $pdo->prepare(
                            'SELECT i.inventory_name, i.piece, i.packing_id,
                                    COALESCE(pk.name, i.packing) AS packing_name, pk.weight AS packing_weight
                             FROM inventory i
                             LEFT JOIN packings pk ON pk.id = i.packing_id
                             WHERE i.id = ? FOR UPDATE'
                        );
                        $inventoryStmt->execute([$inventoryId]);
                        $inventory = $inventoryStmt->fetch();
                        if (!$inventory) {
                            throw new RuntimeException('Selected inventory not found.');
                        }
                        if ($weight > (float) $inventory['piece']) {
                            throw new RuntimeException(
                                'Sale piece cannot be greater than available stock for "' .
                                ($inventory['inventory_name'] ?? 'item') . '".'
                            );
                        }

                        $packingKg = packingKgFromInventoryRow($inventory);
                        $lineTotal = round($packingKg * $weight * $price, 2);
                        $totalBillAmount += $lineTotal;
                        if ($firstInventoryId === null) {
                            $firstInventoryId = $inventoryId;
                        }

                        $preparedLines[] = [
                            'inventoryId' => $inventoryId,
                            'weight' => $weight,
                            'price' => $price,
                            'lineTotal' => $lineTotal,
                            'inventory' => $inventory,
                        ];
                    }

                    $totalBillAmount = round($totalBillAmount, 2);
                    if ($amountPaid > $totalBillAmount) {
                        throw new RuntimeException('Paid amount cannot exceed total bill amount.');
                    }

                    // Keep anchor sale row (id = billId) so invoices.sale_id FK does not cascade-delete the invoice.
                    $pdo->prepare(
                        'DELETE FROM sales WHERE COALESCE(bill_id, id) = ? AND id <> ?'
                    )->execute([$billId, $billId]);

                    $saleIds = [];
                    $consumeInventory = $pdo->prepare('UPDATE inventory SET piece = piece - ? WHERE id = ?');
                    foreach ($preparedLines as $index => $line) {
                        if ($index === 0) {
                            $anchorCheck = $pdo->prepare('SELECT id FROM sales WHERE id = ? FOR UPDATE');
                            $anchorCheck->execute([$billId]);
                            if ($anchorCheck->fetch()) {
                                $stmt = $pdo->prepare(
                                    'UPDATE sales
                                     SET party_id = ?, inventory_id = ?, weight = ?, price = ?, payment_type = ?,
                                         satisfaction_rating = ?, sale_date = ?, bill_id = ?
                                     WHERE id = ?'
                                );
                                $stmt->execute([
                                    $partyId,
                                    $line['inventoryId'],
                                    $line['weight'],
                                    $line['price'],
                                    $paymentType,
                                    $satisfactionRating,
                                    $saleDate,
                                    $billId,
                                    $billId,
                                ]);
                            } else {
                                $stmt = $pdo->prepare(
                                    'INSERT INTO sales (id, party_id, inventory_id, weight, price, payment_type, satisfaction_rating, sale_date, bill_id)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                                );
                                $stmt->execute([
                                    $billId,
                                    $partyId,
                                    $line['inventoryId'],
                                    $line['weight'],
                                    $line['price'],
                                    $paymentType,
                                    $satisfactionRating,
                                    $saleDate,
                                    $billId,
                                ]);
                            }
                            $saleId = $billId;
                        } else {
                            $stmt = $pdo->prepare(
                                'INSERT INTO sales (party_id, inventory_id, weight, price, payment_type, satisfaction_rating, sale_date, bill_id)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                            );
                            $stmt->execute([
                                $partyId,
                                $line['inventoryId'],
                                $line['weight'],
                                $line['price'],
                                $paymentType,
                                $satisfactionRating,
                                $saleDate,
                                $billId,
                            ]);
                            $saleId = (int) $pdo->lastInsertId();
                        }
                        $saleIds[] = $saleId;
                        $consumeInventory->execute([$line['weight'], $line['inventoryId']]);
                        $preparedLines[$index]['saleId'] = $saleId;
                    }

                    $invStmt = $pdo->prepare(
                        'SELECT id FROM invoices WHERE bill_id = ? OR sale_id = ? ORDER BY id ASC LIMIT 1'
                    );
                    $invStmt->execute([$billId, $billId]);
                    $invoiceId = (int) $invStmt->fetchColumn();

                    if ($invoiceId > 0) {
                        $updateInv = $pdo->prepare(
                            'UPDATE invoices
                             SET party_id = ?, inventory_id = ?, amount = ?, payment_type = ?, invoice_date = ?, bill_id = ?, sale_id = ?
                             WHERE id = ?'
                        );
                        $updateInv->execute([
                            $partyId,
                            $firstInventoryId,
                            $totalBillAmount,
                            $paymentType,
                            $saleDate,
                            $billId,
                            $billId,
                            $invoiceId,
                        ]);
                    } else {
                        $invoiceStmt = $pdo->prepare(
                            'INSERT INTO invoices (bill_id, sale_id, party_id, inventory_id, amount, payment_type, payment_status, invoice_date)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $invoiceStmt->execute([
                            $billId,
                            $billId,
                            $partyId,
                            $firstInventoryId,
                            $totalBillAmount,
                            $paymentType,
                            'Pending',
                            $saleDate,
                        ]);
                        $invoiceId = (int) $pdo->lastInsertId();
                    }

                    $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);
                    $itemIns = $pdo->prepare(
                        'INSERT INTO invoice_items (invoice_id, sale_id, inventory_id, weight, price, amount)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    foreach ($preparedLines as $line) {
                        $itemIns->execute([
                            $invoiceId,
                            $line['saleId'],
                            $line['inventoryId'],
                            $line['weight'],
                            $line['price'],
                            $line['lineTotal'],
                        ]);
                    }

                    // Keep installment receipts; only sync the original "on create" payment.
                    $otherPaidStmt = $pdo->prepare(
                        "SELECT COALESCE(SUM(amount), 0) FROM payment_receipts
                         WHERE invoice_id = ? AND (details IS NULL OR details <> 'Payment on bill creation')"
                    );
                    $otherPaidStmt->execute([$invoiceId]);
                    $otherPaid = round((float) $otherPaidStmt->fetchColumn(), 2);
                    if ($amountPaid < $otherPaid) {
                        throw new RuntimeException(
                            'Paid amount cannot be less than later installment payments (' .
                            number_format($otherPaid, 2) . ').'
                        );
                    }
                    $creationPaid = round($amountPaid - $otherPaid, 2);

                    $pdo->prepare(
                        "DELETE FROM payment_receipts
                         WHERE invoice_id = ? AND details = 'Payment on bill creation'"
                    )->execute([$invoiceId]);

                    if ($creationPaid > 0) {
                        $insertReceipt = $pdo->prepare(
                            'INSERT INTO payment_receipts (party_id, invoice_id, bank_id, amount, payment_type, receipt_date, details)
                             VALUES (?, ?, ?, ?, ?, ?, ?)'
                        );
                        $insertReceipt->execute([
                            $partyId,
                            $invoiceId,
                            $bankId,
                            $creationPaid,
                            $paymentType,
                            $saleDate,
                            'Payment on bill creation',
                        ]);
                    }

                    // Keep party in sync on any remaining installment receipts
                    $pdo->prepare('UPDATE payment_receipts SET party_id = ? WHERE invoice_id = ?')
                        ->execute([$partyId, $invoiceId]);

                    recalculateInvoiceStatus($pdo, $invoiceId);
                    $pdo->commit();
                } catch (Throwable $txError) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $txError;
                }

                $billItems = [];
                foreach ($preparedLines as $line) {
                    $inventory = $line['inventory'];
                    $billItems[] = [
                        'inventoryName' => $inventory['inventory_name'] ?? 'Unknown',
                        'packing' => $inventory['packing_name'] ?? '',
                        'packingWeight' => isset($inventory['packing_weight']) && $inventory['packing_weight'] !== null
                            ? (float) $inventory['packing_weight']
                            : null,
                        'weight' => $line['weight'],
                        'price' => $line['price'],
                        'lineTotal' => $line['lineTotal'],
                    ];
                }

                sendJson([
                    'ok' => true,
                    'data' => [
                        'id' => (string) $billId,
                        'billId' => (string) $billId,
                        'invoiceId' => (string) $invoiceId,
                        'saleIds' => $saleIds,
                        'itemCount' => count($saleIds),
                        'bill' => [
                            'billId' => (string) $billId,
                            'saleIds' => $saleIds,
                            'invoiceId' => (string) $invoiceId,
                            'partyName' => $party['name'] ?? 'Unknown',
                            'partyPhone' => $party['phone'] ?? '',
                            'date' => $saleDate,
                            'paymentType' => $paymentType,
                            'paymentStatus' => ($amountPaid >= $totalBillAmount && $totalBillAmount > 0)
                                ? 'Paid'
                                : ($amountPaid > 0 ? 'Partial' : 'Pending'),
                            'items' => $billItems,
                            'totalAmount' => $totalBillAmount,
                            'amountPaid' => $amountPaid,
                            'amountRemaining' => max(0, round($totalBillAmount - $amountPaid, 2)),
                        ],
                    ],
                ]);
                break;
            }

            $partyId = (int) ($payload['partyId'] ?? 0);
            $newInventoryId = (int) ($payload['inventoryId'] ?? 0);
            $newWeight = (float) ($payload['weight'] ?? 0);
            $newPrice = (float) ($payload['price'] ?? 0);
            $newPaymentType = $payload['paymentType'] ?? 'Cash';
            $satisfactionRating = array_key_exists('satisfactionRating', $payload)
                ? (($payload['satisfactionRating'] === '' || $payload['satisfactionRating'] === null)
                    ? null
                    : (float) $payload['satisfactionRating'])
                : null;
            if ($partyId <= 0) {
                sendJson(['ok' => false, 'message' => 'Party is required.'], 400);
            }
            if ($newInventoryId <= 0) {
                sendJson(['ok' => false, 'message' => 'Inventory item is required.'], 400);
            }
            if (!is_numeric((string) ($payload['weight'] ?? '')) || $newWeight <= 0) {
                sendJson(['ok' => false, 'message' => 'Sale piece must be a valid number greater than 0.'], 400);
            }
            if (!is_numeric((string) ($payload['price'] ?? '')) || $newPrice < 0) {
                sendJson(['ok' => false, 'message' => 'Sale price must be a valid number 0 or greater.'], 400);
            }

            if ($satisfactionRating !== null && ($satisfactionRating < 1 || $satisfactionRating > 5)) {
                sendJson(['ok' => false, 'message' => 'Satisfaction rating must be between 1 and 5.'], 400);
            }

            $pdo->beginTransaction();
            try {
                $partyStmt = $pdo->prepare('SELECT id FROM parties WHERE id = ?');
                $partyStmt->execute([$partyId]);
                if (!$partyStmt->fetch()) {
                    throw new RuntimeException('Selected party not found.');
                }

                $saleStmt = $pdo->prepare(
                    'SELECT id, inventory_id, weight, COALESCE(bill_id, id) AS bill_id FROM sales WHERE id = ? FOR UPDATE'
                );
                $saleStmt->execute([$id]);
                $existingSale = $saleStmt->fetch();
                if (!$existingSale) {
                    throw new RuntimeException('Sale not found.');
                }
                if ($newWeight <= 0) {
                    throw new RuntimeException('Sale piece must be greater than 0.');
                }

                $oldInventoryId = (int) $existingSale['inventory_id'];
                $oldWeight = (float) $existingSale['weight'];
                $billId = (int) $existingSale['bill_id'];

                $retCheck = $pdo->prepare('SELECT COUNT(*) FROM sale_returns WHERE sale_id = ?');
                $retCheck->execute([$id]);
                if ((int) $retCheck->fetchColumn() > 0) {
                    throw new RuntimeException(
                        'Cannot edit this sale because sale returns exist. Delete the returns first.'
                    );
                }

                if ($oldInventoryId === $newInventoryId) {
                    $inventoryStmt = $pdo->prepare('SELECT piece FROM inventory WHERE id = ? FOR UPDATE');
                    $inventoryStmt->execute([$newInventoryId]);
                    $inventory = $inventoryStmt->fetch();
                    if (!$inventory) {
                        throw new RuntimeException('Selected inventory not found.');
                    }
                    $effectiveAvailable = (float) $inventory['piece'] + $oldWeight;
                    if ($newWeight > $effectiveAvailable) {
                        throw new RuntimeException('Sale piece cannot be greater than available stock.');
                    }

                    $pieceAdjustment = $oldWeight - $newWeight;
                    $updateInventory = $pdo->prepare('UPDATE inventory SET piece = piece + ? WHERE id = ?');
                    $updateInventory->execute([$pieceAdjustment, $newInventoryId]);
                } else {
                    $oldInventoryStmt = $pdo->prepare('SELECT piece FROM inventory WHERE id = ? FOR UPDATE');
                    $oldInventoryStmt->execute([$oldInventoryId]);
                    $oldInventory = $oldInventoryStmt->fetch();
                    if (!$oldInventory) {
                        throw new RuntimeException('Original inventory not found.');
                    }

                    $newInventoryStmt = $pdo->prepare('SELECT piece FROM inventory WHERE id = ? FOR UPDATE');
                    $newInventoryStmt->execute([$newInventoryId]);
                    $newInventory = $newInventoryStmt->fetch();
                    if (!$newInventory) {
                        throw new RuntimeException('Selected inventory not found.');
                    }

                    if ($newWeight > (float) $newInventory['piece']) {
                        throw new RuntimeException('Sale piece cannot be greater than available stock.');
                    }

                    $restoreOld = $pdo->prepare('UPDATE inventory SET piece = piece + ? WHERE id = ?');
                    $restoreOld->execute([$oldWeight, $oldInventoryId]);

                    $consumeNew = $pdo->prepare('UPDATE inventory SET piece = piece - ? WHERE id = ?');
                    $consumeNew->execute([$newWeight, $newInventoryId]);
                }

                $stmt = $pdo->prepare(
                    'UPDATE sales SET party_id = ?, inventory_id = ?, weight = ?, price = ?, payment_type = ?, satisfaction_rating = ? WHERE id = ?'
                );
                $stmt->execute([$partyId, $newInventoryId, $newWeight, $newPrice, $newPaymentType, $satisfactionRating, $id]);

                // Keep party/payment synced on sibling lines of the same bill
                $syncBill = $pdo->prepare(
                    'UPDATE sales SET party_id = ?, payment_type = ?, satisfaction_rating = ? WHERE COALESCE(bill_id, id) = ?'
                );
                $syncBill->execute([$partyId, $newPaymentType, $satisfactionRating, $billId]);

                $payInv = $pdo->prepare('UPDATE invoices SET party_id = ?, payment_type = ? WHERE bill_id = ? OR sale_id = ?');
                $payInv->execute([$partyId, $newPaymentType, $billId, $billId]);

                recalculateBillInvoice($pdo, $billId);

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
                sendJson(['ok' => false, 'message' => 'Invalid sale id.'], 400);
            }
            $pdo->beginTransaction();
            try {
                $saleStmt = $pdo->prepare(
                    'SELECT id, inventory_id, weight, COALESCE(bill_id, id) AS bill_id FROM sales WHERE id = ? OR COALESCE(bill_id, id) = ? FOR UPDATE'
                );
                $saleStmt->execute([$id, $id]);
                $sales = $saleStmt->fetchAll();
                if (!$sales) {
                    throw new RuntimeException('Sale not found.');
                }

                $billId = (int) $sales[0]['bill_id'];
                $allStmt = $pdo->prepare(
                    'SELECT id, inventory_id, weight FROM sales WHERE COALESCE(bill_id, id) = ? FOR UPDATE'
                );
                $allStmt->execute([$billId]);
                $allSales = $allStmt->fetchAll();
                $saleIds = array_map(fn($s) => (int) $s['id'], $allSales);

                // Clear sale returns first (FK restrict) — restore only remaining sold qty
                if ($saleIds) {
                    $ph = implode(',', array_fill(0, count($saleIds), '?'));
                    $retStmt = $pdo->prepare("SELECT * FROM sale_returns WHERE sale_id IN ($ph) FOR UPDATE");
                    $retStmt->execute($saleIds);
                    $returns = $retStmt->fetchAll();
                    $returnedBySale = [];
                    foreach ($returns as $ret) {
                        $sid = (int) $ret['sale_id'];
                        $returnedBySale[$sid] = ($returnedBySale[$sid] ?? 0) + (float) $ret['weight'];
                        if (!empty($ret['stock_id'])) {
                            $pdo->prepare('DELETE FROM stock WHERE id = ?')->execute([(int) $ret['stock_id']]);
                        }
                    }
                    $pdo->prepare("DELETE FROM sale_returns WHERE sale_id IN ($ph)")->execute($saleIds);

                    $restoreInventory = $pdo->prepare('UPDATE inventory SET piece = piece + ? WHERE id = ?');
                    foreach ($allSales as $sale) {
                        $sid = (int) $sale['id'];
                        $returned = (float) ($returnedBySale[$sid] ?? 0);
                        $toRestore = round((float) $sale['weight'] - $returned, 2);
                        if ($toRestore > 0) {
                            $restoreInventory->execute([$toRestore, (int) $sale['inventory_id']]);
                        }
                    }
                } else {
                    $restoreInventory = $pdo->prepare('UPDATE inventory SET piece = piece + ? WHERE id = ?');
                    foreach ($allSales as $sale) {
                        $restoreInventory->execute([(float) $sale['weight'], (int) $sale['inventory_id']]);
                    }
                }

                $invoiceIds = [];
                $invFind = $pdo->prepare('SELECT id FROM invoices WHERE bill_id = ?');
                $invFind->execute([$billId]);
                foreach ($invFind->fetchAll() as $invRow) {
                    $invoiceIds[] = (int) $invRow['id'];
                }
                foreach ($allSales as $sale) {
                    $bySale = $pdo->prepare('SELECT id FROM invoices WHERE sale_id = ?');
                    $bySale->execute([(int) $sale['id']]);
                    foreach ($bySale->fetchAll() as $invRow) {
                        $invoiceIds[] = (int) $invRow['id'];
                    }
                }
                $invoiceIds = array_values(array_unique($invoiceIds));
                foreach ($invoiceIds as $invoiceId) {
                    $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$invoiceId]);
                }

                $stmt = $pdo->prepare('DELETE FROM sales WHERE COALESCE(bill_id, id) = ?');
                $stmt->execute([$billId]);
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
