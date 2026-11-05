<?php

/**
 * DB credentials — Hostinger (upload THIS file to live server).
 *
 * Local XAMPP: cli-server / localhost / 127.0.0.1 → root + younas_db (etc.)
 *
 * younissonsindustry.com          → main DB
 * factory.younissonsindustry.com  → factory DB
 * pre.younissonsindustry.com      → pre DB (u648321227_pre_db)
 */
function normalizeHost($host) {
    $host = strtolower(trim((string) $host));
    $host = preg_replace('/:\d+$/', '', $host);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    return $host;
}

function isLocalEnvironment() {
    if (PHP_SAPI === 'cli-server') {
        return true;
    }
    $host = normalizeHost($_SERVER['HTTP_HOST'] ?? '');
    return $host === 'localhost' || $host === '127.0.0.1';
}

function getTenantDatabases() {
    if (isLocalEnvironment()) {
        // XAMPP MySQL: root / (empty password)
        return [
            'younissonsindustry.com' => [
                'host'     => '127.0.0.1',
                'dbName'   => 'younas_db',
                'username' => 'root',
                'password' => '',
                'label'    => 'main',
            ],
            'factory.younissonsindustry.com' => [
                'host'     => '127.0.0.1',
                'dbName'   => 'younas_factory',
                'username' => 'root',
                'password' => '',
                'label'    => 'factory',
            ],
            'pre.younissonsindustry.com' => [
                'host'     => '127.0.0.1',
                'dbName'   => 'younas_pre',
                'username' => 'root',
                'password' => '',
                'label'    => 'pre',
            ],
        ];
    }

    return [
        // Main — younissonsindustry.com
        'younissonsindustry.com' => [
            'host'     => 'localhost',
            'dbName'   => 'u648321227_younas_db',
            'username' => 'u648321227_younas',
            'password' => 'YounasDB123@',
            'label'    => 'main',
        ],
        // Factory — factory.younissonsindustry.com
        'factory.younissonsindustry.com' => [
            'host'     => 'localhost',
            'dbName'   => 'u648321227_factory',
            'username' => 'u648321227_factory',
            'password' => 'H8|b/+!6Z4u:',
            'label'    => 'factory',
        ],
        // Pre / Preview — pre.younissonsindustry.com
        'pre.younissonsindustry.com' => [
            'host'     => 'localhost',
            'dbName'   => 'u648321227_pre_db',
            'username' => 'u648321227_pre',
            'password' => '+3Hw89kdHgo',
            'label'    => 'pre',
        ],
    ];
}

function resolveRequestHost() {
    $headerHost = $_SERVER['HTTP_X_TENANT_HOST'] ?? '';
    if ($headerHost !== '') {
        return normalizeHost($headerHost);
    }

    $label = strtolower(trim($_SERVER['HTTP_X_TENANT_LABEL'] ?? ''));
    if ($label === 'factory') {
        return 'factory.younissonsindustry.com';
    }
    if ($label === 'pre') {
        return 'pre.younissonsindustry.com';
    }
    if ($label === 'main') {
        return 'younissonsindustry.com';
    }

    return normalizeHost($_SERVER['HTTP_HOST'] ?? '');
}

function getDbConfigForHost() {
    $databases = getTenantDatabases();
    $host = resolveRequestHost();

    if (isset($databases[$host])) {
        return $databases[$host];
    }

    foreach ($databases as $mappedHost => $config) {
        if (normalizeHost($mappedHost) === $host) {
            return $config;
        }
    }

    return $databases['younissonsindustry.com'];
}

function getDbConnection() {
    static $connections = [];

    $config = getDbConfigForHost();
    $cacheKey = $config['label'];

    if (isset($connections[$cacheKey])) {
        return $connections[$cacheKey];
    }

    $dsn = "mysql:host={$config['host']};dbname={$config['dbName']};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ];

    try {
        $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
    } catch (PDOException $e) {
        sendJson([
            'ok' => false,
            'message' => $e->getMessage(),
            'tenant' => $config['label'],
            'dbUser' => $config['username'],
            'dbName' => $config['dbName'],
            'hint' => 'Upload latest backend/db.php to Hostinger if dbUser still shows u487433789_*',
        ], 500);
    }

    try {
        $pdo->exec("ALTER TABLE parties ADD COLUMN opening_balance DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER company_name");
    } catch (PDOException $e) {
        // Column may already exist
    }

    // Soft migrations: Cities + party.city_id
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cities (
              id         INT AUTO_INCREMENT PRIMARY KEY,
              name       VARCHAR(120) NOT NULL,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_cities_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (PDOException $e) {
        // Table may already exist
    }

    try {
        $pdo->exec("ALTER TABLE parties ADD COLUMN city_id INT NULL AFTER company_name");
    } catch (PDOException $e) {
        // Column may already exist
    }

    try {
        $pdo->exec(
            "ALTER TABLE parties
             ADD CONSTRAINT fk_parties_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL"
        );
    } catch (PDOException $e) {
        // FK may already exist
    }

    try {
        $pdo->exec("ALTER TABLE parties ADD INDEX idx_parties_city (city_id)");
    } catch (PDOException $e) {
        // Index may already exist
    }

    // Soft migrations: Packings master + inventory.packing_id
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS packings (
              id         INT AUTO_INCREMENT PRIMARY KEY,
              name       VARCHAR(60)    NOT NULL,
              weight     DECIMAL(12,2)  NOT NULL DEFAULT 0,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_packings_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (PDOException $e) {
        // Table may already exist
    }

    try {
        $pdo->exec(
            "INSERT INTO packings (name, weight)
             SELECT '10kg', 10 FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM packings WHERE LOWER(name) = '10kg')"
        );
        $pdo->exec(
            "INSERT INTO packings (name, weight)
             SELECT '25kg', 25 FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM packings WHERE LOWER(name) = '25kg')"
        );
    } catch (PDOException $e) {
        // Seed may already exist
    }

    try {
        $pdo->exec("ALTER TABLE inventory MODIFY COLUMN packing VARCHAR(60) NOT NULL DEFAULT '10kg'");
    } catch (PDOException $e) {
        // Column may already be VARCHAR
    }

    try {
        $pdo->exec("ALTER TABLE inventory ADD COLUMN packing_id INT NULL AFTER packing");
    } catch (PDOException $e) {
        // Column may already exist
    }

    try {
        $pdo->exec(
            "UPDATE inventory i
             INNER JOIN packings p ON LOWER(p.name) = LOWER(i.packing)
             SET i.packing_id = p.id
             WHERE i.packing_id IS NULL"
        );
    } catch (PDOException $e) {
        // Backfill may fail on empty table
    }

    try {
        $pdo->exec(
            "ALTER TABLE inventory
             ADD CONSTRAINT fk_inventory_packing FOREIGN KEY (packing_id) REFERENCES packings(id) ON DELETE RESTRICT"
        );
    } catch (PDOException $e) {
        // FK may already exist
    }

    try {
        $pdo->exec("ALTER TABLE inventory ADD INDEX idx_inventory_packing (packing_id)");
    } catch (PDOException $e) {
        // Index may already exist
    }

    // Soft migrations: Purchase Orders + Delivery Orders (safe if already exist)
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS purchase_orders (
              id            INT AUTO_INCREMENT PRIMARY KEY,
              party_id      INT            NOT NULL,
              order_date    DATE           NOT NULL,
              status        ENUM('Draft','Ordered','Partial','Received','Cancelled') NOT NULL DEFAULT 'Draft',
              remarks       TEXT           NULL,
              total_amount  DECIMAL(15,2)  NOT NULL DEFAULT 0,
              stock_applied TINYINT(1)     NOT NULL DEFAULT 0,
              created_at    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
              updated_at    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_po_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE RESTRICT,
              INDEX idx_po_party (party_id),
              INDEX idx_po_date (order_date),
              INDEX idx_po_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS purchase_order_items (
              id                INT AUTO_INCREMENT PRIMARY KEY,
              purchase_order_id INT            NOT NULL,
              inventory_id      INT            NOT NULL,
              quantity          DECIMAL(12,2)  NOT NULL DEFAULT 0,
              rate              DECIMAL(15,2)  NOT NULL DEFAULT 0,
              amount            DECIMAL(15,2)  NOT NULL DEFAULT 0,
              CONSTRAINT fk_poi_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
              CONSTRAINT fk_poi_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE RESTRICT,
              INDEX idx_poi_po (purchase_order_id),
              INDEX idx_poi_inventory (inventory_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS delivery_orders (
              id                 INT AUTO_INCREMENT PRIMARY KEY,
              party_id           INT            NOT NULL,
              purchase_order_id  INT            NULL,
              delivery_date      DATE           NOT NULL,
              status             ENUM('Pending','Dispatched','Delivered','Cancelled') NOT NULL DEFAULT 'Pending',
              remarks            TEXT           NULL,
              total_amount       DECIMAL(15,2)  NOT NULL DEFAULT 0,
              stock_applied      TINYINT(1)     NOT NULL DEFAULT 0,
              created_at         TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
              updated_at         TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_do_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE RESTRICT,
              CONSTRAINT fk_do_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL,
              INDEX idx_do_party (party_id),
              INDEX idx_do_po (purchase_order_id),
              INDEX idx_do_date (delivery_date),
              INDEX idx_do_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS delivery_order_items (
              id                 INT AUTO_INCREMENT PRIMARY KEY,
              delivery_order_id  INT            NOT NULL,
              inventory_id       INT            NOT NULL,
              quantity           DECIMAL(12,2)  NOT NULL DEFAULT 0,
              rate               DECIMAL(15,2)  NOT NULL DEFAULT 0,
              amount             DECIMAL(15,2)  NOT NULL DEFAULT 0,
              CONSTRAINT fk_doi_do FOREIGN KEY (delivery_order_id) REFERENCES delivery_orders(id) ON DELETE CASCADE,
              CONSTRAINT fk_doi_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE RESTRICT,
              INDEX idx_doi_do (delivery_order_id),
              INDEX idx_doi_inventory (inventory_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (PDOException $e) {
        // Tables / FKs may already exist
    }

    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN satisfaction_rating DECIMAL(2, 1) NULL AFTER payment_type");
    } catch (PDOException $e) {
        // Column may already exist
    }

    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN sale_date DATE NULL AFTER satisfaction_rating");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE sales ADD COLUMN sale_date DATE NULL AFTER payment_type");
        } catch (PDOException $e2) {
            // Column may already exist
        }
    }

    try {
        $pdo->exec(
            "UPDATE sales SET sale_date = DATE(created_at) WHERE sale_date IS NULL"
        );
    } catch (PDOException $e) {
        // Backfill may fail before column exists
    }

    // Soft migration: bill grouping (multi-product sale → one bill / one invoice)
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN bill_id INT NULL AFTER id");
    } catch (PDOException $e) {
        // Column may already exist
    }
    try {
        $pdo->exec("ALTER TABLE sales ADD INDEX idx_sales_bill (bill_id)");
    } catch (PDOException $e) {
        // Index may already exist
    }
    try {
        $pdo->exec("UPDATE sales SET bill_id = id WHERE bill_id IS NULL");
    } catch (PDOException $e) {
        // Backfill may fail
    }

    try {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN bill_id INT NULL AFTER id");
    } catch (PDOException $e) {
        // Column may already exist
    }
    try {
        $pdo->exec("ALTER TABLE invoices ADD INDEX idx_invoices_bill (bill_id)");
    } catch (PDOException $e) {
        // Index may already exist
    }
    try {
        $pdo->exec(
            "UPDATE invoices inv
             INNER JOIN sales s ON inv.sale_id = s.id
             SET inv.bill_id = COALESCE(s.bill_id, s.id)
             WHERE inv.bill_id IS NULL"
        );
    } catch (PDOException $e) {
        // Backfill may fail
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS invoice_items (
              id           INT AUTO_INCREMENT PRIMARY KEY,
              invoice_id   INT            NOT NULL,
              sale_id      INT            NOT NULL,
              inventory_id INT            NOT NULL,
              weight       DECIMAL(12,2)  NOT NULL DEFAULT 0,
              price        DECIMAL(15,2)  NOT NULL DEFAULT 0,
              amount       DECIMAL(15,2)  NOT NULL DEFAULT 0,
              created_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_ii_invoice (invoice_id),
              INDEX idx_ii_sale (sale_id),
              INDEX idx_ii_inventory (inventory_id),
              CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
              CONSTRAINT fk_ii_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
              CONSTRAINT fk_ii_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (PDOException $e) {
        // Table / FKs may already exist
    }

    // Soft migration: link payment receipts to invoices (installments)
    try {
        $pdo->exec("ALTER TABLE payment_receipts ADD COLUMN invoice_id INT NULL AFTER party_id");
    } catch (PDOException $e) {
        // Column may already exist
    }
    try {
        $pdo->exec("ALTER TABLE payment_receipts ADD INDEX idx_receipts_invoice (invoice_id)");
    } catch (PDOException $e) {
        // Index may already exist
    }
    try {
        $pdo->exec(
            "ALTER TABLE payment_receipts
             ADD CONSTRAINT fk_receipts_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL"
        );
    } catch (PDOException $e) {
        // FK may already exist
    }
    try {
        $pdo->exec(
            "ALTER TABLE invoices
             MODIFY payment_status ENUM('Pending', 'Partial', 'Received', 'Deferred') NOT NULL DEFAULT 'Pending'"
        );
    } catch (PDOException $e) {
        // Enum may already be updated
    }

    // Soft migration: Sale returns
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sale_returns (
              id           INT AUTO_INCREMENT PRIMARY KEY,
              sale_id      INT            NOT NULL,
              invoice_id   INT            NOT NULL,
              party_id     INT            NOT NULL,
              inventory_id INT            NOT NULL,
              weight       DECIMAL(12,2)  NOT NULL DEFAULT 0,
              price        DECIMAL(15,2)  NOT NULL DEFAULT 0,
              amount       DECIMAL(15,2)  NOT NULL DEFAULT 0,
              return_date  DATE           NOT NULL,
              details      TEXT           NULL,
              created_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
              updated_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_sr_sale (sale_id),
              INDEX idx_sr_invoice (invoice_id),
              INDEX idx_sr_party (party_id),
              INDEX idx_sr_date (return_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (PDOException $e) {
        // Table may already exist
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM sale_returns')->fetchAll(PDO::FETCH_COLUMN);
        if (is_array($cols) && !in_array('stock_id', $cols, true)) {
            $pdo->exec('ALTER TABLE sale_returns ADD COLUMN stock_id INT NULL AFTER details');
        }
    } catch (PDOException $e) {
        // Column may already exist
    }

    try {
        ensureUsersTable($pdo);
    } catch (PDOException $e) {
        // Users table may fail on first connect; auth.php will retry
    }

    $connections[$cacheKey] = $pdo;
    return $pdo;
}

function readJsonInput() {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function sendCorsHeaders() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    } else {
        header('Access-Control-Allow-Origin: *');
    }
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Tenant-Host, X-Tenant-Label, X-Requested-With, X-Auth-Token, Authorization');
    header('Access-Control-Expose-Headers: X-Auth-Token');
    header('Vary: Origin, X-Tenant-Host, X-Tenant-Label');
}

function sendJson($payload, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    sendCorsHeaders();
    echo json_encode($payload);
    exit();
}

function defaultTenantAuth(string $tenantLabel): array
{
    $label = strtolower(trim($tenantLabel));
    if ($label === 'factory') {
        return [
            'username' => 'factory@gmail.com',
            'password' => 'factory123@',
            'display_name' => 'Factory Admin',
        ];
    }

    // main + pre (Younis)
    return [
        'username' => 'younis@gmail.com',
        'password' => 'younis123@',
        'display_name' => 'Younis Admin',
    ];
}

function ensureUsersTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
          id            INT AUTO_INCREMENT PRIMARY KEY,
          username      VARCHAR(120) NOT NULL,
          password_hash VARCHAR(255) NOT NULL,
          display_name  VARCHAR(120) NULL,
          is_active     TINYINT(1)   NOT NULL DEFAULT 1,
          created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
          updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_users_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    try {
        $pdo->exec('ALTER TABLE users MODIFY username VARCHAR(120) NOT NULL');
    } catch (PDOException $e) {
        // Column may already be wide enough
    }

    $tenantLabel = (string) (getDbConfigForHost()['label'] ?? 'main');
    $creds = defaultTenantAuth($tenantLabel);
    $username = $creds['username'];
    $password = $creds['password'];
    $displayName = $creds['display_name'];
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $find = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
    $find->execute([$username]);
    $existing = $find->fetch();

    if (!$existing) {
        $ins = $pdo->prepare(
            'INSERT INTO users (username, password_hash, display_name, is_active) VALUES (?, ?, ?, 1)'
        );
        $ins->execute([$username, $hash, $displayName]);
    } else {
        $pdo->prepare('UPDATE users SET display_name = ?, is_active = 1 WHERE id = ?')
            ->execute([$displayName, (int) $existing['id']]);
    }

    // Legacy default login — disable so only tenant emails work
    $pdo->exec("UPDATE users SET is_active = 0 WHERE LOWER(username) = 'admin'");
}

/** Max authenticated session age in seconds (1 day) */
function authMaxAgeSeconds(): int
{
    return 86400;
}

function destroyAppSession(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }
}

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $token = '';
    if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        $token = trim((string) $_SERVER['HTTP_X_AUTH_TOKEN']);
    } elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(\S+)/i', (string) $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $token = trim($m[1]);
        }
    }

    // Server-side session files expire after 1 day
    ini_set('session.gc_maxlifetime', (string) authMaxAgeSeconds());

    session_name('YSSESSID');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0, // browser session cookie (tab/browser close)
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if ($token !== '' && preg_match('/^[a-zA-Z0-9,-]{16,128}$/', $token)) {
        session_id($token);
    }

    session_start();
}

function currentAuthUser(): ?array
{
    startAppSession();
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $loginAt = (int) ($_SESSION['login_at'] ?? 0);
    if ($loginAt <= 0 || (time() - $loginAt) > authMaxAgeSeconds()) {
        destroyAppSession();
        return null;
    }

    return [
        'id' => (int) $_SESSION['user_id'],
        'username' => (string) ($_SESSION['username'] ?? ''),
        'displayName' => (string) ($_SESSION['display_name'] ?? $_SESSION['username'] ?? ''),
    ];
}

function requireAuth(): void
{
    $user = currentAuthUser();
    if ($user === null) {
        sendJson([
            'ok' => false,
            'message' => 'Login required. Please sign in to continue.',
            'code' => 'AUTH_REQUIRED',
        ], 403);
    }
}

function authPublicScriptName(): string
{
    return basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
}

function isAuthPublicEndpoint(): bool
{
    $script = authPublicScriptName();
    if ($script === 'auth.php') {
        return true;
    }

    $uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $uri = strtolower(rtrim($uri, '/'));
    if ($uri !== '' && preg_match('#/(auth)(\.php)?$#', $uri)) {
        return true;
    }

    return false;
}

// Soft migrate users whenever a DB connection is opened
// (called from getDbConnection via ensureUsersTable below — hooked in getDbConnection)

// ── Global API gate: every endpoint that includes db.php needs login ──
// Exception: auth.php (login / logout / me)
if (PHP_SAPI !== 'cli') {
    sendCorsHeaders();
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
    if (!isAuthPublicEndpoint()) {
        requireAuth();
    }
}
