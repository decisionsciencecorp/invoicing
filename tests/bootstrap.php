<?php
/**
 * PHPUnit bootstrap for DSC Invoicing.
 */
$projectRoot = dirname(__DIR__);
putenv('INVOICING_TEST=1');
$dbPath = getenv('DB_PATH');
if ($dbPath === false || $dbPath === '' || $dbPath === ':memory:') {
    $dbPath = sys_get_temp_dir() . '/dsc_invoicing_test_' . getmypid() . '.db';
    putenv('DB_PATH=' . $dbPath);
}
$_ENV['DB_PATH'] = $dbPath;
@unlink($dbPath);

defined('DSC_INVOICING_SKIP_SESSION') || define('DSC_INVOICING_SKIP_SESSION', true);

require_once $projectRoot . '/public/includes/config.php';
require_once $projectRoot . '/public/includes/markdown.php';
require_once $projectRoot . '/public/includes/csrf.php';
require_once $projectRoot . '/public/includes/auth.php';
require_once $projectRoot . '/public/includes/square.php';
require_once $projectRoot . '/public/includes/tasks-dsc.php';
require_once $projectRoot . '/public/includes/billing.php';
require_once $projectRoot . '/public/api/handlers/invoicing-crud-handlers.php';

/**
 * Default Square HTTP mock used when INVOICING_SQUARE_MOCK=1.
 *
 * @return array{ok:bool,status?:int,data?:array,error?:string}
 */
function invoicing_test_default_square_mock(string $method, string $path, ?array $body): array {
    $n = (int) ($GLOBALS['_dsc_square_mock_seq'] ?? 0);
    $GLOBALS['_dsc_square_mock_seq'] = $n + 1;
    $paid = !empty($GLOBALS['_dsc_square_mock_paid']);
    $uniq = substr(sha1(($method . '|' . $path . '|' . json_encode($body) . '|' . $n)), 0, 10);

    if ($method === 'POST' && $path === '/customers') {
        return [
            'ok' => true,
            'status' => 200,
            'data' => ['customer' => ['id' => 'CUST_MOCK_' . $uniq]],
        ];
    }
    if ($method === 'POST' && $path === '/orders') {
        return [
            'ok' => true,
            'status' => 200,
            'data' => ['order' => ['id' => 'ORDER_MOCK_' . $uniq]],
        ];
    }
    if ($method === 'POST' && $path === '/invoices') {
        return [
            'ok' => true,
            'status' => 200,
            'data' => [
                'invoice' => [
                    'id' => 'inv:MOCK_' . $uniq,
                    'version' => 0,
                    'status' => 'DRAFT',
                ],
            ],
        ];
    }
    if ($method === 'POST' && preg_match('#^/invoices/.+/publish$#', $path)) {
        $id = 'inv:PUB_' . $n;
        if (preg_match('#^/invoices/([^/]+)/publish$#', $path, $m)) {
            $id = rawurldecode($m[1]);
        }
        return [
            'ok' => true,
            'status' => 200,
            'data' => [
                'invoice' => [
                    'id' => $id,
                    'version' => 1,
                    'status' => $paid ? 'PAID' : 'UNPAID',
                    'public_url' => 'https://squareup.com/pay/' . rawurlencode($id),
                ],
            ],
        ];
    }
    if ($method === 'GET' && preg_match('#^/invoices/[^/]+$#', $path)) {
        $id = 'inv:GET_' . $n;
        if (preg_match('#^/invoices/([^/]+)$#', $path, $m)) {
            $id = rawurldecode($m[1]);
        }
        $status = 'UNPAID';
        if ($paid) {
            $status = 'PAID';
        } elseif (!empty($GLOBALS['_dsc_square_mock_canceled'])) {
            $status = 'CANCELED';
        }
        return [
            'ok' => true,
            'status' => 200,
            'data' => [
                'invoice' => [
                    'id' => $id,
                    'version' => 2,
                    'status' => $status,
                    'public_url' => 'https://squareup.com/pay/' . rawurlencode($id),
                ],
            ],
        ];
    }
    if ($method === 'POST' && preg_match('#^/invoices/.+/cancel$#', $path)) {
        $id = 'inv:CANCEL_' . $n;
        if (preg_match('#^/invoices/([^/]+)/cancel$#', $path, $m)) {
            $id = rawurldecode($m[1]);
        }
        $GLOBALS['_dsc_square_mock_canceled'] = true;
        return [
            'ok' => true,
            'status' => 200,
            'data' => [
                'invoice' => [
                    'id' => $id,
                    'version' => 3,
                    'status' => 'CANCELED',
                    'public_url' => 'https://squareup.com/pay/' . rawurlencode($id),
                ],
            ],
        ];
    }
    if ($method === 'GET' && $path === '/locations') {
        return [
            'ok' => true,
            'status' => 200,
            'data' => ['locations' => [['id' => 'LOC_TEST', 'name' => 'Test']]],
        ];
    }

    return ['ok' => false, 'status' => 404, 'error' => 'Unhandled mock path ' . $method . ' ' . $path];
}

/**
 * @return array{ok:bool,document?:array,error?:string}
 */
function invoicing_test_default_tasks_mock(int $documentId): array {
    if ($documentId === 999001) {
        return ['ok' => false, 'error' => 'Tasks document not found or denied: missing'];
    }
    return [
        'ok' => true,
        'document' => [
            'id' => $documentId,
            'title' => 'Accounting ' . $documentId,
            'body' => "## Hours\n\n- Worked 5h\n",
            'project_id' => 2,
        ],
    ];
}

function invoicing_test_install_mocks(): void {
    putenv('INVOICING_SQUARE_MOCK=1');
    putenv('INVOICING_TASKS_MOCK=1');
    $GLOBALS['_dsc_square_mock_seq'] = 0;
    $GLOBALS['_dsc_square_mock_paid'] = false;
    $GLOBALS['_dsc_square_mock_handler'] = 'invoicing_test_default_square_mock';
    $GLOBALS['_dsc_tasks_mock_handler'] = 'invoicing_test_default_tasks_mock';
    square_config_reset();
}

function invoicing_test_seed_company_engagement(
    SQLite3 $db,
    int $includedHours = 5,
    int $rateCents = 10000,
    string $billingMode = 'hourly',
    int $tier1 = 0,
    int $tier2 = 0,
): array {
    $name = 'Test Co ' . uniqid('', true);
    $stc = $db->prepare('INSERT INTO companies (name, billing_email) VALUES (:n, :e)');
    $stc->bindValue(':n', $name, SQLITE3_TEXT);
    $stc->bindValue(':e', 'billing@example.com', SQLITE3_TEXT);
    $stc->execute();
    $companyId = (int) $db->lastInsertRowID();
    $st = $db->prepare(
        'INSERT INTO engagements (company_id, name, hourly_rate_cents, included_hours_per_month, status, '
        . 'billing_mode, tier1_amount_cents, tier2_amount_cents) '
        . 'VALUES (:c, :n, :r, :h, \'active\', :bm, :t1, :t2)'
    );
    $st->bindValue(':c', $companyId, SQLITE3_INTEGER);
    $st->bindValue(':n', $billingMode === 'flat_tier' ? 'Flat program' : 'Retainer', SQLITE3_TEXT);
    $st->bindValue(':r', $rateCents, SQLITE3_INTEGER);
    $st->bindValue(':h', $includedHours, SQLITE3_INTEGER);
    $st->bindValue(':bm', $billingMode, SQLITE3_TEXT);
    $st->bindValue(':t1', $tier1, SQLITE3_INTEGER);
    $st->bindValue(':t2', $tier2, SQLITE3_INTEGER);
    $st->execute();
    return ['company_id' => $companyId, 'engagement_id' => (int) $db->lastInsertRowID()];
}

function invoicing_test_seed_api_key(): string {
    initializeDatabase();
    return createApiKey('phpunit');
}

invoicing_test_install_mocks();
