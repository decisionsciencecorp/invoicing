<?php

if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Client invoices + admin are not a public marketing surface — keep crawlers out.
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    $csp = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;";
    header('Content-Security-Policy: ' . $csp);
    if (defined('SITE_URL') && strpos(SITE_URL, 'https://') === 0) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
