#!/usr/bin/env php
<?php
/**
 * Run PHPUnit with coverage; enforce --min (default 90) on Lines %.
 *
 * Usage: php tools/check_coverage.php [--min=90]
 */
declare(strict_types=1);

$min = 90.0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--min=')) {
        $min = (float) substr($arg, 6);
    }
}

$root = dirname(__DIR__);
$phpunit = $root . '/tools/phpunit.phar';
if (!is_file($phpunit)) {
    fwrite(STDERR, "Missing tools/phpunit.phar\n");
    exit(2);
}

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
    . ' --colors=never --coverage-text';
$out = [];
exec($cmd . ' 2>&1', $out, $code);
$text = implode("\n", $out);
echo $text, "\n";
if ($code !== 0) {
    exit($code);
}
if (!preg_match('/Lines:\s+([\d.]+)%/', $text, $m)) {
    fwrite(STDERR, "Could not parse Lines coverage from PHPUnit output.\n");
    exit(2);
}
$lines = (float) $m[1];
echo sprintf("Coverage gate: Lines %.2f%% (min %.2f%%)\n", $lines, $min);
if ($lines + 0.001 < $min) {
    fwrite(STDERR, "FAIL: coverage below threshold.\n");
    exit(1);
}
echo "OK: coverage meets threshold.\n";
exit(0);
