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
            $rows = $pdo->query(
                'SELECT id, name, created_at, updated_at FROM cities ORDER BY name ASC'
            )->fetchAll();
            $data = array_map(function ($row) {
                return [
                    'id' => (string) $row['id'],
                    'name' => $row['name'],
                    'createdAt' => $row['created_at'],
                    'updatedAt' => $row['updated_at']
                ];
            }, $rows);
            sendJson(['ok' => true, 'data' => $data]);
            break;

        case 'get':
            $id = (int) ($input['id'] ?? 0);
            $stmt = $pdo->prepare(
                'SELECT id, name, created_at, updated_at FROM cities WHERE id = ?'
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
                    'name' => $row['name'],
                    'createdAt' => $row['created_at'],
                    'updatedAt' => $row['updated_at']
                ]
            ]);
            break;

        case 'create':
            $payload = $input['payload'] ?? [];
            $name = trim((string) ($payload['name'] ?? ''));
            if ($name === '') {
                sendJson(['ok' => false, 'message' => 'City name is required.'], 400);
            }
            $dup = $pdo->prepare('SELECT id FROM cities WHERE LOWER(name) = LOWER(?) LIMIT 1');
            $dup->execute([$name]);
            if ($dup->fetch()) {
                sendJson(['ok' => false, 'message' => 'City already exists.'], 400);
            }
            $stmt = $pdo->prepare('INSERT INTO cities (name) VALUES (?)');
            $stmt->execute([$name]);
            sendJson(['ok' => true, 'data' => ['id' => (string) $pdo->lastInsertId()]]);
            break;

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            $payload = $input['payload'] ?? [];
            $name = trim((string) ($payload['name'] ?? ''));
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid city id.'], 400);
            }
            if ($name === '') {
                sendJson(['ok' => false, 'message' => 'City name is required.'], 400);
            }
            $dup = $pdo->prepare(
                'SELECT id FROM cities WHERE LOWER(name) = LOWER(?) AND id <> ? LIMIT 1'
            );
            $dup->execute([$name, $id]);
            if ($dup->fetch()) {
                sendJson(['ok' => false, 'message' => 'City already exists.'], 400);
            }
            $stmt = $pdo->prepare('UPDATE cities SET name = ? WHERE id = ?');
            $stmt->execute([$name, $id]);
            sendJson(['ok' => true]);
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid city id.'], 400);
            }
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM parties WHERE city_id = ?');
            $countStmt->execute([$id]);
            if ((int) $countStmt->fetchColumn() > 0) {
                sendJson(['ok' => false, 'message' => 'Cannot delete city that is used by parties.'], 400);
            }
            $stmt = $pdo->prepare('DELETE FROM cities WHERE id = ?');
            $stmt->execute([$id]);
            sendJson(['ok' => true]);
            break;

        default:
            sendJson(['ok' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    sendJson(['ok' => false, 'message' => $e->getMessage()], 500);
}
