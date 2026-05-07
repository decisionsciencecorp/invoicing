<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode([
    'success' => true,
    'service' => 'dsc-invoicing',
    'time' => gmdate('c'),
]);
