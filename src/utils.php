<?php

if (!function_exists('ensureDir')) {
    function ensureDir(string $path): void {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
    }
}

if (!function_exists('log_event')) {
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
}

if (!function_exists('rate_limit_or_die')) {
    function rate_limit_or_die(): void {
        global $rateLimitWindowSec, $rateLimitMaxRequests;
        if ($rateLimitMaxRequests <= 0) return; // disabled
        $ip = preg_replace('/[^0-9a-fA-F:\\.]/', '', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
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
}

if (!function_exists('require_csrf_or_die')) {
    function require_csrf_or_die(): void {
        $token = $_POST['csrf_token'] ?? '';
        $valid = isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
        if (!$valid) {
            http_response_code(400);
            echo '<!doctype html><meta charset="utf-8"><title>Bad Request</title><p style="font-family:system-ui,Arial">Invalid or missing CSRF token.</p>';
            exit;
        }
    }
}

if (!function_exists('extractJsonPayload')) {
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
}

