<?php
/**
 * Minimal safe markdown → HTML for accounting breakdown docs.
 */

declare(strict_types=1);

function dsc_markdown_to_html(string $markdown): string {
    $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
    $html = [];
    $inCode = false;
    $codeBuf = [];
    $listOpen = false;

    $flushList = static function () use (&$html, &$listOpen): void {
        if ($listOpen) {
            $html[] = '</ul>';
            $listOpen = false;
        }
    };

    foreach ($lines as $line) {
        if (preg_match('/^```/', $line)) {
            if ($inCode) {
                $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeBuf), ENT_QUOTES, 'UTF-8') . '</code></pre>';
                $codeBuf = [];
                $inCode = false;
            } else {
                $flushList();
                $inCode = true;
            }
            continue;
        }
        if ($inCode) {
            $codeBuf[] = $line;
            continue;
        }

        if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
            $flushList();
            $level = strlen($m[1]);
            $tag = $level === 1 ? 'h2' : ($level === 2 ? 'h3' : 'h4');
            $html[] = '<' . $tag . '>' . dsc_markdown_inline_html($m[2]) . '</' . $tag . '>';
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
            if (!$listOpen) {
                $html[] = '<ul>';
                $listOpen = true;
            }
            $html[] = '<li>' . dsc_markdown_inline_html($m[1]) . '</li>';
            continue;
        }

        if (trim($line) === '') {
            $flushList();
            continue;
        }

        $flushList();
        $html[] = '<p>' . dsc_markdown_inline_html($line) . '</p>';
    }

    if ($inCode && $codeBuf !== []) {
        $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeBuf), ENT_QUOTES, 'UTF-8') . '</code></pre>';
    }
    $flushList();

    return implode("\n", $html);
}

function dsc_markdown_inline_html(string $text): string {
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
    return $escaped;
}
