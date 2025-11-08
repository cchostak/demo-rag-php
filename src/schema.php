<?php

if (!function_exists('ensureProductsTable')) {
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
}

if (!function_exists('loadSchema')) {
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
}

if (!function_exists('tableCoverage')) {
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
}
