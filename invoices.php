<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJson(['ok' => true]);
}

try {
    $pdo = getDbConnection();
    repairInvoiceTotalsFromItems($pdo);
    $input = readJsonInput();
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'list':
            $rows = $pdo->query(invoiceListQuery() . ' ORDER BY inv.id DESC')->fetchAll();
            $data = array_map(function ($row) use ($pdo) {
                $items = fetchInvoiceItems($pdo, (int) $row['id']);
                return mapInvoiceRow($row, $items);
            }, $rows);
            sendJson(['ok' => true, 'data' => $data]);
            break;

        case 'get':
            $id = (int) ($input['id'] ?? 0);
            $stmt = $pdo->prepare(invoiceListQuery() . ' WHERE inv.id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                sendJson(['ok' => true, 'data' => null]);
            }

            $installmentStmt = $pdo->prepare(
                'SELECT pr.id, pr.amount, pr.payment_type, pr.receipt_date, pr.details, pr.bank_id, b.bank_name
                 FROM payment_receipts pr
                 LEFT JOIN banks b ON pr.bank_id = b.id
                 WHERE pr.invoice_id = ?
                 ORDER BY pr.receipt_date ASC, pr.id ASC'
            );
            $installmentStmt->execute([$id]);
            $installments = array_map(function ($item) {
                return [
                    'id' => (string) $item['id'],
                    'amount' => (float) $item['amount'],
                    'paymentType' => $item['payment_type'],
                    'date' => $item['receipt_date'],
                    'details' => $item['details'] ?? '',
                    'bankId' => $item['bank_id'] ? (int) $item['bank_id'] : null,
                    'bankName' => $item['bank_name'] ?? '-'
                ];
            }, $installmentStmt->fetchAll());

            $items = fetchInvoiceItems($pdo, $id);
            $invoice = mapInvoiceRow($row, $items);
            $invoice['installments'] = $installments;
            sendJson(['ok' => true, 'data' => $invoice]);
            break;

        case 'updateStatus':
            $id = (int) ($input['id'] ?? 0);
            $status = $input['status'] ?? 'Pending';

            if (!in_array($status, ['Pending', 'Deferred'], true)) {
                sendJson(['ok' => false, 'message' => 'Status can only be changed to Pending or Deferred. Use Receive Payment for Partial/Received.'], 400);
            }

            $stmt = $pdo->prepare('SELECT payment_status FROM invoices WHERE id = ?');
            $stmt->execute([$id]);
            $invoice = $stmt->fetch();
            if (!$invoice) {
                sendJson(['ok' => false, 'message' => 'Invoice not found.'], 404);
            }
            if ($invoice['payment_status'] === 'Received') {
                sendJson(['ok' => false, 'message' => 'Fully received invoice status cannot be changed.'], 400);
            }
            if ($invoice['payment_status'] === 'Partial') {
                sendJson(['ok' => false, 'message' => 'Partially paid invoice status cannot be changed until payments are removed.'], 400);
            }

            $update = $pdo->prepare('UPDATE invoices SET payment_status = ? WHERE id = ?');
            $update->execute([$status, $id]);
            sendJson(['ok' => true]);
            break;

        case 'receivePayment':
            $id = (int) ($input['id'] ?? 0);
            $amount = round((float) ($input['amount'] ?? 0), 2);
            $paymentType = (string) ($input['paymentType'] ?? '');
            $bankId = !empty($input['bankId']) ? (int) $input['bankId'] : null;
            $date = $input['date'] ?? date('Y-m-d');
            $details = trim((string) ($input['details'] ?? ''));

            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid invoice id.'], 400);
            }
            if ($amount <= 0) {
                sendJson(['ok' => false, 'message' => 'Payment amount must be greater than 0.'], 400);
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT party_id, amount, payment_type, payment_status FROM invoices WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $invoice = $stmt->fetch();

                if (!$invoice) {
                    throw new RuntimeException('Invoice not found.');
                }
                if ($invoice['payment_status'] === 'Received') {
                    throw new RuntimeException('Invoice is already fully received.');
                }

                $totalAmount = round((float) $invoice['amount'], 2);
                $remaining = getInvoiceRemainingAmount($pdo, $id, $totalAmount);
                if ($amount > $remaining) {
                    throw new RuntimeException("Payment amount cannot exceed remaining balance ($remaining).");
                }

                if ($paymentType === '') {
                    $paymentType = $invoice['payment_type'];
                }
                if (!in_array($paymentType, ['Cash', 'Cheque', 'Online'], true)) {
                    throw new RuntimeException('Invalid payment type.');
                }
                if ($paymentType !== 'Cash' && (!$bankId || $bankId <= 0)) {
                    throw new RuntimeException('Bank is required for Cheque and Online payments.');
                }
                if ($paymentType === 'Cash') {
                    $bankId = null;
                }

                if ($details === '') {
                    $details = "Installment for Invoice #$id";
                }

                $insert = $pdo->prepare(
                    'INSERT INTO payment_receipts (party_id, invoice_id, bank_id, amount, payment_type, receipt_date, details) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $invoice['party_id'],
                    $id,
                    $bankId,
                    $amount,
                    $paymentType,
                    $date,
                    $details
                ]);

                recalculateInvoiceStatus($pdo, $id);

                $statusStmt = $pdo->prepare('SELECT payment_status FROM invoices WHERE id = ?');
                $statusStmt->execute([$id]);
                $paymentStatus = $statusStmt->fetchColumn();

                $pdo->commit();
                sendJson([
                    'ok' => true,
                    'data' => [
                        'receivedAmount' => getInvoiceReceivedAmount($pdo, $id),
                        'remainingAmount' => getInvoiceRemainingAmount($pdo, $id, $totalAmount),
                        'paymentStatus' => $paymentStatus
                    ]
                ]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM invoices WHERE id = ?');
            $stmt->execute([$id]);
            sendJson(['ok' => true]);
            break;

        default:
            sendJson(['ok' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    sendJson(['ok' => false, 'message' => $e->getMessage()], 500);
}
