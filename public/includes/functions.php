<?php

require_once __DIR__ . '/config.php';

function dsc_invoicing_web_base_path(): string {
    $env = getenv('INVOICING_WEB_BASE');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }
    if (function_exists('get_config')) {
        try {
            $fromConfig = get_config('web_base_path');
            if (is_string($fromConfig) && trim($fromConfig) !== '') {
                return rtrim(trim($fromConfig), '/');
            }
        } catch (Throwable $e) {
            // DB/config table may not be available very early in bootstrap.
        }
    }

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

    $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (is_string($scriptFilename) && is_string($documentRoot) && $scriptFilename !== '' && $documentRoot !== '') {
        $sf = str_replace('\\', '/', $scriptFilename);
        $dr = rtrim(str_replace('\\', '/', $documentRoot), '/');
        if (str_starts_with($sf, $dr . '/')) {
            $rel = substr($sf, strlen($dr));
            if (preg_match('#^(/.+)/[^/]+\.php$#', $rel, $m)) {
                return rtrim($m[1], '/');
            }
        }
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (is_string($uri) && preg_match('#^(/.+)/(admin|api)/[^/?]+(?:\.php)?(?:\?|$)#', $uri, $m)) {
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
