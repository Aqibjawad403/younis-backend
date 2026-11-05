<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJson(['ok' => true]);
}

function validateExpensePayload(array $payload, bool $isUpdate = false) {
    $categoryId = (int) ($payload['categoryId'] ?? 0);
    $amount = (float) ($payload['amount'] ?? 0);
    $paymentType = (string) ($payload['paymentType'] ?? 'Cash');
    $bankId = !empty($payload['bankId']) ? (int) $payload['bankId'] : null;
    $date = trim((string) ($payload['date'] ?? ''));
    $details = trim((string) ($payload['details'] ?? ''));

    if ($categoryId <= 0) {
        return ['ok' => false, 'message' => 'Expense category is required.'];
    }
    if ($amount <= 0) {
        return ['ok' => false, 'message' => 'Amount must be greater than 0.'];
    }
    if (!in_array($paymentType, ['Cash', 'Cheque', 'Online'], true)) {
        return ['ok' => false, 'message' => 'Invalid payment type.'];
    }
    if ($paymentType !== 'Cash' && (!$bankId || $bankId <= 0)) {
        return ['ok' => false, 'message' => 'Bank is required for Cheque and Online payments.'];
    }
    if ($paymentType === 'Cash') {
        $bankId = null;
    }
    if ($date === '') {
        $date = date('Y-m-d');
    }

    return [
        'ok' => true,
        'data' => [
            'categoryId' => $categoryId,
            'amount' => $amount,
            'paymentType' => $paymentType,
            'bankId' => $bankId,
            'date' => $date,
            'details' => $details
        ]
    ];
}

try {
    $pdo = getDbConnection();
    $input = readJsonInput();
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'list':
            $rows = $pdo->query(
                'SELECT e.id, e.category_id, ec.name AS category_name, e.bank_id, b.bank_name,
                        e.amount, e.payment_type, e.expense_date, e.details, e.created_at, e.updated_at
                 FROM expenses e
                 LEFT JOIN expense_categories ec ON e.category_id = ec.id
                 LEFT JOIN banks b ON e.bank_id = b.id
                 ORDER BY e.id DESC'
            )->fetchAll();
            $data = array_map(function ($row) {
                return [
                    'id' => (string) $row['id'],
                    'categoryId' => (int) $row['category_id'],
                    'categoryName' => $row['category_name'] ?? 'Unknown',
                    'bankId' => $row['bank_id'] ? (int) $row['bank_id'] : null,
                    'bankName' => $row['bank_name'] ?? ($row['payment_type'] === 'Cash' ? '-' : 'Unknown'),
                    'amount' => (float) $row['amount'],
                    'paymentType' => $row['payment_type'],
                    'date' => $row['expense_date'],
                    'details' => $row['details'] ?? '',
                    'createdAt' => $row['created_at'],
                    'updatedAt' => $row['updated_at']
                ];
            }, $rows);
            sendJson(['ok' => true, 'data' => $data]);
            break;

        case 'get':
            $id = (int) ($input['id'] ?? 0);
            $stmt = $pdo->prepare(
                'SELECT e.id, e.category_id, ec.name AS category_name, e.bank_id, b.bank_name,
                        e.amount, e.payment_type, e.expense_date, e.details, e.created_at, e.updated_at
                 FROM expenses e
                 LEFT JOIN expense_categories ec ON e.category_id = ec.id
                 LEFT JOIN banks b ON e.bank_id = b.id
                 WHERE e.id = ?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                sendJson(['ok' => true, 'data' => null]);
            }
            sendJson([
                'ok' => true,
                'data' => [
                    'id' => (string) $row['id'],
                    'categoryId' => (int) $row['category_id'],
                    'categoryName' => $row['category_name'] ?? 'Unknown',
                    'bankId' => $row['bank_id'] ? (int) $row['bank_id'] : null,
                    'bankName' => $row['bank_name'] ?? ($row['payment_type'] === 'Cash' ? '-' : 'Unknown'),
                    'amount' => (float) $row['amount'],
                    'paymentType' => $row['payment_type'],
                    'date' => $row['expense_date'],
                    'details' => $row['details'] ?? '',
                    'createdAt' => $row['created_at'],
                    'updatedAt' => $row['updated_at']
                ]
            ]);
            break;

        case 'create':
            $validation = validateExpensePayload($input['payload'] ?? []);
            if (!$validation['ok']) {
                sendJson(['ok' => false, 'message' => $validation['message']], 400);
            }
            $data = $validation['data'];

            $categoryStmt = $pdo->prepare('SELECT id FROM expense_categories WHERE id = ?');
            $categoryStmt->execute([$data['categoryId']]);
            if (!$categoryStmt->fetch()) {
                sendJson(['ok' => false, 'message' => 'Expense category not found.'], 400);
            }
            if ($data['bankId']) {
                $bankStmt = $pdo->prepare('SELECT id FROM banks WHERE id = ?');
                $bankStmt->execute([$data['bankId']]);
                if (!$bankStmt->fetch()) {
                    sendJson(['ok' => false, 'message' => 'Bank not found.'], 400);
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO expenses (category_id, bank_id, amount, payment_type, expense_date, details)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['categoryId'],
                $data['bankId'],
                $data['amount'],
                $data['paymentType'],
                $data['date'],
                $data['details']
            ]);
            sendJson(['ok' => true, 'data' => ['id' => (string) $pdo->lastInsertId()]]);
            break;

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid expense id.'], 400);
            }
            $validation = validateExpensePayload($input['payload'] ?? [], true);
            if (!$validation['ok']) {
                sendJson(['ok' => false, 'message' => $validation['message']], 400);
            }
            $data = $validation['data'];

            $existsStmt = $pdo->prepare('SELECT id FROM expenses WHERE id = ?');
            $existsStmt->execute([$id]);
            if (!$existsStmt->fetch()) {
                sendJson(['ok' => false, 'message' => 'Expense not found.'], 404);
            }

            $categoryStmt = $pdo->prepare('SELECT id FROM expense_categories WHERE id = ?');
            $categoryStmt->execute([$data['categoryId']]);
            if (!$categoryStmt->fetch()) {
                sendJson(['ok' => false, 'message' => 'Expense category not found.'], 400);
            }
            if ($data['bankId']) {
                $bankStmt = $pdo->prepare('SELECT id FROM banks WHERE id = ?');
                $bankStmt->execute([$data['bankId']]);
                if (!$bankStmt->fetch()) {
                    sendJson(['ok' => false, 'message' => 'Bank not found.'], 400);
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE expenses SET category_id = ?, bank_id = ?, amount = ?, payment_type = ?, expense_date = ?, details = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $data['categoryId'],
                $data['bankId'],
                $data['amount'],
                $data['paymentType'],
                $data['date'],
                $data['details'],
                $id
            ]);
            sendJson(['ok' => true]);
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid expense id.'], 400);
            }
            $stmt = $pdo->prepare('DELETE FROM expenses WHERE id = ?');
            $stmt->execute([$id]);
            sendJson(['ok' => true]);
            break;

        default:
            sendJson(['ok' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    sendJson(['ok' => false, 'message' => $e->getMessage()], 500);
}
