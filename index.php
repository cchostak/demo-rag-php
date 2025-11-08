<?php
require 'vendor/autoload.php';

// Phase 1 split: load config and OpenAI helper
require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/src/openai.php';
require_once __DIR__ . '/src/utils.php';
require_once __DIR__ . '/src/schema.php';
require_once __DIR__ . '/src/db_write.php';
require_once __DIR__ . '/src/bootstrap.php';

// Simple, production-leaning NL → DB assistant with:
// - Single textbox for read/write
// - Dynamic schema RAG over whitelisted tables
// - Strict write controls (insert/update only by default) with confirmation
// - Parameterized statements for safety

// Configuration moved to src/config.php

// Bootstrap moved to src/bootstrap.php

// ensureProductsTable moved to src/schema.php

// askOpenAI moved to src/openai.php

// ensureDir/log_event moved to src/utils.php

// rate_limit_or_die moved to src/utils.php

// require_csrf_or_die moved to src/utils.php

// loadSchema moved to src/schema.php

// tableCoverage moved to src/schema.php

// extractJsonPayload moved to src/utils.php

// guardSelect moved to src/db_write.php

// validateWrite moved to src/db_write.php

// execInsert moved to src/db_write.php

// execUpdate moved to src/db_write.php

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
// Request handling (moved to src/handlers.php)
// -------------------------
require_once __DIR__ . '/src/handlers.php';
$__vars = handle_request($pdo, $exposedTables, $schema, $schemaBlock, $coverageHint, $openaiModel, $maxRows, $defaultPageSize);
extract($__vars, EXTR_OVERWRITE);

// -------------------------
// UI (moved to views/main.php)
// -------------------------
require __DIR__ . '/views/main.php';
