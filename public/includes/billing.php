<?php
/**
 * Combined monthly billing: retainer for anchor month M + prior-month overage (M−1).
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/square.php';

function dsc_billing_valid_month(string $ym): bool {
    return (bool) preg_match('/^\d{4}-\d{2}$/', $ym);
}

function dsc_billing_prev_month(string $ym): ?string {
    if (!dsc_billing_valid_month($ym)) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m', $ym);
    if (!$dt) {
        return null;
    }
    return $dt->modify('-1 month')->format('Y-m');
}

function dsc_billing_month_last_day_iso(string $ym): string {
    $dt = DateTimeImmutable::createFromFormat('!Y-m', $ym);
    if (!$dt) {
        return gmdate('Y-m-t');
    }
    return $dt->modify('last day of this month')->format('Y-m-d');
}

function dsc_billing_sum_hours_engagement(SQLite3 $db, int $engagementId, string $periodMonth): float {
    $st = $db->prepare(
        'SELECT SUM(hours) AS s FROM time_entries WHERE engagement_id = :e AND billing_period_month = :p'
    );
    $st->bindValue(':e', $engagementId, SQLITE3_INTEGER);
    $st->bindValue(':p', $periodMonth, SQLITE3_TEXT);
    $r = $st->execute();
    $row = $r ? $r->fetchArray(SQLITE3_ASSOC) : false;
    if (!$row || $row['s'] === null) {
        return 0.0;
    }
    return (float) $row['s'];
}

/**
 * @return array{
 *     retainer_month:string,
 *     overage_month:string|null,
 *     retainer_amount_cents:int,
 *     overage_amount_cents:int,
 *     total_cents:int,
 *     hourly_rate_cents:int,
 *     included_hours_per_month:int
 * }|array{error:string}
 */
function dsc_billing_combined_totals(SQLite3 $db, int $engagementId, string $anchorMonth): array {
    if (!dsc_billing_valid_month($anchorMonth)) {
        return ['error' => 'Invalid billing month (use YYYY-MM).'];
    }
    $st = $db->prepare(
        'SELECT e.id, e.hourly_rate_cents, e.included_hours_per_month, e.status, e.name AS engagement_name '
        . 'FROM engagements e WHERE e.id = :id LIMIT 1'
    );
    $st->bindValue(':id', $engagementId, SQLITE3_INTEGER);
    $exe = $st->execute();
    $e = $exe ? $exe->fetchArray(SQLITE3_ASSOC) : false;
    if (!$e) {
        return ['error' => 'Engagement not found.'];
    }
    if (($e['status'] ?? '') !== 'active') {
        return ['error' => 'Engagement is not active; cannot invoice.'];
    }
    $rate = (int) $e['hourly_rate_cents'];
    $included = (int) $e['included_hours_per_month'];
    $overageMonth = dsc_billing_prev_month($anchorMonth);
    $retainerCents = max(0, (int) round($included * $rate));

    $overageCents = 0;
    if ($overageMonth !== null) {
        $prevLogged = dsc_billing_sum_hours_engagement($db, $engagementId, $overageMonth);
        $overageHours = max(0.0, $prevLogged - (float) $included);
        $overageCents = (int) round($overageHours * $rate);
    }

    $total = $retainerCents + $overageCents;
    return [
        'retainer_month' => $anchorMonth,
        'overage_month' => $overageMonth,
        'retainer_amount_cents' => $retainerCents,
        'overage_amount_cents' => $overageCents,
        'total_cents' => $total,
        'hourly_rate_cents' => $rate,
        'included_hours_per_month' => $included,
    ];
}

/**
 * Provision Square customer row for company when missing (requires billing_email).
 *
 * @return array{ok:bool, customer_id?:string, error?:string}
 */
function dsc_invoicing_square_ensure_company_customer(SQLite3 $db, int $companyId): array {
    $st = $db->prepare(
        'SELECT id, name, billing_email, square_customer_id FROM companies WHERE id = :id LIMIT 1'
    );
    $st->bindValue(':id', $companyId, SQLITE3_INTEGER);
    $exe = $st->execute();
    $c = $exe ? $exe->fetchArray(SQLITE3_ASSOC) : false;
    if (!$c) {
        return ['ok' => false, 'error' => 'Company not found.'];
    }
    $existing = trim((string) ($c['square_customer_id'] ?? ''));
    if ($existing !== '') {
        return ['ok' => true, 'customer_id' => $existing];
    }
    $email = trim((string) ($c['billing_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Company needs a valid billing_email before invoicing (Square EMAIL delivery).'];
    }
    $name = trim((string) ($c['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'error' => 'Company name required.'];
    }
    $idemp = 'dsc-inv-cust-create-' . $companyId;

    // Square Customers API wraps body in {"given_name"...} → top-level Customer fields
    $body = [
        'idempotency_key' => $idemp,
        'given_name' => 'DSC',
        'family_name' => 'Billing',
        'company_name' => $name,
        'email_address' => $email,
    ];
    $resp = dsc_invoicing_square_request('POST', '/customers', $body);
    if (!$resp['ok']) {
        return ['ok' => false, 'error' => 'Square CreateCustomer failed: ' . ($resp['error'] ?? 'unknown')];
    }
    $customer = $resp['data']['customer'] ?? null;
    $cid = is_array($customer) && !empty($customer['id']) ? trim((string) $customer['id']) : '';
    if ($cid === '') {
        return ['ok' => false, 'error' => 'Square CreateCustomer returned no customer id.'];
    }
    $up = $db->prepare('UPDATE companies SET square_customer_id = :s, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $up->bindValue(':s', $cid, SQLITE3_TEXT);
    $up->bindValue(':id', $companyId, SQLITE3_INTEGER);
    $up->execute();
    square_config_reset();

    return ['ok' => true, 'customer_id' => $cid];
}

/**
 * Create order + invoice in Square, publish, persist outbound_invoices, stamp time rows for overage month.
 *
 * @return array{ok:bool, message?:string, outbound_id?:int, public_url?:string, error?:string}
 */
function dsc_billing_publish_combined_invoice(SQLite3 $db, int $engagementId, string $anchorMonth): array {
    $totals = dsc_billing_combined_totals($db, $engagementId, $anchorMonth);
    if (isset($totals['error'])) {
        return ['ok' => false, 'error' => $totals['error']];
    }
    if ($totals['total_cents'] <= 0) {
        return ['ok' => false, 'error' => 'Nothing to bill (retainer + overage total is zero).'];
    }
    $overageMonth = $totals['overage_month'];
    if ($overageMonth === null) {
        return ['ok' => false, 'error' => 'Could not resolve prior month for overage.'];
    }

    $cfg = dsc_invoicing_square_config();
    if (empty($cfg['location_id'])) {
        return ['ok' => false, 'error' => 'Square location_id not configured.'];
    }

    $st = $db->prepare(
        'SELECT e.id, e.company_id, e.name AS engagement_name, c.name AS company_name '
        . 'FROM engagements e JOIN companies c ON c.id = e.company_id WHERE e.id = :id LIMIT 1'
    );
    $st->bindValue(':id', $engagementId, SQLITE3_INTEGER);
    $exe = $st->execute();
    $row = $exe ? $exe->fetchArray(SQLITE3_ASSOC) : false;
    if (!$row) {
        return ['ok' => false, 'error' => 'Engagement not found.'];
    }
    $companyId = (int) $row['company_id'];

    $cust = dsc_invoicing_square_ensure_company_customer($db, $companyId);
    if (!$cust['ok']) {
        return ['ok' => false, 'error' => $cust['error'] ?? 'customer'];
    }
    $customerId = $cust['customer_id'] ?? '';
    if ($customerId === '') {
        return ['ok' => false, 'error' => 'Missing Square customer id.'];
    }

    $lineItems = [];
    if ($totals['retainer_amount_cents'] > 0) {
        $lineItems[] = [
            'name' => 'Monthly retainer — ' . $anchorMonth,
            'quantity' => '1',
            'base_price_money' => [
                'amount' => $totals['retainer_amount_cents'],
                'currency' => 'USD',
            ],
        ];
    }
    if ($totals['overage_amount_cents'] > 0) {
        $lineItems[] = [
            'name' => 'Prior-month overage — ' . $overageMonth,
            'quantity' => '1',
            'base_price_money' => [
                'amount' => $totals['overage_amount_cents'],
                'currency' => 'USD',
            ],
        ];
    }

    $orderKey = substr('ord-' . sha1((string) $engagementId . '|' . $anchorMonth . '|combined'), 0, 61);
    $orderBody = [
        'idempotency_key' => $orderKey,
        'order' => [
            'location_id' => (string) $cfg['location_id'],
            'customer_id' => $customerId,
            'line_items' => $lineItems,
        ],
    ];
    $orderRes = dsc_invoicing_square_request('POST', '/orders', $orderBody);
    if (!$orderRes['ok']) {
        return ['ok' => false, 'error' => 'Create order failed: ' . ($orderRes['error'] ?? 'unknown')];
    }
    $order = $orderRes['data']['order'] ?? null;
    $orderId = is_array($order) && !empty($order['id']) ? (string) $order['id'] : '';
    if ($orderId === '') {
        return ['ok' => false, 'error' => 'Create order returned no order id.'];
    }

    $invKey = substr('inv-' . sha1((string) $engagementId . '|' . $anchorMonth . '|combined'), 0, 61);
    $due = dsc_billing_month_last_day_iso($anchorMonth);
    $invoiceNumber = substr('DSC' . preg_replace('/\D/', '', $anchorMonth) . 'e' . $engagementId, 0, 25);
    $invBody = [
        'idempotency_key' => $invKey,
        'invoice' => [
            'location_id' => (string) $cfg['location_id'],
            'order_id' => $orderId,
            'primary_recipient' => [
                'customer_id' => $customerId,
            ],
            'payment_requests' => [
                [
                    'request_type' => 'BALANCE',
                    'due_date' => $due,
                ],
            ],
            'delivery_method' => 'EMAIL',
            'accepted_payment_methods' => [
                'card' => true,
                'square_gift_card' => false,
                'bank_account' => false,
                'buy_now_pay_later' => false,
                'cash_app_pay' => false,
            ],
            'title' => 'DSC combined — ' . $anchorMonth,
            'description' => 'Monthly retainer for ' . $anchorMonth . '; overage window ' . $overageMonth . '.',
            'invoice_number' => $invoiceNumber,
            'sale_or_service_date' => $due,
        ],
    ];
    $invRes = dsc_invoicing_square_request('POST', '/invoices', $invBody);
    if (!$invRes['ok']) {
        return ['ok' => false, 'error' => 'Create invoice failed: ' . ($invRes['error'] ?? 'unknown')];
    }
    $inv = $invRes['data']['invoice'] ?? null;
    if (!is_array($inv) || empty($inv['id'])) {
        return ['ok' => false, 'error' => 'Create invoice returned no invoice id.'];
    }
    $invoiceId = trim((string) $inv['id']);
    $version = isset($inv['version']) ? (int) $inv['version'] : 0;

    $pubKey = substr('pub-' . sha1($invoiceId . '|' . (string) $version), 0, 61);
    $pubRes = dsc_invoicing_square_request(
        'POST',
        '/invoices/' . rawurlencode($invoiceId) . '/publish',
        [
            'idempotency_key' => $pubKey,
            'version' => $version,
        ]
    );
    if (!$pubRes['ok']) {
        return ['ok' => false, 'error' => 'Publish invoice failed: ' . ($pubRes['error'] ?? 'unknown')];
    }
    $invOut = $pubRes['data']['invoice'] ?? $inv;
    $publicUrl = '';
    if (is_array($invOut) && !empty($invOut['public_url'])) {
        $publicUrl = trim((string) $invOut['public_url']);
    }
    if ($publicUrl === '') {
        $get = dsc_invoicing_square_request('GET', '/invoices/' . rawurlencode($invoiceId), null);
        if ($get['ok']) {
            $inv2 = $get['data']['invoice'] ?? null;
            if (is_array($inv2) && !empty($inv2['public_url'])) {
                $publicUrl = trim((string) $inv2['public_url']);
            }
        }
    }
    $versionOut = is_array($invOut) && isset($invOut['version']) ? (int) $invOut['version'] : $version;

    try {
        $ins = $db->prepare(
            'INSERT INTO outbound_invoices (engagement_id, anchor_month, overage_month, retainer_amount_cents, '
            . 'overage_amount_cents, total_amount_cents, square_order_id, square_invoice_id, square_invoice_version, '
            . 'public_url, delivery_method, payment_status) VALUES '
            . '(:e, :a, :o, :r, :ov, :t, :so, :si, :sv, :pu, :dm, :ps)'
        );
        $ins->bindValue(':e', $engagementId, SQLITE3_INTEGER);
        $ins->bindValue(':a', $anchorMonth, SQLITE3_TEXT);
        $ins->bindValue(':o', $overageMonth, SQLITE3_TEXT);
        $ins->bindValue(':r', $totals['retainer_amount_cents'], SQLITE3_INTEGER);
        $ins->bindValue(':ov', $totals['overage_amount_cents'], SQLITE3_INTEGER);
        $ins->bindValue(':t', $totals['total_cents'], SQLITE3_INTEGER);
        $ins->bindValue(':so', $orderId, SQLITE3_TEXT);
        $ins->bindValue(':si', $invoiceId, SQLITE3_TEXT);
        $ins->bindValue(':sv', $versionOut, SQLITE3_INTEGER);
        $ins->bindValue(':pu', $publicUrl, SQLITE3_TEXT);
        $ins->bindValue(':dm', 'EMAIL', SQLITE3_TEXT);
        $ins->bindValue(':ps', 'published', SQLITE3_TEXT);
        $ins->execute();
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'Square publish succeeded but local DB insert failed (duplicate month?): ' . $e->getMessage(),
        ];
    }
    $outboundId = (int) $db->lastInsertRowID();

    $stamp = $db->prepare(
        'UPDATE time_entries SET invoiced_square_invoice_id = :i, updated_at = CURRENT_TIMESTAMP '
        . 'WHERE engagement_id = :e AND billing_period_month = :p '
        . 'AND (invoiced_square_invoice_id IS NULL OR TRIM(invoiced_square_invoice_id) = \'\')'
    );
    $stamp->bindValue(':i', $invoiceId, SQLITE3_TEXT);
    $stamp->bindValue(':e', $engagementId, SQLITE3_INTEGER);
    $stamp->bindValue(':p', $overageMonth, SQLITE3_TEXT);
    $stamp->execute();

    return [
        'ok' => true,
        'message' => 'Invoice published.',
        'outbound_id' => $outboundId,
        'public_url' => $publicUrl !== '' ? $publicUrl : null,
    ];
}
