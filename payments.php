<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJson(['ok' => true]);
}

try {
    $pdo = getDbConnection();
    $input = readJsonInput();
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'list':
            $rows = $pdo->query('SELECT pr.id, pr.party_id, pr.invoice_id, p.name AS party_name, pr.bank_id, b.bank_name, pr.amount, pr.payment_type, pr.receipt_date, pr.details, pr.created_at FROM payment_receipts pr LEFT JOIN parties p ON pr.party_id = p.id LEFT JOIN banks b ON pr.bank_id = b.id ORDER BY pr.id DESC')->fetchAll();
            $data = array_map(function ($row) {
                return [
                    'id' => (string) $row['id'],
                    'partyId' => (int) $row['party_id'],
                    'invoiceId' => $row['invoice_id'] ? (int) $row['invoice_id'] : null,
                    'partyName' => $row['party_name'] ?? 'Unknown',
                    'bankId' => $row['bank_id'],
                    'bankName' => $row['bank_name'] ?? ($row['payment_type'] === 'Cash' ? '-' : 'Unknown'),
                    'amount' => (float) $row['amount'],
                    'paymentType' => $row['payment_type'],
                    'date' => $row['receipt_date'],
                    'details' => $row['details'],
                    'createdAt' => $row['created_at']
                ];
            }, $rows);
            sendJson(['ok' => true, 'data' => $data]);
            break;

        case 'create':
            $payload = $input['payload'] ?? [];
            $partyId = (int) ($payload['partyId'] ?? 0);
            $bankId = !empty($payload['bankId']) ? (int) $payload['bankId'] : null;
            $amount = (float) ($payload['amount'] ?? 0);
            $paymentType = $payload['paymentType'] ?? 'Cash';
            $date = $payload['date'] ?? date('Y-m-d');
            $details = $payload['details'] ?? '';

            if ($partyId <= 0 || $amount <= 0) {
                sendJson(['ok' => false, 'message' => 'Party and Amount are required.'], 400);
            }

            $stmt = $pdo->prepare('INSERT INTO payment_receipts (party_id, bank_id, amount, payment_type, receipt_date, details) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$partyId, $bankId, $amount, $paymentType, $date, $details]);
            sendJson(['ok' => true, 'data' => ['id' => (string) $pdo->lastInsertId()]]);
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT invoice_id FROM payment_receipts WHERE id = ?');
                $stmt->execute([$id]);
                $receipt = $stmt->fetch();
                if (!$receipt) {
                    throw new RuntimeException('Receipt not found.');
                }

                $delete = $pdo->prepare('DELETE FROM payment_receipts WHERE id = ?');
                $delete->execute([$id]);

                if (!empty($receipt['invoice_id'])) {
                    recalculateInvoiceStatus($pdo, (int) $receipt['invoice_id']);
                }

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
