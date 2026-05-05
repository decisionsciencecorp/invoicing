<?php

require_once __DIR__ . '/config.php';

function dsc_invoicing_web_base_path(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (preg_match('#^(.+)/admin/[^/]+$#', $script, $m)) {
        return rtrim($m[1], '/');
    }
    if (preg_match('#^(.+)/admin/[^/]+\.php$#', $script, $m)) {
        return rtrim($m[1], '/');
    }
    if (preg_match('#^(.+)/[^/]+\.php$#', $script, $m)) {
        return rtrim($m[1], '/');
    }
    return '';
}

/** URL path under the app document root (public/). */
function dsc_invoicing_href(string $path): string {
    $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
    $base = dsc_invoicing_web_base_path();
    if ($base === '') {
        return $path;
    }
    return $base . $path;
}

function app_log(string $level, string $message): void {
    if (!defined('LOG_PATH')) {
        return;
    }
    $dir = dirname(LOG_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = date('c') . ' [' . $level . '] ' . $message . "\n";
    @file_put_contents(LOG_PATH, $line, FILE_APPEND | LOCK_EX);
}
