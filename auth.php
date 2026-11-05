<?php
/**
 * Auth API — login / logout / me / changePassword
 * Public endpoint (db.php skips requireAuth for this script).
 */
require_once __DIR__ . '/db.php';

function mapAuthUser(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'displayName' => (string) ($row['display_name'] ?: $row['username']),
    ];
}

try {
    $pdo = getDbConnection();
    ensureUsersTable($pdo);
    $input = readJsonInput();
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'login':
            $username = trim((string) ($input['username'] ?? $input['payload']['username'] ?? ''));
            $password = (string) ($input['password'] ?? $input['payload']['password'] ?? '');

            if ($username === '' || $password === '') {
                sendJson(['ok' => false, 'message' => 'Username and password are required.'], 400);
            }

            $stmt = $pdo->prepare(
                'SELECT id, username, password_hash, display_name, is_active
                 FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1'
            );
            $stmt->execute([$username]);
            $row = $stmt->fetch();

            if (!$row || !(int) $row['is_active']) {
                sendJson(['ok' => false, 'message' => 'Invalid username or password.'], 401);
            }
            if (!password_verify($password, $row['password_hash'])) {
                sendJson(['ok' => false, 'message' => 'Invalid username or password.'], 401);
            }

            startAppSession();
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $row['id'];
            $_SESSION['username'] = (string) $row['username'];
            $_SESSION['display_name'] = (string) ($row['display_name'] ?: $row['username']);
            $_SESSION['login_at'] = time();

            $user = mapAuthUser($row);
            sendJson([
                'ok' => true,
                'data' => [
                    'token' => session_id(),
                    'user' => $user,
                ],
            ]);
            break;

        case 'logout':
            startAppSession();
            destroyAppSession();
            sendJson(['ok' => true, 'data' => null]);
            break;

        case 'me':
            startAppSession();
            $authUser = currentAuthUser();
            if ($authUser === null) {
                sendJson([
                    'ok' => false,
                    'message' => 'Login required.',
                    'code' => 'AUTH_REQUIRED',
                ], 403);
            }

            $stmt = $pdo->prepare(
                'SELECT id, username, display_name, is_active FROM users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([(int) $authUser['id']]);
            $row = $stmt->fetch();
            if (!$row || !(int) $row['is_active']) {
                destroyAppSession();
                sendJson([
                    'ok' => false,
                    'message' => 'Login required.',
                    'code' => 'AUTH_REQUIRED',
                ], 403);
            }

            sendJson([
                'ok' => true,
                'data' => [
                    'token' => session_id(),
                    'user' => mapAuthUser($row),
                ],
            ]);
            break;

        case 'changePassword':
            requireAuth();
            $currentPassword = (string) ($input['currentPassword'] ?? $input['payload']['currentPassword'] ?? '');
            $newPassword = (string) ($input['newPassword'] ?? $input['payload']['newPassword'] ?? '');

            if ($currentPassword === '' || $newPassword === '') {
                sendJson(['ok' => false, 'message' => 'Current and new password are required.'], 400);
            }
            if (strlen($newPassword) < 6) {
                sendJson(['ok' => false, 'message' => 'New password must be at least 6 characters.'], 400);
            }

            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
                sendJson(['ok' => false, 'message' => 'Current password is incorrect.'], 400);
            }

            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
            sendJson(['ok' => true, 'message' => 'Password updated.']);
            break;

        default:
            sendJson(['ok' => false, 'message' => 'Invalid action. Use login, logout, me, or changePassword.'], 400);
    }
} catch (Throwable $e) {
    sendJson(['ok' => false, 'message' => $e->getMessage()], 500);
}
