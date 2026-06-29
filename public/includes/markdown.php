<?php
/**
 * Markdown → HTML (same stack as Sanctum Tasks: Parsedown 1.7.4 safe mode).
 */

declare(strict_types=1);

/**
 * Render markdown safely for client-facing invoice breakdowns.
 * Trusted HTML output (Parsedown safe mode + escaped markup).
 */
function dsc_markdown_normalize_storage(string $raw): string {
    if ($raw === '') {
        return '';
    }
    // Legacy poisoned rows: JSON-escaped newlines / \uXXXX from one-off backfill scripts.
    if (!str_contains($raw, "\n") && str_contains($raw, '\\n')) {
        $raw = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $raw);
    }
    if (str_contains($raw, '\\u')) {
        $decoded = preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            static function (array $m): string {
                $bin = pack('H*', $m[1]);
                $out = @mb_convert_encoding($bin, 'UTF-8', 'UCS-2BE');
                return is_string($out) ? $out : $m[0];
            },
            $raw
        );
        if (is_string($decoded)) {
            $raw = $decoded;
        }
    }
    return $raw;
}

function dsc_markdown_to_html(?string $raw, bool $inline = false): string {
    $raw = dsc_markdown_normalize_storage((string) $raw);
    if ($raw === '') {
        return '';
    }
    require_once __DIR__ . '/lib/Parsedown.php';
    static $pd = null;
    if ($pd === null) {
        $pd = new Parsedown();
        $pd->setSafeMode(true);
        $pd->setMarkupEscaped(true);
        $pd->setUrlsLinked(true);
        $pd->setBreaksEnabled(true);
    }

    return $inline ? $pd->line($raw) : $pd->text($raw);
}
