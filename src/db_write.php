<?php

if (!function_exists('guardSelect')) {
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
}

if (!function_exists('validateWrite')) {
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
}

if (!function_exists('execInsert')) {
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
}

if (!function_exists('execUpdate')) {
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
}
