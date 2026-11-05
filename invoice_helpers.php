<?php

function getInvoiceReceivedAmount(PDO $pdo, int $invoiceId): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payment_receipts WHERE invoice_id = ?');
    $stmt->execute([$invoiceId]);
    return round((float) $stmt->fetchColumn(), 2);
}

function getInvoiceRemainingAmount(PDO $pdo, int $invoiceId, ?float $invoiceAmount = null): float
{
    if ($invoiceAmount === null) {
        $stmt = $pdo->prepare('SELECT amount FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch();
        if (!$row) {
            return 0;
        }
        $invoiceAmount = (float) $row['amount'];
    }

    return max(0, round($invoiceAmount - getInvoiceReceivedAmount($pdo, $invoiceId), 2));
}

function recalculateInvoiceStatus(PDO $pdo, int $invoiceId): void
{
    $stmt = $pdo->prepare('SELECT amount, payment_status FROM invoices WHERE id = ?');
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch();
    if (!$invoice) {
        return;
    }

    $total = round((float) $invoice['amount'], 2);
    $received = getInvoiceReceivedAmount($pdo, $invoiceId);
    $wasDeferred = $invoice['payment_status'] === 'Deferred';

    if ($received <= 0) {
        $newStatus = $wasDeferred ? 'Deferred' : 'Pending';
    } elseif ($received >= $total) {
        $newStatus = 'Received';
    } else {
        $newStatus = 'Partial';
    }

    $update = $pdo->prepare('UPDATE invoices SET payment_status = ? WHERE id = ?');
    $update->execute([$newStatus, $invoiceId]);
}

/**
 * Bill total must equal the amount column.
 * If invoices.amount drifted above or below the saved lines, write the line sum back.
 */
function repairInvoiceTotalsFromItems(PDO $pdo): void
{
    $ids = $pdo->query(
        'SELECT inv.id
         FROM invoices inv
         INNER JOIN (
            SELECT invoice_id, ROUND(SUM(amount), 2) AS line_sum
            FROM invoice_items
            GROUP BY invoice_id
         ) item_totals ON item_totals.invoice_id = inv.id
         WHERE ABS(inv.amount - item_totals.line_sum) > 0.009'
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!$ids) {
        return;
    }

    $pdo->exec(
        'UPDATE invoices inv
         INNER JOIN (
            SELECT invoice_id, ROUND(SUM(amount), 2) AS line_sum
            FROM invoice_items
            GROUP BY invoice_id
         ) item_totals ON item_totals.invoice_id = inv.id
         SET inv.amount = item_totals.line_sum
         WHERE ABS(inv.amount - item_totals.line_sum) > 0.009'
    );

    foreach ($ids as $invoiceId) {
        recalculateInvoiceStatus($pdo, (int) $invoiceId);
    }
}

function fetchInvoiceItems(PDO $pdo, int $invoiceId): array
{
    $stmt = $pdo->prepare(
        "SELECT ii.id, ii.sale_id, ii.inventory_id, i.inventory_name,
                i.packing_id, COALESCE(pk.name, i.packing) AS packing_name, pk.weight AS packing_weight,
                ii.weight, ii.price, ii.amount
         FROM invoice_items ii
         LEFT JOIN inventory i ON i.id = ii.inventory_id
         LEFT JOIN packings pk ON pk.id = i.packing_id
         WHERE ii.invoice_id = ?
         ORDER BY ii.id ASC"
    );
    $stmt->execute([$invoiceId]);
    $rows = $stmt->fetchAll();
    return array_map(function ($row) {
        return [
            'id' => (string) $row['id'],
            'saleId' => (int) $row['sale_id'],
            'inventoryId' => (int) $row['inventory_id'],
            'inventoryName' => $row['inventory_name'] ?? 'Unknown',
            'packingId' => !empty($row['packing_id']) ? (string) $row['packing_id'] : null,
            'packing' => $row['packing_name'] ?? '',
            'packingWeight' => isset($row['packing_weight']) && $row['packing_weight'] !== null
                ? (float) $row['packing_weight']
                : null,
            'weight' => (float) $row['weight'],
            'price' => (float) $row['price'],
            'amount' => round((float) $row['amount'], 2),
        ];
    }, $rows);
}

function mapInvoiceRow(array $row, array $items = []): array
{
    $amount = round((float) $row['amount'], 2);
    $received = round((float) ($row['received_amount'] ?? 0), 2);
    $remaining = max(0, round($amount - $received, 2));

    if (!$items && !empty($row['inventory_name'])) {
        $items = [];
    }

    $names = array_values(array_unique(array_map(function ($item) {
        return $item['inventoryName'];
    }, $items)));
    if (!$names && !empty($row['inventory_name'])) {
        $names = [$row['inventory_name']];
    }
    $inventoryLabel = !$names
        ? 'Unknown'
        : (count($names) <= 2
            ? implode(' + ', $names)
            : $names[0] . ' + ' . (count($names) - 1) . ' more');

    $packing = count($items) > 1
        ? (count($items) . ' products')
        : ($row['packing_name'] ?? $row['packing'] ?? (count($items) === 1 ? ($items[0]['packing'] ?? '') : ''));

    $packingWeight = null;
    if (count($items) === 1) {
        $packingWeight = $items[0]['packingWeight'] ?? null;
    } elseif (isset($row['packing_weight']) && $row['packing_weight'] !== null) {
        $packingWeight = (float) $row['packing_weight'];
    }

    return [
        'id' => (string) $row['id'],
        'billId' => !empty($row['bill_id']) ? (string) $row['bill_id'] : null,
        'saleId' => (int) $row['sale_id'],
        'partyId' => (string) $row['party_id'],
        'partyName' => $row['party_name'] ?? 'Unknown',
        'inventoryName' => $inventoryLabel,
        'packingId' => !empty($row['packing_id']) ? (string) $row['packing_id'] : null,
        'packing' => $packing,
        'packingWeight' => $packingWeight,
        'amount' => $amount,
        'receivedAmount' => $received,
        'remainingAmount' => $remaining,
        'paymentType' => $row['payment_type'],
        'paymentStatus' => $row['payment_status'],
        // Prefer sales.sale_date (Bill Date from sales form), then invoice_date
        'date' => $row['bill_date'] ?? $row['invoice_date'],
        'itemCount' => max(count($items), 1),
        'items' => $items
    ];
}

function invoiceListQuery(): string
{
    return "SELECT inv.id, inv.bill_id, inv.sale_id, inv.party_id, p.name AS party_name, i.inventory_name,
                   i.packing_id, COALESCE(pk.name, i.packing) AS packing_name, pk.weight AS packing_weight,
                   inv.amount, inv.payment_type, inv.payment_status, inv.invoice_date,
                   COALESCE(
                     (
                       SELECT COALESCE(s.sale_date, DATE(s.created_at))
                       FROM sales s
                       WHERE COALESCE(s.bill_id, s.id) = COALESCE(inv.bill_id, inv.sale_id)
                       ORDER BY s.id ASC
                       LIMIT 1
                     ),
                     DATE(inv.invoice_date)
                   ) AS bill_date,
                   COALESCE((
                     SELECT SUM(pr.amount)
                     FROM payment_receipts pr
                     WHERE pr.invoice_id = inv.id
                   ), 0) AS received_amount
            FROM invoices inv
            LEFT JOIN parties p ON inv.party_id = p.id
            LEFT JOIN inventory i ON inv.inventory_id = i.id
            LEFT JOIN packings pk ON pk.id = i.packing_id";
}
