<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJson(['ok' => true]);
}

function partyMapRow(array $row): array {
    return [
        'id' => (string) $row['id'],
        'name' => $row['name'],
        'email' => $row['email'] ?? '',
        'phone' => $row['phone'] ?? '',
        'companyName' => $row['company_name'] ?? '',
        'cityId' => $row['city_id'] ? (int) $row['city_id'] : null,
        'cityName' => $row['city_name'] ?? '',
        'openingBalance' => (float) ($row['opening_balance'] ?? 0),
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at']
    ];
}

function resolvePartyCityId(PDO $pdo, $payload): ?int {
    $cityId = !empty($payload['cityId']) ? (int) $payload['cityId'] : null;
    if (!$cityId || $cityId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id FROM cities WHERE id = ?');
    $stmt->execute([$cityId]);
    if (!$stmt->fetch()) {
        throw new RuntimeException('Selected city not found.');
    }
    return $cityId;
}

try {
    $pdo = getDbConnection();
    $input = readJsonInput();
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'list':
            $rows = $pdo->query(
                'SELECT p.id, p.name, p.email, p.phone, p.company_name, p.city_id, c.name AS city_name,
                        p.opening_balance, p.created_at, p.updated_at
                 FROM parties p
                 LEFT JOIN cities c ON p.city_id = c.id
                 ORDER BY p.id DESC'
            )->fetchAll();
            $data = array_map('partyMapRow', $rows);
            sendJson(['ok' => true, 'data' => $data]);
            break;

        case 'get':
            $id = (int) ($input['id'] ?? 0);
            $stmt = $pdo->prepare(
                'SELECT p.id, p.name, p.email, p.phone, p.company_name, p.city_id, c.name AS city_name,
                        p.opening_balance, p.created_at, p.updated_at
                 FROM parties p
                 LEFT JOIN cities c ON p.city_id = c.id
                 WHERE p.id = ?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                sendJson(['ok' => true, 'data' => null]);
            }
            sendJson(['ok' => true, 'data' => partyMapRow($row)]);
            break;

        case 'create':
            $payload = $input['payload'] ?? [];
            $name = trim((string) ($payload['name'] ?? ''));
            $email = trim((string) ($payload['email'] ?? ''));
            $openingBalance = (float) ($payload['openingBalance'] ?? 0);
            if ($name === '') {
                sendJson(['ok' => false, 'message' => 'Party name is required.'], 400);
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                sendJson(['ok' => false, 'message' => 'Invalid email format.'], 400);
            }
            $cityId = resolvePartyCityId($pdo, $payload);
            $stmt = $pdo->prepare(
                'INSERT INTO parties (name, email, phone, company_name, city_id, opening_balance)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $name,
                $email,
                trim((string) ($payload['phone'] ?? '')),
                trim((string) ($payload['companyName'] ?? '')),
                $cityId,
                $openingBalance
            ]);
            sendJson(['ok' => true, 'data' => ['id' => (string) $pdo->lastInsertId()]]);
            break;

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            $payload = $input['payload'] ?? [];
            $name = trim((string) ($payload['name'] ?? ''));
            $email = trim((string) ($payload['email'] ?? ''));
            $openingBalance = (float) ($payload['openingBalance'] ?? 0);
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid party id.'], 400);
            }
            if ($name === '') {
                sendJson(['ok' => false, 'message' => 'Party name is required.'], 400);
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                sendJson(['ok' => false, 'message' => 'Invalid email format.'], 400);
            }
            $cityId = resolvePartyCityId($pdo, $payload);
            $stmt = $pdo->prepare(
                'UPDATE parties
                 SET name = ?, email = ?, phone = ?, company_name = ?, city_id = ?, opening_balance = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $name,
                $email,
                trim((string) ($payload['phone'] ?? '')),
                trim((string) ($payload['companyName'] ?? '')),
                $cityId,
                $openingBalance,
                $id
            ]);
            sendJson(['ok' => true]);
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['ok' => false, 'message' => 'Invalid party id.'], 400);
            }
            $stmt = $pdo->prepare('DELETE FROM parties WHERE id = ?');
            $stmt->execute([$id]);
            sendJson(['ok' => true]);
            break;

        default:
            sendJson(['ok' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    sendJson(['ok' => false, 'message' => $e->getMessage()], 500);
}
