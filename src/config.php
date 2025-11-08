<?php
// Configuration and environment-driven defaults

$openaiApiKey = getenv('OPENAI_API_KEY') ?: '';
$dbHost = getenv('DB_HOST') ?: 'mysql';
$dbName = getenv('DB_DATABASE') ?: 'sales_db';
$dbUser = getenv('DB_USERNAME') ?: 'root';
$dbPass = getenv('DB_PASSWORD') ?: 'example';
$openaiModel = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';

// Logging and rate limiting config
$logFile = getenv('LOG_FILE') ?: __DIR__ . '/../storage/app.log';
$rateLimitWindowSec = (int)(getenv('RATE_LIMIT_WINDOW_SEC') !== false ? getenv('RATE_LIMIT_WINDOW_SEC') : 60);
$rateLimitMaxRequests = (int)(getenv('RATE_LIMIT_MAX_REQUESTS') !== false ? getenv('RATE_LIMIT_MAX_REQUESTS') : 20);

// Pagination
$defaultPageSize = (int)(getenv('PAGE_SIZE') !== false ? getenv('PAGE_SIZE') : 50);

// Whitelist tables and write permissions (adjust as needed)
// Default allowlist (can be overridden via EXPOSED_TABLES_JSON)
$exposedTables = [
    'sales' => [
        'read' => true,
        'write' => [
            'insert' => ['item_name','quantity','sold_at'],
            'update' => ['quantity','sold_at'],
            'delete' => false,
        ]
    ],
    'products' => [
        'read' => true,
        'write' => [
            'insert' => ['name','sku','price','description','category','stock'],
            'update' => ['price','description','category','stock','name','sku'],
            'delete' => false,
        ]
    ]
];

// Env-driven overrides
$maxRows = (int)(getenv('MAX_ROWS') !== false ? getenv('MAX_ROWS') : 100); // default limit for reads
$requireWriteConfirmation = (function(){
    $v = getenv('REQUIRE_WRITE_CONFIRMATION');
    if ($v === false || $v === '') return true; // default on
    $v = strtolower((string)$v);
    return !in_array($v, ['0','false','no','off'], true);
})();

// Optional JSON config for allowlist
$exposedJson = getenv('EXPOSED_TABLES_JSON');
if (!empty($exposedJson)) {
    try {
        $cfg = json_decode($exposedJson, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($cfg) && $cfg) {
            $exposedTables = $cfg;
        }
    } catch (Throwable $e) {
        // ignore invalid JSON; keep defaults
    }
}

