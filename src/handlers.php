<?php

function handle_request(PDO $pdo, array $exposedTables, array $schema, string $schemaBlock, string $coverageHint, string $openaiModel, int $maxRows, int $defaultPageSize): array {
    $nl = trim($_POST['nl'] ?? '');
    $confirmWrite = isset($_POST['confirm_write']);
    $proposedOpJson = $_POST['proposed_op'] ?? '';
    $downloadCsv = isset($_POST['download_csv']);
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
        global $openaiApiKey, $requireWriteConfirmation;
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
                    $baseSql = trim(rtrim($safeSql, ';'));
                    $baseSql = preg_replace('/\s+LIMIT\s+\d+(\s*,\s*\d+|\s+OFFSET\s+\d+)?\s*$/i', '', $baseSql);
                    $_SESSION['last_base_sql'] = $baseSql;
                    $_SESSION['last_page_size'] = $pageSize;
                    $offset = ($page - 1) * $pageSize;
                    $sql = 'SELECT * FROM (' . $baseSql . ') AS _sub LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset;
                    $provSql = $sql;
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

    return compact('modeTag','feedback','resultPayload','proposedOp','executedOp','provReason','provSql','errorRaw','totalCount','page','pageSize');
}

