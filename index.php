<?php
require 'vendor/autoload.php';

// Simple, production-leaning NL → DB assistant with:
// - Single textbox for read/write
// - Dynamic schema RAG over whitelisted tables
// - Strict write controls (insert/update only by default) with confirmation
// - Parameterized statements for safety

// -------------------------
// Configuration
// -------------------------
$openaiApiKey = getenv('OPENAI_API_KEY') ?: '';
$dbHost = getenv('DB_HOST') ?: 'mysql';
$dbName = getenv('DB_DATABASE') ?: 'sales_db';
$dbUser = getenv('DB_USERNAME') ?: 'root';
$dbPass = getenv('DB_PASSWORD') ?: 'example';
$openaiModel = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';
// Logging and rate limiting config
$logFile = getenv('LOG_FILE') ?: __DIR__ . '/storage/app.log';
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

// -------------------------
// Bootstrap
// -------------------------
session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbName);
$pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Optional: ensure products table exists to smooth local dx when volume was created before seeding
function ensureProductsTable(PDO $pdo): void {
    $sql = "CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        sku VARCHAR(100) NOT NULL UNIQUE,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        description TEXT NULL,
        category VARCHAR(100) NOT NULL DEFAULT 'general',
        stock INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try { $pdo->exec($sql); } catch (Throwable $e) { /* ignore */ }
}

function askOpenAI(array $messages, string $model) {
    global $openaiApiKey;
    if (empty($openaiApiKey)) {
        throw new Exception('OPENAI_API_KEY is not set');
    }
    $client = \OpenAI::client($openaiApiKey);
    $response = $client->chat()->create([
        'model' => $model,
        'messages' => $messages
    ]);
    return $response->choices[0]->message->content;
}

function ensureDir(string $path): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
}

function log_event(string $type, array $context = []): void {
    global $logFile;
    try {
        ensureDir($logFile);
        $entry = [
            'ts' => gmdate('c'),
            'type' => $type,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
            'ctx' => $context,
        ];
        $line = json_encode($entry) . PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // ignore logging failures
    }
}

function rate_limit_or_die(): void {
    global $rateLimitWindowSec, $rateLimitMaxRequests;
    if ($rateLimitMaxRequests <= 0) return; // disabled
    $ip = preg_replace('/[^0-9a-fA-F:\.]/', '', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rag_rate_' . md5($ip) . '.json';
    $now = time();
    $data = [];
    $fp = @fopen($file, 'c+');
    if ($fp) {
        if (@flock($fp, LOCK_EX)) {
            $raw = stream_get_contents($fp);
            $data = json_decode($raw ?: '[]', true) ?: [];
            $data = array_values(array_filter($data, function($t) use ($now, $rateLimitWindowSec){ return is_int($t) && $t > $now - $rateLimitWindowSec; }));
            if (count($data) >= $rateLimitMaxRequests) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
                http_response_code(429);
                echo '<!doctype html><meta charset="utf-8"><title>Rate limit</title><p style="font-family:system-ui,Arial">Too many requests. Please wait a moment and try again.</p>';
                log_event('rate_limited', ['ip'=>$ip,'count'=>count($data),'window_sec'=>$rateLimitWindowSec]);
                exit;
            }
            $data[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);
            @flock($fp, LOCK_UN);
            @fclose($fp);
        } else {
            @fclose($fp);
        }
    }
}

function require_csrf_or_die(): void {
    $token = $_POST['csrf_token'] ?? '';
    $valid = isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
    if (!$valid) {
        http_response_code(400);
        echo '<!doctype html><meta charset="utf-8"><title>Bad Request</title><p style="font-family:system-ui,Arial">Invalid or missing CSRF token.</p>';
        exit;
    }
}

function loadSchema(PDO $pdo, string $dbName, array $tables): array {
    if (!$tables) return ['schema'=>[], 'text'=>''];
    $inPlaceholders = implode(',', array_fill(0, count($tables), '?'));
    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($inPlaceholders)
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    $params = array_merge([$dbName], array_keys($tables));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $schema = [];
    foreach ($rows as $r) {
        $t = $r['TABLE_NAME'];
        if (!isset($schema[$t])) $schema[$t] = ['columns' => []];
        $schema[$t]['columns'][] = [
            'name' => $r['COLUMN_NAME'],
            'type' => $r['DATA_TYPE'],
            'nullable' => $r['IS_NULLABLE'] === 'YES',
            'default' => $r['COLUMN_DEFAULT'],
            'key' => $r['COLUMN_KEY'],
            'extra' => $r['EXTRA'],
        ];
    }

    $textParts = [];
    foreach ($schema as $table => $info) {
        $colStrs = array_map(function($c) {
            $bits = [$c['name'] . ' ' . $c['type']];
            $bits[] = $c['nullable'] ? 'NULL' : 'NOT NULL';
            if (!is_null($c['default'])) $bits[] = 'DEFAULT ' . $c['default'];
            if ($c['key'] === 'PRI') $bits[] = 'PRIMARY KEY';
            if ($c['key'] === 'UNI') $bits[] = 'UNIQUE';
            if (!empty($c['extra'])) $bits[] = $c['extra'];
            return implode(' ', $bits);
        }, $info['columns']);
        $textParts[] = sprintf("Table '%s' columns:\n- %s", $table, implode("\n- ", $colStrs));
    }

    return [
        'schema' => $schema,
        'text' => implode("\n\n", $textParts)
    ];
}

function tableCoverage(PDO $pdo, string $table, string $dateCol): ?array {
    try {
        $t = str_replace('`','``',$table);
        $c = str_replace('`','``',$dateCol);
        $sql = "SELECT MIN(`$c`) AS min_date, MAX(`$c`) AS max_date FROM `$t`";
        $row = $pdo->query($sql)->fetch();
        if (!$row || (!$row['min_date'] && !$row['max_date'])) return null;
        return ['min' => $row['min_date'], 'max' => $row['max_date']];
    } catch (Throwable $e) {
        return null;
    }
}

function extractJsonPayload(string $text): ?array {
    $raw = trim($text);
    if (preg_match('/```json\s*(\{[\s\S]*?\})\s*```/i', $raw, $m)) {
        $raw = $m[1];
    } elseif (preg_match('/```\s*(\{[\s\S]*?\})\s*```/i', $raw, $m)) {
        $raw = $m[1];
    } elseif (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
        $raw = $m[0];
    }
    $data = json_decode($raw ?? '', true);
    return is_array($data) ? $data : null;
}

function guardSelect(string $sql, int $maxRows): string {
    if (!preg_match('/^\s*SELECT\b/i', $sql)) {
        throw new Exception('Only SELECT allowed for read operations');
    }
    if (preg_match('/;\s*\S/', $sql)) {
        throw new Exception('Multiple statements not allowed');
    }
    if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) {
        $sql .= ' LIMIT ' . (int)$maxRows;
    }
    return $sql;
}

function validateWrite(array $op, array $exposedTables, array $schema): array {
    // op: { type: insert|update, table: string, values|set: {col=>val}, where_equals?: {col=>val} }
    if (empty($op['type']) || empty($op['table'])) throw new Exception('Invalid operation payload');
    $type = strtolower((string)$op['type']);
    $table = (string)$op['table'];
    if (!isset($exposedTables[$table])) throw new Exception('Table not allowed: ' . htmlspecialchars($table));
    $rules = $exposedTables[$table]['write'] ?? [];
    if (!in_array($type, ['insert','update'], true)) throw new Exception('Write type not permitted');

    if ($type === 'insert') {
        $allowed = $rules['insert'] ?? [];
        $values = $op['values'] ?? [];
        if (!is_array($values) || !$values) throw new Exception('Insert values missing');
        foreach ($values as $col => $_) {
            if (!in_array($col, $allowed, true)) throw new Exception('Column not allowed for insert: ' . $col);
        }
        return ['type'=>'insert','table'=>$table,'values'=>$values];
    }

    if ($type === 'update') {
        $allowed = $rules['update'] ?? [];
        $set = $op['set'] ?? [];
        $where = $op['where_equals'] ?? [];
        if (!is_array($set) || !$set) throw new Exception('Update set missing');
        foreach ($set as $col => $_) {
            if (!in_array($col, $allowed, true)) throw new Exception('Column not allowed for update: ' . $col);
        }
        if (!is_array($where) || !$where) throw new Exception('Update must include where_equals');
        return ['type'=>'update','table'=>$table,'set'=>$set,'where_equals'=>$where];
    }

    throw new Exception('Unsupported write');
}

function execInsert(PDO $pdo, string $table, array $values): array {
    $cols = array_keys($values);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $colList = implode('`, `', array_map(fn($c) => str_replace('`','``',$c), $cols));
    $sql = "INSERT INTO `" . str_replace('`','``',$table) . "` (`$colList`) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($values));
    $id = $pdo->lastInsertId();
    try {
        $sel = $pdo->prepare("SELECT * FROM `" . str_replace('`','``',$table) . "` WHERE id = ?");
        $sel->execute([$id]);
        $row = $sel->fetch();
        return ['inserted_id'=>$id,'row'=>$row];
    } catch (Throwable $e) {
        return ['inserted_id'=>$id];
    }
}

function execUpdate(PDO $pdo, string $table, array $set, array $whereEq): array {
    $setCols = array_keys($set);
    $whereCols = array_keys($whereEq);
    $setExpr = implode(', ', array_map(fn($c) => '`' . str_replace('`','``',$c) . '` = ?', $setCols));
    $whereExpr = implode(' AND ', array_map(fn($c) => '`' . str_replace('`','``',$c) . '` = ?', $whereCols));
    $sql = "UPDATE `" . str_replace('`','``',$table) . "` SET $setExpr WHERE $whereExpr";
    $stmt = $pdo->prepare($sql);
    $params = array_merge(array_values($set), array_values($whereEq));
    $stmt->execute($params);
    return ['affected_rows'=>$stmt->rowCount()];
}

// -------------------------
// Build schema context
// -------------------------
$schemaBlock = '';
$schema = [];
$coverageHint = '';
try {
    if (isset($exposedTables['products'])) { ensureProductsTable($pdo); }
    $ctx = loadSchema($pdo, $dbName, $exposedTables);
    $schema = $ctx['schema'];
    $schemaBlock = $ctx['text'];
    // Append data coverage hints for time-aware queries
    $covParts = [];
    if (isset($exposedTables['sales'])) {
        $cov = tableCoverage($pdo, 'sales', 'sold_at');
        if ($cov) {
            $covParts[] = "Data coverage for 'sales' (sold_at): " . $cov['min'] . " → " . $cov['max'];
            $coverageHint = $covParts[0];
        }
    }
    if (isset($exposedTables['products'])) {
        $cov = tableCoverage($pdo, 'products', 'created_at');
        if ($cov) { $covParts[] = "Data coverage for 'products' (created_at): " . $cov['min'] . " → " . $cov['max']; }
    }
    if ($covParts) {
        $schemaBlock .= "\n\n" . implode("\n", $covParts);
    }
} catch (Throwable $e) {
    $schemaBlock = '(Failed to load schema: ' . htmlspecialchars($e->getMessage()) . ')';
}

// -------------------------
// Request handling
// -------------------------
$nl = trim($_POST['nl'] ?? '');
$confirmWrite = isset($_POST['confirm_write']);
$proposedOpJson = $_POST['proposed_op'] ?? '';
// CSV download handling
$downloadCsv = isset($_POST['download_csv']);
// Pagination handling
$paginate = isset($_POST['paginate']);
$page = max(1, (int)($_POST['page'] ?? 1));
$pageSize = (int)($_POST['page_size'] ?? $defaultPageSize);
if ($pageSize < 1) $pageSize = $defaultPageSize; if ($pageSize > 500) $pageSize = 500;
$totalCount = null;

$modeTag = '';
$feedback = '';
$resultPayload = null;
$proposedOp = null;
$executedOp = null;
$provReason = '';
$provSql = '';
$errorRaw = '';

if ($downloadCsv) {
    require_csrf_or_die();
    rate_limit_or_die();
    $rows = $_SESSION['last_result'] ?? [];
    log_event('csv_download', ['rows'=>is_array($rows)?count($rows):0]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=results.csv');
    $out = fopen('php://output', 'w');
    if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
        $cols = [];
        foreach ($rows as $r) { $cols = array_values(array_unique(array_merge($cols, array_keys($r)))); }
        fputcsv($out, $cols);
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $c) {
                $v = $r[$c] ?? '';
                if (is_string($v)) {
                    $trim = ltrim($v);
                    if ($trim !== '' && preg_match('/^[=+\-@]/', $trim)) {
                        $v = "'" . $v; // CSV formula injection mitigation
                    }
                }
                $line[] = $v;
            }
            fputcsv($out, $line);
        }
    }
    fclose($out);
    exit;
} elseif ($confirmWrite && $proposedOpJson !== '') {
    require_csrf_or_die();
    rate_limit_or_die();
    try {
        $op = json_decode($proposedOpJson, true, 512, JSON_THROW_ON_ERROR);
        $validated = validateWrite($op, $exposedTables, $schema);
        log_event('write_apply', ['op'=>$validated]);
        if ($validated['type'] === 'insert') {
            $res = execInsert($pdo, $validated['table'], $validated['values']);
            $modeTag = 'WRITE (insert)';
            $feedback = 'Insert executed successfully.';
            $resultPayload = $res;
            $executedOp = $validated;
            log_event('write_success', ['result'=>$res]);
        } elseif ($validated['type'] === 'update') {
            $res = execUpdate($pdo, $validated['table'], $validated['set'], $validated['where_equals']);
            $modeTag = 'WRITE (update)';
            $feedback = 'Update executed successfully.';
            $resultPayload = $res;
            $executedOp = $validated;
            log_event('write_success', ['result'=>$res]);
        }
    } catch (Throwable $e) {
        $modeTag = 'WRITE';
        // Friendlier DB error explanation
        $msg = $e->getMessage();
        $errorRaw = $msg;
        log_event('write_error', ['error'=>$msg]);
        if (strpos($msg, 'SQLSTATE[23000]') !== false && strpos($msg, '1062') !== false && preg_match("/Duplicate entry '([^']+)' for key '([^']+)'/", $msg, $m)) {
            $dupVal = $m[1];
            $dupKey = $m[2];
            $col = (strpos($dupKey, '.') !== false) ? substr($dupKey, strrpos($dupKey, '.')+1) : $dupKey;
            $feedback = 'Write failed: a record with unique ' . htmlspecialchars($col) . "='" . htmlspecialchars($dupVal) . "' already exists. Consider using an update instead.";
        } else {
            $feedback = 'Write failed: ' . htmlspecialchars($msg);
        }
    }
} elseif ($paginate) {
    require_csrf_or_die();
    rate_limit_or_die();
    $baseSql = $_SESSION['last_base_sql'] ?? '';
    if ($baseSql) {
        $offset = ($page - 1) * $pageSize;
        $sql = 'SELECT * FROM (' . $baseSql . ') AS _sub LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset;
        $provSql = $sql;
        try {
            // Optional total count
            try {
                $cnt = $pdo->query('SELECT COUNT(*) AS c FROM (' . $baseSql . ') AS _c');
                $cRow = $cnt->fetch();
                if ($cRow && isset($cRow['c'])) $totalCount = (int)$cRow['c'];
            } catch (Throwable $e) {}
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            $resultPayload = $rows;
            $_SESSION['last_result'] = $rows;
            $modeTag = 'READ';
            $feedback = $rows ? ('Page ' . $page . ' loaded.') : 'No results on this page.';
            log_event('read_page', ['page'=>$page,'size'=>$pageSize,'rows'=>count($rows)]);
        } catch (Throwable $e) {
            $feedback = 'Pagination failed: ' . htmlspecialchars($e->getMessage());
        }
    } else {
        $feedback = 'Nothing to paginate.';
    }
} elseif ($nl !== '') {
    require_csrf_or_die();
    rate_limit_or_die();
    if (empty($openaiApiKey)) {
        $feedback = 'OPENAI_API_KEY is required for NL processing.';
    } else {
        $rulesSummary = [];
        foreach ($exposedTables as $t => $r) {
            $ins = implode(',', $r['write']['insert'] ?? []);
            $upd = implode(',', $r['write']['update'] ?? []);
            $rulesSummary[] = "$t: read=on, insert=[$ins], update=[$upd], delete=" . (($r['write']['delete']??false)?'on':'off');
        }
        $sys = 'You are a production-grade MySQL 8.0 NL-to-DB planner. '
            . 'Return STRICT JSON only with keys: intent ("read"|"write"), reason (string), '
            . 'For intent="read": sql (single SELECT with MySQL 8.0 syntax, add LIMIT if missing). '
            . 'For intent="write": operation { type ("insert"|"update"), table (string), '
            . 'values (for insert) OR set (for update), where_equals (object with equality-only conditions for update) }. '
            . 'Never include comments or extra keys. Use only exposed tables and columns. '
            . 'Prefer robust time windows (e.g., last 365 days) so results are non-empty even if data is historical; consider data coverage hints provided.';

        $messages = [
            ['role'=>'system', 'content'=>$sys],
            ['role'=>'assistant', 'content'=>"Database schema for grounding:\n" . $schemaBlock],
            ['role'=>'assistant', 'content'=>"Table access rules:\n" . implode("\n", $rulesSummary)],
            ['role'=>'user', 'content'=>'User request: ' . $nl],
        ];

        try {
            log_event('nl_request', ['nl'=>$nl]);
            $raw = askOpenAI($messages, $openaiModel);
            $plan = extractJsonPayload($raw) ?? [];
            $intent = strtolower((string)($plan['intent'] ?? ''));
            $provReason = (string)($plan['reason'] ?? '');
            if ($intent === 'read') {
                $modeTag = 'READ';
                $userSql = (string)($plan['sql'] ?? '');
                $safeSql = guardSelect($userSql, $maxRows);
                // Derive base SQL by stripping trailing LIMIT/OFFSET and semicolons
                $baseSql = trim(rtrim($safeSql, ';'));
                $baseSql = preg_replace('/\s+LIMIT\s+\d+(\s*,\s*\d+|\s+OFFSET\s+\d+)?\s*$/i', '', $baseSql);
                $_SESSION['last_base_sql'] = $baseSql;
                $_SESSION['last_page_size'] = $pageSize;
                $offset = ($page - 1) * $pageSize;
                $sql = 'SELECT * FROM (' . $baseSql . ') AS _sub LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset;
                $provSql = $sql;
                // Optional total count
                try {
                    $cnt = $pdo->query('SELECT COUNT(*) AS c FROM (' . $baseSql . ') AS _c');
                    $cRow = $cnt->fetch();
                    if ($cRow && isset($cRow['c'])) $totalCount = (int)$cRow['c'];
                } catch (Throwable $e) {}
                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                $rows = $stmt->fetchAll();
                $resultPayload = $rows;
                $_SESSION['last_result'] = $rows; // for CSV download
                if ($rows) {
                    // Optional: summarize results briefly
                    try {
                        $sum = askOpenAI([
                            ['role'=>'system','content'=>'Summarize these query results in one brief paragraph.'],
                            ['role'=>'user','content'=>json_encode($rows)]
                        ], $openaiModel);
                        $feedback = $sum;
                    } catch (Throwable $e) {
                        $feedback = 'Query executed. Showing raw results.';
                    }
                    log_event('read_success', ['sql'=>$sql,'rows'=>count($rows)]);
                } else {
                    $hint = $coverageHint ? ($coverageHint . '. ') : '';
                    $feedback = $hint . 'No results. Consider broadening the time range (e.g., last 365 days) or using an explicit historical window like October 2024.';
                    log_event('read_empty', ['sql'=>$sql]);
                }
            } elseif ($intent === 'write') {
                $modeTag = 'WRITE';
                $op = $plan['operation'] ?? null;
                if (!is_array($op)) throw new Exception('Missing operation for write');
                $validated = validateWrite($op, $exposedTables, $schema);

                if ($requireWriteConfirmation) {
                    // Stage confirmation
                    $proposedOp = $validated;
                    $feedback = 'Review and confirm the write operation below.';
                    log_event('write_proposed', ['op'=>$validated]);
                } else {
                    if ($validated['type'] === 'insert') {
                        $res = execInsert($pdo, $validated['table'], $validated['values']);
                        $feedback = 'Insert executed successfully.';
                        $resultPayload = $res;
                        $executedOp = $validated;
                        log_event('write_success', ['result'=>$res]);
                    } else {
                        $res = execUpdate($pdo, $validated['table'], $validated['set'], $validated['where_equals']);
                        $feedback = 'Update executed successfully.';
                        $resultPayload = $res;
                        $executedOp = $validated;
                        log_event('write_success', ['result'=>$res]);
                    }
                }
            } else {
                $modeTag = 'UNSURE';
                $feedback = 'Could not determine intent.';
                log_event('nl_unsure', ['plan'=>$plan]);
            }
        } catch (Throwable $e) {
            $feedback = 'NL processing failed: ' . htmlspecialchars($e->getMessage());
            log_event('nl_error', ['error'=>$e->getMessage()]);
        }
    }
}

// CSV download route (POST-only, after a read populated last_result)
if ($downloadCsv) {
    rate_limit_or_die();
    $rows = $_SESSION['last_result'] ?? [];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=results.csv');
    $out = fopen('php://output', 'w');
    if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
        // Header: union of keys
        $cols = [];
        foreach ($rows as $r) { $cols = array_values(array_unique(array_merge($cols, array_keys($r)))); }
        fputcsv($out, $cols);
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $c) { $line[] = $r[$c] ?? ''; }
            fputcsv($out, $line);
        }
    }
    fclose($out);
    exit;
}

// -------------------------
// UI
// -------------------------
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <title>DB Assistant (Prod)</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
  <style>
    .result { background: #f6f8fa; padding: 12px; border-radius: 8px; border: 1px solid #e5e7eb; white-space: pre-wrap; }
    details { margin-top: 18px; }
  </style>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta http-equiv="Cache-Control" content="no-store" />
  <meta http-equiv="Pragma" content="no-cache" />
  </head>
<body>
  <section class="section">
    <div class="container">
      <h1 class="title">Natural Language DB Assistant</h1>
      <p class="subtitle">Single box for read/write over approved tables. Secure-by-default, with write confirmation.</p>

      <form method="post" class="box">
        <div class="field">
          <label for="nl" class="label">What do you want to do?</label>
          <div class="control">
            <textarea id="nl" name="nl" class="textarea" placeholder="Examples: 
- Top 10 items in last 365 days
- Top items in October 2024
- Add a product called Pixel 9 (SKU PIX9-128-BLK) priced 699.99 with 25 in stock
- Update stock for SKU IP14-128-BLK to 60"></textarea>
          </div>
        </div>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="page" value="<?= (int)$page ?>">
        <input type="hidden" name="page_size" value="<?= (int)$pageSize ?>">
        <div class="field is-grouped is-align-items-center">
          <div class="control">
            <button type="submit" class="button is-primary">Submit</button>
          </div>
          <div class="control">
            <button type="button" id="toggleExamples" class="button is-light is-small">Show examples</button>
          </div>
          <?php if ($modeTag): ?>
            <div class="control" style="margin-left:8px;">
              <span class="tag is-link is-light"><?= htmlspecialchars($modeTag) ?></span>
            </div>
          <?php endif; ?>
        </div>
        <div id="examplesPanel" style="display:none; margin: 6px 0 12px 0;">
          <?php
            $examples = [
              'Top 10 items in last 365 days',
              'Top items in October 2024',
              'List all products with stock < 20',
              'Add a product called Pixel 9 (SKU PIX9-128-BLK) priced 699.99 with 25 in stock',
              'Update stock for SKU IP14-128-BLK to 60',
            ];
          ?>
          <?php foreach ($examples as $ex): ?>
            <button type="button" class="button is-small is-light ex-chip" data-text="<?= htmlspecialchars($ex) ?>" style="margin:4px;">
              <?= htmlspecialchars($ex) ?>
            </button>
          <?php endforeach; ?>
        </div>
      </form>

      <?php if ($feedback): ?>
        <div class="box" style="margin-top:16px;">
          <h3 class="title is-5">Response</h3>
          <div class="content">
            <div class="notification is-light">
              <?= nl2br(htmlspecialchars($feedback)) ?>
            </div>
          </div>
          <?php if ($provReason || $provSql || $executedOp || $proposedOp): ?>
            <details style="margin-top:10px;">
              <summary><strong>Provenance (what was planned/executed)</strong></summary>
              <?php if ($provReason): ?>
                <p><strong>Reason:</strong> <?= htmlspecialchars($provReason) ?></p>
              <?php endif; ?>
              <?php if ($provSql): ?>
                <p><strong>SQL:</strong></p>
                <pre class="result" id="sqlText"><?= htmlspecialchars($provSql) ?></pre>
                <button class="button is-small is-light" type="button" data-copy-target="#sqlText">Copy SQL</button>
              <?php endif; ?>
              <?php if ($executedOp): ?>
                <p><strong>Executed Operation:</strong></p>
                <pre class="result" id="opText"><?= htmlspecialchars(json_encode($executedOp, JSON_PRETTY_PRINT)) ?></pre>
                <button class="button is-small is-light" type="button" data-copy-target="#opText">Copy Operation</button>
              <?php elseif ($proposedOp): ?>
                <p><strong>Proposed Operation:</strong></p>
                <pre class="result" id="opText"><?= htmlspecialchars(json_encode($proposedOp, JSON_PRETTY_PRINT)) ?></pre>
                <button class="button is-small is-light" type="button" data-copy-target="#opText">Copy Operation</button>
              <?php endif; ?>
              <?php if ($errorRaw): ?>
                <p><strong>DB Error (raw):</strong></p>
                <pre class="result"><?= htmlspecialchars($errorRaw) ?></pre>
              <?php endif; ?>
            </details>
          <?php endif; ?>
          <?php if (!empty($_SESSION['last_base_sql'])): ?>
            <div class="field is-grouped" style="margin-top:10px;">
              <form method="post" class="control">
                <input type="hidden" name="paginate" value="1">
                <input type="hidden" name="page" value="<?= max(1, $page-1) ?>">
                <input type="hidden" name="page_size" value="<?= (int)$pageSize ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <button class="button is-light" type="submit" <?= $page <= 1 ? 'disabled' : '' ?>>Prev</button>
              </form>
              <form method="post" class="control">
                <input type="hidden" name="paginate" value="1">
                <input type="hidden" name="page" value="<?= $page + 1 ?>">
                <input type="hidden" name="page_size" value="<?= (int)$pageSize ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <?php
                  $disableNext = false;
                  if (is_int($totalCount ?? null)) {
                    $disableNext = ($page * $pageSize) >= $totalCount;
                  }
                ?>
                <button class="button is-light" type="submit" <?= $disableNext ? 'disabled' : '' ?>>Next</button>
              </form>
              <div class="control" style="padding-top:8px;">
                <span class="tag is-light">Page <?= (int)$page ?><?= is_int($totalCount ?? null) ? (' / ' . max(1, (int)ceil($totalCount / max(1,$pageSize)) )) : '' ?></span>
              </div>
            </div>
          <?php endif; ?>
          <?php if ($resultPayload !== null): ?>
            <details>
              <summary>Show raw payload</summary>
              <pre class="result" id="payloadText"><?= htmlspecialchars(json_encode($resultPayload, JSON_PRETTY_PRINT)) ?></pre>
              <div class="buttons">
                <button class="button is-small is-light" type="button" data-copy-target="#payloadText">Copy JSON</button>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                  <input type="hidden" name="download_csv" value="1" />
                  <button class="button is-small is-link is-light" type="submit">Download CSV</button>
                </form>
              </div>
            </details>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($proposedOp): ?>
        <div class="box" style="margin-top:16px;">
          <h3 class="title is-5">Confirm Write</h3>
          <article class="message is-warning">
            <div class="message-body">
              <p>This change will be applied to table <strong><?= htmlspecialchars($proposedOp['table']) ?></strong>:</p>
              <pre class="result"><?= htmlspecialchars(json_encode($proposedOp, JSON_PRETTY_PRINT)) ?></pre>
              <form method="post">
                <input type="hidden" name="confirm_write" value="1" />
                <input type="hidden" name="proposed_op" value='<?= htmlspecialchars(json_encode($proposedOp)) ?>' />
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <button type="submit" class="button is-warning is-light">Confirm and Apply</button>
              </form>
            </div>
          </article>
        </div>
      <?php endif; ?>

      <details>
        <summary><strong>Show database schema context</strong></summary>
        <pre class="result"><?= htmlspecialchars($schemaBlock) ?></pre>
        <hr>
        <p class="subtitle is-6">Exposed tables: <?= htmlspecialchars(implode(', ', array_keys($exposedTables))) ?></p>
      </details>
    </div>
  </section>
</body>
<script>
  (function(){
    var btn = document.getElementById('toggleExamples');
    var panel = document.getElementById('examplesPanel');
    if (btn && panel) {
      btn.addEventListener('click', function(){
        var show = panel.style.display === 'none';
        panel.style.display = show ? 'block' : 'none';
        btn.textContent = show ? 'Hide examples' : 'Show examples';
      });
    }
    var chips = document.querySelectorAll('.ex-chip');
    chips.forEach(function(ch){
      ch.addEventListener('click', function(){
        var t = ch.getAttribute('data-text') || '';
        var ta = document.getElementById('nl');
        if (ta) { ta.value = t; ta.focus(); }
      });
    });
  })();
  // Copy to clipboard helper
  (function(){
    function copyFromSelector(sel){
      var el = document.querySelector(sel);
      if (!el) return;
      var text = el.innerText || el.textContent || '';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
      } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch(e) {}
        document.body.removeChild(ta);
      }
    }
    document.querySelectorAll('[data-copy-target]').forEach(function(btn){
      btn.addEventListener('click', function(){ copyFromSelector(btn.getAttribute('data-copy-target')); });
    });
  })();
</script>
<?php $csrfVal = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES); ?>
</html>
