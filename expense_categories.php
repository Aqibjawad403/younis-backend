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
            $rows = $pdo->query('SELECT id, name, details, created_at, updated_at FROM expense_categories ORDER BY id DESC')->fetchAll();
            $data = array_map(function ($row) {
                return [
                    'id' => (string) $row['id'],
                    'name' => $row['name'],
                    'details' => $row['details'] ?? '',
                    'createdAt' => $row['created_at'],
                    'updatedAt' => $row['updated_at']
                ];
            }, $rows);
            sendJson(['ok' => true, 'data' => $data]);
            break;

        case 'get':
            $id = (int) ($input['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT id, name, details, created_at, updated_at FROM expense_categories WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                sendJson(['ok' => true, 'data' => null]);
            }
            sendJson([
                'ok' => true,
                'data' => [
                    'id' => (string) $row['id'],
                    'name' => $row['name'],
                    'details' => $row['details'] ?? '',
                    'createdAt' => $row['created_at'],
                    'updatedAt' => $row['updated_at']
                ]
            ]);
            break;

        case 'create':
            $payload = $input['payload'] ?? [];
            $name = trim((string) ($payload['name'] ?? ''));
            if ($name === '') {
                sendJson(['ok' => false, 'message' => 'Category name is required.'], 400);
            }
            $stmt = $pdo->prepare('INSERT INTO expense_categories (name, details) VALUES (?, ?)');
            $stmt->execute([
                $name,
                trim((string) ($payload['details'] ?? ''))
            ]);
            sendJson(['ok' => true, 'data' => ['id' => (string) $pdo->lastInsertId()]]);
            break;

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            $payload = $input['payload'] ?? [];
            $name = trim((string) ($payload['name'] ?? ''));
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid category id.'], 400);
            }
            if ($name === '') {
                sendJson(['ok' => false, 'message' => 'Category name is required.'], 400);
            }
            $stmt = $pdo->prepare('UPDATE expense_categories SET name = ?, details = ? WHERE id = ?');
            $stmt->execute([
                $name,
                trim((string) ($payload['details'] ?? '')),
                $id
            ]);
            sendJson(['ok' => true]);
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid category id.'], 400);
            }
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM expenses WHERE category_id = ?');
            $countStmt->execute([$id]);
            if ((int) $countStmt->fetchColumn() > 0) {
                sendJson(['ok' => false, 'message' => 'Cannot delete category that has expenses. Delete expenses first.'], 400);
            }
            $stmt = $pdo->prepare('DELETE FROM expense_categories WHERE id = ?');
            $stmt->execute([$id]);
            sendJson(['ok' => true]);
            break;

        default:
            sendJson(['ok' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    sendJson(['ok' => false, 'message' => $e->getMessage()], 500);
}
