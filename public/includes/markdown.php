<?php
/**
 * Markdown → HTML (same stack as Sanctum Tasks: Parsedown 1.7.4 safe mode).
 */

declare(strict_types=1);

/**
 * Render markdown safely for client-facing invoice breakdowns.
 * Trusted HTML output (Parsedown safe mode + escaped markup).
 */
function dsc_markdown_to_html(?string $raw, bool $inline = false): string {
    $raw = (string) $raw;
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
