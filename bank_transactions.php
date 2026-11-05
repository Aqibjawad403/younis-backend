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
        case 'create':
            $payload = $input['payload'] ?? [];
            $bankId = (int) ($payload['bankId'] ?? 0);
            $type = $payload['type'] ?? 'Inflow';
            $amount = (float) ($payload['amount'] ?? 0);
            $date = $payload['date'] ?? date('Y-m-d');
            $details = $payload['details'] ?? '';

            if ($bankId <= 0 || $amount <= 0) {
                sendJson(['ok' => false, 'message' => 'Bank and Amount are required.'], 400);
            }

            $stmt = $pdo->prepare('INSERT INTO bank_transactions (bank_id, type, amount, transaction_date, details) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$bankId, $type, $amount, $date, $details]);
            sendJson(['ok' => true, 'data' => ['id' => (string) $pdo->lastInsertId()]]);
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM bank_transactions WHERE id = ?');
            $stmt->execute([$id]);
            sendJson(['ok' => true]);
            break;

        default:
            sendJson(['ok' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    sendJson(['ok' => false, 'message' => $e->getMessage()], 500);
}
