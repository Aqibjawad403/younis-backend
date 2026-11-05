<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJson(['ok' => true]);
}

function mapPackingRow($row)
{
    return [
        'id' => (string) $row['id'],
        'name' => $row['name'],
        'weight' => (float) $row['weight'],
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at']
    ];
}

try {
    $pdo = getDbConnection();
    $input = readJsonInput();
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'list':
            $rows = $pdo->query(
                'SELECT id, name, weight, created_at, updated_at FROM packings ORDER BY weight ASC, name ASC'
            )->fetchAll();
            $data = array_map('mapPackingRow', $rows);
            sendJson(['ok' => true, 'data' => $data]);
            break;

        case 'get':
            $id = (int) ($input['id'] ?? 0);
            $stmt = $pdo->prepare(
                'SELECT id, name, weight, created_at, updated_at FROM packings WHERE id = ?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                sendJson(['ok' => true, 'data' => null]);
            }
            sendJson(['ok' => true, 'data' => mapPackingRow($row)]);
            break;

        case 'create':
            $payload = $input['payload'] ?? [];
            $name = trim((string) ($payload['name'] ?? ''));
            $weightRaw = (string) ($payload['weight'] ?? '');
            $weight = (float) $weightRaw;

            if ($name === '') {
                sendJson(['ok' => false, 'message' => 'Packing name is required.'], 400);
            }
            if (!is_numeric($weightRaw) || $weight <= 0) {
                sendJson(['ok' => false, 'message' => 'Weight must be a number greater than 0.'], 400);
            }

            $dup = $pdo->prepare('SELECT id FROM packings WHERE LOWER(name) = LOWER(?) LIMIT 1');
            $dup->execute([$name]);
            if ($dup->fetch()) {
                sendJson(['ok' => false, 'message' => 'Packing already exists.'], 400);
            }

            $stmt = $pdo->prepare('INSERT INTO packings (name, weight) VALUES (?, ?)');
            $stmt->execute([$name, $weight]);
            sendJson(['ok' => true, 'data' => ['id' => (string) $pdo->lastInsertId()]]);
            break;

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            $payload = $input['payload'] ?? [];
            $name = trim((string) ($payload['name'] ?? ''));
            $weightRaw = (string) ($payload['weight'] ?? '');
            $weight = (float) $weightRaw;

            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid packing id.'], 400);
            }
            if ($name === '') {
                sendJson(['ok' => false, 'message' => 'Packing name is required.'], 400);
            }
            if (!is_numeric($weightRaw) || $weight <= 0) {
                sendJson(['ok' => false, 'message' => 'Weight must be a number greater than 0.'], 400);
            }

            $dup = $pdo->prepare(
                'SELECT id FROM packings WHERE LOWER(name) = LOWER(?) AND id <> ? LIMIT 1'
            );
            $dup->execute([$name, $id]);
            if ($dup->fetch()) {
                sendJson(['ok' => false, 'message' => 'Packing already exists.'], 400);
            }

            $stmt = $pdo->prepare('UPDATE packings SET name = ?, weight = ? WHERE id = ?');
            $stmt->execute([$name, $weight, $id]);

            // Keep denormalized inventory.packing name in sync
            $sync = $pdo->prepare('UPDATE inventory SET packing = ? WHERE packing_id = ?');
            $sync->execute([$name, $id]);

            sendJson(['ok' => true]);
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid packing id.'], 400);
            }
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM inventory WHERE packing_id = ?');
            $countStmt->execute([$id]);
            if ((int) $countStmt->fetchColumn() > 0) {
                sendJson(['ok' => false, 'message' => 'Cannot delete packing that is used by inventory.'], 400);
            }
            $stmt = $pdo->prepare('DELETE FROM packings WHERE id = ?');
            $stmt->execute([$id]);
            sendJson(['ok' => true]);
            break;

        default:
            sendJson(['ok' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    sendJson(['ok' => false, 'message' => $e->getMessage()], 500);
}
