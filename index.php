<?php
/**
 * Younas CRM Backend Router
 * Main entry point for the API (main domain + factory subdomain).
 */

require_once __DIR__ . '/db.php';

sendCorsHeaders();

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Parse Request URI to get the route
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptName = dirname($_SERVER['SCRIPT_NAME']);

// Remove script name from URI to get the relative route
$route = str_replace($scriptName, '', $requestUri);
$route = trim($route, '/');

// If no route, show health status
if (empty($route) || $route === 'index.php') {
    $dbConfig = getDbConfigForHost();
    sendJson([
        'ok' => true,
        'status' => 'online',
        'message' => 'Younas CRM Backend API is active',
        'version' => '1.0.0',
        'host' => $_SERVER['HTTP_HOST'] ?? null,
        'tenantHost' => resolveRequestHost(),
        'tenant' => $dbConfig['label'],
        'database' => $dbConfig['dbName'],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

// Define available routes and their corresponding files
$routes = [
    'sales'             => 'sales.php',
    'ledger'            => 'ledger.php',
    'parties'           => 'parties.php',
    'cities'            => 'cities.php',
    'packings'          => 'packings.php',
    'banks'             => 'banks.php',
    'payments'          => 'payments.php',
    'inventory'         => 'inventory.php',
    'invoices'          => 'invoices.php',
    'stock'             => 'stock.php',
    'bank-transactions' => 'bank_transactions.php',
    'expense-categories'  => 'expense_categories.php',
    'expenses'            => 'expenses.php',
    'purchase-orders'     => 'purchase_orders.php',
    'delivery-orders'     => 'delivery_orders.php',
    'sale-returns'        => 'sale_returns.php',
    'sale_returns'        => 'sale_returns.php',
    'auth'                => 'auth.php',
];

// Check if route exists in the map
if (isset($routes[$route])) {
    require_once __DIR__ . '/' . $routes[$route];
} else {
    // Attempt to load the file directly if it exists (e.g., /ledger)
    $filePath = __DIR__ . '/' . $route . '.php';
    if (file_exists($filePath)) {
        require_once $filePath;
    } else {
        sendJson([
            'ok' => false,
            'message' => 'Route not found: ' . $route,
            'requested_path' => $requestUri
        ], 404);
    }
}
