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
            $rows = $pdo->query('SELECT id, bank_name, opening_balance, details, created_at, updated_at FROM banks ORDER BY id DESC')->fetchAll();
            $data = array_map(function ($row) {
                return [
                    'id' => (string) $row['id'],
                    'bankName' => $row['bank_name'],
                    'openingBalance' => (float) $row['opening_balance'],
                    'details' => $row['details'] ?? '',
                    'createdAt' => $row['created_at'],
                    'updatedAt' => $row['updated_at']
                ];
            }, $rows);
            sendJson(['ok' => true, 'data' => $data]);
            break;

        case 'get':
            $id = (int) ($input['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT id, bank_name, opening_balance, details, created_at, updated_at FROM banks WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                sendJson(['ok' => true, 'data' => null]);
            }
            sendJson([
                'ok' => true,
                'data' => [
                    'id' => (string) $row['id'],
                    'bankName' => $row['bank_name'],
                    'openingBalance' => (float) $row['opening_balance'],
                    'details' => $row['details'] ?? '',
                    'createdAt' => $row['created_at'],
                    'updatedAt' => $row['updated_at']
                ]
            ]);
            break;

        case 'create':
            $payload = $input['payload'] ?? [];
            $bankName = trim((string) ($payload['bankName'] ?? ''));
            $openingBalanceRaw = (string) ($payload['openingBalance'] ?? '0');
            $openingBalance = (float) $openingBalanceRaw;
            if ($bankName === '') {
                sendJson(['ok' => false, 'message' => 'Bank name is required.'], 400);
            }
            if (!is_numeric($openingBalanceRaw) || $openingBalance < 0) {
                sendJson(['ok' => false, 'message' => 'Opening balance must be a valid number 0 or greater.'], 400);
            }
            $stmt = $pdo->prepare('INSERT INTO banks (bank_name, opening_balance, details) VALUES (?, ?, ?)');
            $stmt->execute([
                $bankName,
                $openingBalance,
                trim((string) ($payload['details'] ?? ''))
            ]);
            $id = (int) $pdo->lastInsertId();
            sendJson(['ok' => true, 'data' => ['id' => (string) $id]]);
            break;

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            $payload = $input['payload'] ?? [];
            $bankName = trim((string) ($payload['bankName'] ?? ''));
            $openingBalanceRaw = (string) ($payload['openingBalance'] ?? '0');
            $openingBalance = (float) $openingBalanceRaw;
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid bank id.'], 400);
            }
            if ($bankName === '') {
                sendJson(['ok' => false, 'message' => 'Bank name is required.'], 400);
            }
            if (!is_numeric($openingBalanceRaw) || $openingBalance < 0) {
                sendJson(['ok' => false, 'message' => 'Opening balance must be a valid number 0 or greater.'], 400);
            }
            $stmt = $pdo->prepare('UPDATE banks SET bank_name = ?, opening_balance = ?, details = ? WHERE id = ?');
            $stmt->execute([
                $bankName,
                $openingBalance,
                trim((string) ($payload['details'] ?? '')),
                $id
            ]);
            sendJson(['ok' => true]);
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid bank id.'], 400);
            }
            $stmt = $pdo->prepare('DELETE FROM banks WHERE id = ?');
            $stmt->execute([$id]);
            sendJson(['ok' => true]);
            break;

        default:
            sendJson(['ok' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    sendJson(['ok' => false, 'message' => $e->getMessage()], 500);
}
