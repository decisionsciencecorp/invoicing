<?php
/**
 * Combined monthly billing: retainer for anchor month M + prior-month overage (M−1).
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/square.php';
require_once __DIR__ . '/tasks-dsc.php';

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

/** Retainer due on receipt (publish day); overage net-30 per standard contract. */
function dsc_billing_due_dates_for_publish(): array {
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    return [
        'retainer_due_date' => $today->format('Y-m-d'),
        'overage_due_date' => $today->modify('+30 days')->format('Y-m-d'),
    ];
}

/** Flat/tier fee: Net 30 from publish day (Acquire-style). Does not change hourly retainer timing. */
function dsc_billing_flat_fee_due_date(): string {
    return (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d');
}

function dsc_billing_normalize_tier_key(?string $tierKey): string {
    $k = strtolower(trim((string) $tierKey));
    return $k === 'tier2' ? 'tier2' : 'tier1';
}

function dsc_billing_tier_label(string $tierKey): string {
    return dsc_billing_normalize_tier_key($tierKey) === 'tier2' ? 'Tier 2' : 'Tier 1';
}

function dsc_billing_generate_public_token(): string {
    return bin2hex(random_bytes(16));
}

function dsc_billing_canonical_invoice_url(string $publicToken): string {
    $token = trim($publicToken);
    $site = function_exists('dsc_invoicing_public_base_url')
        ? dsc_invoicing_public_base_url()
        : (defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '');
    $path = dsc_invoicing_href('invoice.php?t=' . rawurlencode($token));
    if ($site !== '') {
        return $site . $path;
    }
    return $path;
}

/** Prefer a stored canonical client URL over rebuilding (avoids stale SITE_URL). */
function dsc_billing_client_page_url(array $row): string {
    $stored = trim((string) ($row['public_url'] ?? ''));
    if (
        $stored !== ''
        && str_contains($stored, 'invoice.php')
        && !str_contains($stored, 'localhost')
        && !str_contains($stored, '127.0.0.1')
    ) {
        return $stored;
    }
    $token = trim((string) ($row['public_token'] ?? ''));
    if ($token !== '') {
        return dsc_billing_canonical_invoice_url($token);
    }
    return '';
}

function dsc_billing_square_map_invoice_status(string $squareStatus): string {
    $squareStatus = strtoupper(trim($squareStatus));
    return match ($squareStatus) {
        'PAID', 'PARTIALLY_PAID' => 'paid',
        'UNPAID', 'SCHEDULED', 'SENT', 'VIEWED' => 'published',
        'CANCELED', 'FAILED' => strtolower($squareStatus),
        default => strtolower($squareStatus !== '' ? $squareStatus : 'published'),
    };
}

/**
 * @param array<int, array<string, mixed>> $lineItems
 * @return array{ok:bool, invoice_id?:string, version?:int, public_url?:string, payment_status?:string, error?:string}
 */
function dsc_billing_square_create_publish_invoice(
    string $customerId,
    array $lineItems,
    string $dueDate,
    string $title,
    string $description,
    string $invoiceNumber,
    string $idempotencySeed,
): array {
    $cfg = dsc_invoicing_square_config();
    if (empty($cfg['location_id'])) {
        return ['ok' => false, 'error' => 'Square location_id not configured.'];
    }
    if ($lineItems === []) {
        return ['ok' => false, 'error' => 'No line items for Square invoice.'];
    }

    $orderKey = substr('ord-' . sha1($idempotencySeed . '|order'), 0, 61);
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

    $invKey = substr('inv-' . sha1($idempotencySeed . '|invoice'), 0, 61);
    $invBody = [
        'idempotency_key' => $invKey,
        'invoice' => [
            'location_id' => (string) $cfg['location_id'],
            'order_id' => $orderId,
            'primary_recipient' => ['customer_id' => $customerId],
            'payment_requests' => [
                [
                    'request_type' => 'BALANCE',
                    'due_date' => $dueDate,
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
            'title' => $title,
            'description' => $description,
            'invoice_number' => substr($invoiceNumber, 0, 25),
            'sale_or_service_date' => $dueDate,
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
        ['idempotency_key' => $pubKey, 'version' => $version]
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
    $statusRaw = is_array($invOut) ? (string) ($invOut['status'] ?? '') : '';

    return [
        'ok' => true,
        'invoice_id' => $invoiceId,
        'order_id' => $orderId,
        'version' => $versionOut,
        'public_url' => $publicUrl,
        'payment_status' => dsc_billing_square_map_invoice_status($statusRaw),
    ];
}

/**
 * @return array{payment_status:string, retainer_payment_status:string, overage_payment_status:?string}
 */
function dsc_billing_aggregate_payment_status(string $retainerStatus, ?string $overageStatus, bool $hasOverage): array {
    $retainerStatus = $retainerStatus !== '' ? $retainerStatus : 'published';
    if (!$hasOverage) {
        return [
            'payment_status' => $retainerStatus,
            'retainer_payment_status' => $retainerStatus,
            'overage_payment_status' => null,
        ];
    }
    $overageStatus = $overageStatus ?? 'published';
    $bothPaid = ($retainerStatus === 'paid' && $overageStatus === 'paid');
    $anyPaid = ($retainerStatus === 'paid' || $overageStatus === 'paid');
    $aggregate = $bothPaid ? 'paid' : ($anyPaid ? 'partial' : 'published');
    return [
        'payment_status' => $aggregate,
        'retainer_payment_status' => $retainerStatus,
        'overage_payment_status' => $overageStatus,
    ];
}

function dsc_billing_sync_engagement_stoppage(SQLite3 $db, int $engagementId, string $aggregatePaymentStatus): void {
    if ($engagementId <= 0) {
        return;
    }
    $w = $db->prepare('UPDATE engagements SET work_stoppage = :ws, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $w->bindValue(':ws', $aggregatePaymentStatus === 'paid' ? 0 : 1, SQLITE3_INTEGER);
    $w->bindValue(':id', $engagementId, SQLITE3_INTEGER);
    $w->execute();
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
 *     billing_mode:string,
 *     retainer_month:string,
 *     overage_month:string|null,
 *     retainer_amount_cents:int,
 *     overage_amount_cents:int,
 *     total_cents:int,
 *     hourly_rate_cents:int,
 *     included_hours_per_month:int,
 *     tier_key?:string,
 *     fee_due_date?:string,
 *     tier1_amount_cents?:int,
 *     tier2_amount_cents?:int
 * }|array{error:string}
 */
function dsc_billing_combined_totals(
    SQLite3 $db,
    int $engagementId,
    string $anchorMonth,
    ?string $tierKey = null,
): array {
    if (!dsc_billing_valid_month($anchorMonth)) {
        return ['error' => 'Invalid billing month (use YYYY-MM).'];
    }
    $st = $db->prepare(
        'SELECT e.id, e.hourly_rate_cents, e.included_hours_per_month, e.status, e.name AS engagement_name, '
        . 'COALESCE(e.billing_mode, \'hourly\') AS billing_mode, '
        . 'COALESCE(e.tier1_amount_cents, 0) AS tier1_amount_cents, '
        . 'COALESCE(e.tier2_amount_cents, 0) AS tier2_amount_cents '
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

    $mode = trim((string) ($e['billing_mode'] ?? 'hourly'));
    if ($mode === 'flat_tier') {
        return dsc_billing_flat_tier_totals($e, $anchorMonth, $tierKey);
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
        'billing_mode' => 'hourly',
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
 * @param array<string,mixed> $engagementRow
 * @return array{
 *     billing_mode:string,
 *     retainer_month:string,
 *     overage_month:string|null,
 *     retainer_amount_cents:int,
 *     overage_amount_cents:int,
 *     total_cents:int,
 *     hourly_rate_cents:int,
 *     included_hours_per_month:int,
 *     tier_key:string,
 *     fee_due_date:string,
 *     tier1_amount_cents:int,
 *     tier2_amount_cents:int
 * }|array{error:string}
 */
function dsc_billing_flat_tier_totals(array $engagementRow, string $anchorMonth, ?string $tierKey = null): array {
    $key = dsc_billing_normalize_tier_key($tierKey);
    $t1 = max(0, (int) ($engagementRow['tier1_amount_cents'] ?? 0));
    $t2 = max(0, (int) ($engagementRow['tier2_amount_cents'] ?? 0));
    $amount = $key === 'tier2' ? $t2 : $t1;
    if ($amount <= 0) {
        return [
            'error' => 'Flat/tier engagement has $0 for '
                . dsc_billing_tier_label($key)
                . '. Set Tier 1 / Tier 2 amounts on the engagement.',
        ];
    }
    $prev = dsc_billing_prev_month($anchorMonth);

    return [
        'billing_mode' => 'flat_tier',
        'retainer_month' => $anchorMonth,
        'overage_month' => $prev ?? $anchorMonth,
        'retainer_amount_cents' => $amount,
        'overage_amount_cents' => 0,
        'total_cents' => $amount,
        'hourly_rate_cents' => 0,
        'included_hours_per_month' => 0,
        'tier_key' => $key,
        'fee_due_date' => dsc_billing_flat_fee_due_date(),
        'tier1_amount_cents' => $t1,
        'tier2_amount_cents' => $t2,
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
 * Create split Square invoices (retainer + optional overage), or a single flat/tier fee invoice.
 * Snapshots optional Tasks doc; issues public breakdown page.
 *
 * @return array{ok:bool, message?:string, outbound_id?:int, public_url?:string, canonical_url?:string, error?:string}
 */
function dsc_billing_publish_combined_invoice(
    SQLite3 $db,
    int $engagementId,
    string $anchorMonth,
    ?int $tasksDocumentId = null,
    ?string $tierKey = null,
): array {
    $totals = dsc_billing_combined_totals($db, $engagementId, $anchorMonth, $tierKey);
    if (isset($totals['error'])) {
        return ['ok' => false, 'error' => $totals['error']];
    }
    if ($totals['total_cents'] <= 0) {
        return ['ok' => false, 'error' => 'Nothing to bill (retainer + overage total is zero).'];
    }

    $isFlat = (($totals['billing_mode'] ?? 'hourly') === 'flat_tier');
    $overageMonth = $totals['overage_month'];
    if (!$isFlat && $overageMonth === null) {
        return ['ok' => false, 'error' => 'Could not resolve prior month for overage.'];
    }
    if ($overageMonth === null || $overageMonth === '') {
        $overageMonth = $anchorMonth;
    }

    $docTitle = '';
    $docBody = '';
    if ($tasksDocumentId !== null && $tasksDocumentId > 0) {
        $docFetch = dsc_tasks_fetch_document($tasksDocumentId);
        if (!$docFetch['ok']) {
            return ['ok' => false, 'error' => $docFetch['error'] ?? 'Could not load Tasks document.'];
        }
        $doc = $docFetch['document'] ?? null;
        if (!is_array($doc)) {
            return ['ok' => false, 'error' => 'Tasks document payload missing.'];
        }
        $docTitle = (string) ($doc['title'] ?? '');
        $docBody = (string) ($doc['body'] ?? '');
    } elseif (!$isFlat) {
        return ['ok' => false, 'error' => 'Tasks accounting document id is required before publishing.'];
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

    $dueDates = dsc_billing_due_dates_for_publish();
    $feeDue = $isFlat
        ? (string) ($totals['fee_due_date'] ?? dsc_billing_flat_fee_due_date())
        : $dueDates['retainer_due_date'];
    $publicToken = dsc_billing_generate_public_token();
    $baseInvNum = 'DSC' . preg_replace('/\D/', '', $anchorMonth) . 'e' . $engagementId;
    $flatTierKey = $isFlat ? dsc_billing_normalize_tier_key($totals['tier_key'] ?? $tierKey) : null;
    $flatLabel = $isFlat ? dsc_billing_tier_label((string) $flatTierKey) : '';

    $retainerResult = null;
    if ($totals['retainer_amount_cents'] > 0) {
        if ($isFlat) {
            $retainerResult = dsc_billing_square_create_publish_invoice(
                $customerId,
                [[
                    'name' => $flatLabel . ' program fee — ' . $anchorMonth,
                    'quantity' => '1',
                    'base_price_money' => ['amount' => $totals['retainer_amount_cents'], 'currency' => 'USD'],
                ]],
                $feeDue,
                'DSC ' . $flatLabel . ' — ' . $anchorMonth,
                $flatLabel . ' program fee for ' . $anchorMonth . ' (net 30).',
                $baseInvNum . 'F',
                (string) $engagementId . '|' . $anchorMonth . '|' . $flatTierKey
            );
        } else {
            $retainerResult = dsc_billing_square_create_publish_invoice(
                $customerId,
                [[
                    'name' => 'Monthly retainer — ' . $anchorMonth,
                    'quantity' => '1',
                    'base_price_money' => ['amount' => $totals['retainer_amount_cents'], 'currency' => 'USD'],
                ]],
                $dueDates['retainer_due_date'],
                'DSC retainer — ' . $anchorMonth,
                'Monthly retainer for ' . $anchorMonth . ' (due upon receipt).',
                $baseInvNum . 'R',
                (string) $engagementId . '|' . $anchorMonth . '|retainer'
            );
        }
        if (!$retainerResult['ok']) {
            return ['ok' => false, 'error' => $retainerResult['error'] ?? 'Primary invoice failed.'];
        }
    }

    $overageResult = null;
    if (!$isFlat && $totals['overage_amount_cents'] > 0) {
        $overageResult = dsc_billing_square_create_publish_invoice(
            $customerId,
            [[
                'name' => 'Prior-month overage — ' . $overageMonth,
                'quantity' => '1',
                'base_price_money' => ['amount' => $totals['overage_amount_cents'], 'currency' => 'USD'],
            ]],
            $dueDates['overage_due_date'],
            'DSC overage — ' . $overageMonth,
            'Overage hours for ' . $overageMonth . ' (net 30).',
            $baseInvNum . 'O',
            (string) $engagementId . '|' . $anchorMonth . '|overage'
        );
        if (!$overageResult['ok']) {
            return ['ok' => false, 'error' => $overageResult['error'] ?? 'Overage invoice failed.'];
        }
    }

    $retainerStatus = $retainerResult['payment_status'] ?? 'published';
    $overageStatus = $overageResult !== null ? ($overageResult['payment_status'] ?? 'published') : null;
    $aggregate = dsc_billing_aggregate_payment_status(
        $retainerStatus,
        $overageStatus,
        !$isFlat && $totals['overage_amount_cents'] > 0
    );

    $primarySquareId = $retainerResult['invoice_id'] ?? ($overageResult['invoice_id'] ?? '');
    $primaryPublicUrl = $retainerResult['public_url'] ?? ($overageResult['public_url'] ?? '');
    $primaryVersion = $retainerResult['version'] ?? ($overageResult['version'] ?? 0);
    $primaryOrderId = $retainerResult['order_id'] ?? ($overageResult['order_id'] ?? '');

    $canonicalUrl = dsc_billing_canonical_invoice_url($publicToken);

    try {
        $ins = $db->prepare(
            'INSERT INTO outbound_invoices (engagement_id, anchor_month, overage_month, retainer_amount_cents, '
            . 'overage_amount_cents, total_amount_cents, square_order_id, square_invoice_id, square_invoice_version, '
            . 'public_url, delivery_method, payment_status, public_token, tasks_document_id, tasks_document_title, '
            . 'accounting_markdown, retainer_due_date, overage_due_date, square_retainer_invoice_id, '
            . 'square_overage_invoice_id, retainer_public_url, overage_public_url, retainer_payment_status, '
            . 'overage_payment_status, billing_mode, tier_key, fee_due_date) VALUES '
            . '(:e, :a, :o, :r, :ov, :t, :so, :si, :sv, :pu, :dm, :ps, :pt, :tdi, :tdt, :md, :rd, :od, :sri, :soi, '
            . ':rpu, :opu, :rps, :ops, :bm, :tk, :fd)'
        );
        $ins->bindValue(':e', $engagementId, SQLITE3_INTEGER);
        $ins->bindValue(':a', $anchorMonth, SQLITE3_TEXT);
        $ins->bindValue(':o', (string) $overageMonth, SQLITE3_TEXT);
        $ins->bindValue(':r', $totals['retainer_amount_cents'], SQLITE3_INTEGER);
        $ins->bindValue(':ov', $totals['overage_amount_cents'], SQLITE3_INTEGER);
        $ins->bindValue(':t', $totals['total_cents'], SQLITE3_INTEGER);
        $ins->bindValue(':so', $primaryOrderId, SQLITE3_TEXT);
        $ins->bindValue(':si', $primarySquareId, SQLITE3_TEXT);
        $ins->bindValue(':sv', $primaryVersion, SQLITE3_INTEGER);
        $ins->bindValue(':pu', $canonicalUrl, SQLITE3_TEXT);
        $ins->bindValue(':dm', 'EMAIL', SQLITE3_TEXT);
        $ins->bindValue(':ps', $aggregate['payment_status'], SQLITE3_TEXT);
        $ins->bindValue(':pt', $publicToken, SQLITE3_TEXT);
        if ($tasksDocumentId !== null && $tasksDocumentId > 0) {
            $ins->bindValue(':tdi', $tasksDocumentId, SQLITE3_INTEGER);
        } else {
            $ins->bindValue(':tdi', null, SQLITE3_NULL);
        }
        $ins->bindValue(':tdt', $docTitle, SQLITE3_TEXT);
        $ins->bindValue(':md', $docBody, SQLITE3_TEXT);
        $ins->bindValue(':rd', $isFlat ? $feeDue : $dueDates['retainer_due_date'], SQLITE3_TEXT);
        if (!$isFlat && $totals['overage_amount_cents'] > 0) {
            $ins->bindValue(':od', $dueDates['overage_due_date'], SQLITE3_TEXT);
        } else {
            $ins->bindValue(':od', null, SQLITE3_NULL);
        }
        if (!empty($retainerResult['invoice_id'])) {
            $ins->bindValue(':sri', (string) $retainerResult['invoice_id'], SQLITE3_TEXT);
        } else {
            $ins->bindValue(':sri', null, SQLITE3_NULL);
        }
        if ($overageResult !== null && !empty($overageResult['invoice_id'])) {
            $ins->bindValue(':soi', (string) $overageResult['invoice_id'], SQLITE3_TEXT);
        } else {
            $ins->bindValue(':soi', null, SQLITE3_NULL);
        }
        if ($retainerResult !== null && !empty($retainerResult['public_url'])) {
            $ins->bindValue(':rpu', (string) $retainerResult['public_url'], SQLITE3_TEXT);
        } else {
            $ins->bindValue(':rpu', null, SQLITE3_NULL);
        }
        if ($overageResult !== null && !empty($overageResult['public_url'])) {
            $ins->bindValue(':opu', (string) $overageResult['public_url'], SQLITE3_TEXT);
        } else {
            $ins->bindValue(':opu', null, SQLITE3_NULL);
        }
        $ins->bindValue(':rps', $aggregate['retainer_payment_status'], SQLITE3_TEXT);
        if ($aggregate['overage_payment_status'] === null) {
            $ins->bindValue(':ops', null, SQLITE3_NULL);
        } else {
            $ins->bindValue(':ops', $aggregate['overage_payment_status'], SQLITE3_TEXT);
        }
        $ins->bindValue(':bm', $isFlat ? 'flat_tier' : 'hourly', SQLITE3_TEXT);
        if ($flatTierKey !== null) {
            $ins->bindValue(':tk', $flatTierKey, SQLITE3_TEXT);
        } else {
            $ins->bindValue(':tk', null, SQLITE3_NULL);
        }
        if ($isFlat) {
            $ins->bindValue(':fd', $feeDue, SQLITE3_TEXT);
        } else {
            $ins->bindValue(':fd', null, SQLITE3_NULL);
        }
        $ins->execute();
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'Square publish succeeded but local DB insert failed (duplicate month?): ' . $e->getMessage(),
        ];
    }
    $outboundId = (int) $db->lastInsertRowID();

    if (!$isFlat && $totals['overage_amount_cents'] > 0 && !empty($overageResult['invoice_id'])) {
        $stamp = $db->prepare(
            'UPDATE time_entries SET invoiced_square_invoice_id = :i, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE engagement_id = :e AND billing_period_month = :p '
            . 'AND (invoiced_square_invoice_id IS NULL OR TRIM(invoiced_square_invoice_id) = \'\')'
        );
        $stamp->bindValue(':i', (string) $overageResult['invoice_id'], SQLITE3_TEXT);
        $stamp->bindValue(':e', $engagementId, SQLITE3_INTEGER);
        $stamp->bindValue(':p', $overageMonth, SQLITE3_TEXT);
        $stamp->execute();
    }

    dsc_billing_sync_engagement_stoppage($db, $engagementId, $aggregate['payment_status']);

    return [
        'ok' => true,
        'message' => $isFlat
            ? 'Flat/tier invoice published with client breakdown page.'
            : 'Invoice published with client breakdown page.',
        'outbound_id' => $outboundId,
        'public_url' => $primaryPublicUrl !== '' ? $primaryPublicUrl : null,
        'canonical_url' => $canonicalUrl,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function dsc_billing_get_outbound_by_public_token(SQLite3 $db, string $token): ?array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $st = $db->prepare(
        'SELECT o.*, e.name AS engagement_name, c.name AS company_name '
        . 'FROM outbound_invoices o '
        . 'JOIN engagements e ON e.id = o.engagement_id '
        . 'JOIN companies c ON c.id = e.company_id '
        . 'WHERE o.public_token = :t LIMIT 1'
    );
    $st->bindValue(':t', $token, SQLITE3_TEXT);
    $exe = $st->execute();
    $row = $exe ? $exe->fetchArray(SQLITE3_ASSOC) : false;
    return $row ?: null;
}

/**
 * @return array{ok:bool, payment_status?:string, public_url?:string, error?:string}
 */
function dsc_billing_refresh_square_invoice_component(string $squareInvoiceId): array {
    $squareInvoiceId = trim($squareInvoiceId);
    if ($squareInvoiceId === '') {
        return ['ok' => false, 'error' => 'No square invoice id.'];
    }
    $resp = dsc_invoicing_square_request('GET', '/invoices/' . rawurlencode($squareInvoiceId), null);
    if (!$resp['ok']) {
        return ['ok' => false, 'error' => 'Square retrieve failed: ' . ($resp['error'] ?? 'unknown')];
    }
    $inv = $resp['data']['invoice'] ?? null;
    if (!is_array($inv)) {
        return ['ok' => false, 'error' => 'Square retrieve returned no invoice object.'];
    }
    $paymentStatus = dsc_billing_square_map_invoice_status((string) ($inv['status'] ?? ''));
    $publicUrl = trim((string) ($inv['public_url'] ?? ''));
    $version = isset($inv['version']) ? (int) $inv['version'] : null;
    return [
        'ok' => true,
        'payment_status' => $paymentStatus,
        'public_url' => $publicUrl,
        'version' => $version,
    ];
}

/**
 * Poll Square for one outbound invoice row and refresh local payment status.
 *
 * @return array{ok:bool, payment_status?:string, public_url?:string, error?:string}
 */
function dsc_billing_refresh_outbound_payment_status(SQLite3 $db, int $outboundId): array {
    if ($outboundId <= 0) {
        return ['ok' => false, 'error' => 'Invalid outbound invoice id.'];
    }
    $st = $db->prepare('SELECT * FROM outbound_invoices WHERE id = :id LIMIT 1');
    $st->bindValue(':id', $outboundId, SQLITE3_INTEGER);
    $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'Outbound invoice not found.'];
    }

    $retainerId = trim((string) ($row['square_retainer_invoice_id'] ?? ''));
    if ($retainerId === '') {
        $retainerId = trim((string) ($row['square_invoice_id'] ?? ''));
    }
    $overageId = trim((string) ($row['square_overage_invoice_id'] ?? ''));

    $retainerRefresh = $retainerId !== '' ? dsc_billing_refresh_square_invoice_component($retainerId) : null;
    if ($retainerRefresh !== null && empty($retainerRefresh['ok'])) {
        return ['ok' => false, 'error' => $retainerRefresh['error'] ?? 'Retainer refresh failed.'];
    }
    $overageRefresh = $overageId !== '' ? dsc_billing_refresh_square_invoice_component($overageId) : null;
    if ($overageRefresh !== null && empty($overageRefresh['ok'])) {
        return ['ok' => false, 'error' => $overageRefresh['error'] ?? 'Overage refresh failed.'];
    }

    $retainerStatus = $retainerRefresh['payment_status'] ?? (string) ($row['retainer_payment_status'] ?? $row['payment_status'] ?? 'published');
    $hasOverage = ((int) ($row['overage_amount_cents'] ?? 0)) > 0 && $overageId !== '';
    $overageStatus = $hasOverage
        ? ($overageRefresh['payment_status'] ?? (string) ($row['overage_payment_status'] ?? 'published'))
        : null;
    $aggregate = dsc_billing_aggregate_payment_status($retainerStatus, $overageStatus, $hasOverage);

    $retainerUrl = $retainerRefresh['public_url'] ?? trim((string) ($row['retainer_public_url'] ?? ''));
    $overageUrl = $hasOverage ? ($overageRefresh['public_url'] ?? trim((string) ($row['overage_public_url'] ?? ''))) : '';

    $canonical = dsc_billing_client_page_url($row);
    if ($canonical === '' && !empty($row['public_token'])) {
        $canonical = dsc_billing_canonical_invoice_url((string) $row['public_token']);
    }

    $up = $db->prepare(
        'UPDATE outbound_invoices SET payment_status = :ps, public_url = :pu, '
        . 'retainer_payment_status = :rps, overage_payment_status = :ops, '
        . 'retainer_public_url = :rpu, overage_public_url = :opu, updated_at = CURRENT_TIMESTAMP '
        . 'WHERE id = :id'
    );
    $up->bindValue(':ps', $aggregate['payment_status'], SQLITE3_TEXT);
    $up->bindValue(':pu', $canonical, SQLITE3_TEXT);
    $up->bindValue(':rps', $aggregate['retainer_payment_status'], SQLITE3_TEXT);
    if ($aggregate['overage_payment_status'] === null) {
        $up->bindValue(':ops', null, SQLITE3_NULL);
    } else {
        $up->bindValue(':ops', $aggregate['overage_payment_status'], SQLITE3_TEXT);
    }
    $up->bindValue(':rpu', $retainerUrl !== '' ? $retainerUrl : null, $retainerUrl !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
    $up->bindValue(':opu', $overageUrl !== '' ? $overageUrl : null, $overageUrl !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
    $up->bindValue(':id', $outboundId, SQLITE3_INTEGER);
    $up->execute();

    dsc_billing_sync_engagement_stoppage($db, (int) ($row['engagement_id'] ?? 0), $aggregate['payment_status']);

    return ['ok' => true, 'payment_status' => $aggregate['payment_status'], 'public_url' => $canonical];
}

/**
 * Attach (or refresh) a Tasks accounting document onto an outbound invoice row.
 *
 * @return array{ok:bool, canonical_url?:string, error?:string}
 */
function dsc_billing_attach_tasks_document_to_outbound(SQLite3 $db, int $outboundId, int $tasksDocumentId): array {
    if ($outboundId <= 0) {
        return ['ok' => false, 'error' => 'Invalid outbound invoice id.'];
    }
    $st = $db->prepare('SELECT * FROM outbound_invoices WHERE id = :id LIMIT 1');
    $st->bindValue(':id', $outboundId, SQLITE3_INTEGER);
    $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'Outbound invoice not found.'];
    }

    $fetch = dsc_tasks_fetch_document($tasksDocumentId);
    if (!$fetch['ok']) {
        return ['ok' => false, 'error' => $fetch['error'] ?? 'Tasks document fetch failed.'];
    }
    $doc = $fetch['document'] ?? null;
    if (!is_array($doc)) {
        return ['ok' => false, 'error' => 'Tasks document missing from response.'];
    }

    $token = trim((string) ($row['public_token'] ?? ''));
    if ($token === '') {
        $token = dsc_billing_generate_public_token();
    }
    $canonical = dsc_billing_canonical_invoice_url($token);

    $up = $db->prepare(
        'UPDATE outbound_invoices SET public_token = :pt, public_url = :pu, tasks_document_id = :tdi, '
        . 'tasks_document_title = :tdt, accounting_markdown = :md, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $up->bindValue(':pt', $token, SQLITE3_TEXT);
    $up->bindValue(':pu', $canonical, SQLITE3_TEXT);
    $up->bindValue(':tdi', $tasksDocumentId, SQLITE3_INTEGER);
    $up->bindValue(':tdt', (string) ($doc['title'] ?? ''), SQLITE3_TEXT);
    $up->bindValue(':md', (string) ($doc['body'] ?? ''), SQLITE3_TEXT);
    $up->bindValue(':id', $outboundId, SQLITE3_INTEGER);
    $up->execute();

    return ['ok' => true, 'canonical_url' => $canonical];
}

/**
 * Legacy rows: ensure public token, map Square URL to retainer/overage slot, set due dates if missing.
 */
function dsc_billing_hydrate_legacy_outbound_row(SQLite3 $db, int $outboundId): void {
    $st = $db->prepare('SELECT * FROM outbound_invoices WHERE id = :id LIMIT 1');
    $st->bindValue(':id', $outboundId, SQLITE3_INTEGER);
    $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return;
    }

    $anchor = (string) ($row['anchor_month'] ?? '');
    $isOverageOnly = str_ends_with($anchor, '-O')
        || (((int) ($row['retainer_amount_cents'] ?? 0)) === 0 && ((int) ($row['overage_amount_cents'] ?? 0)) > 0);
    $isRetainerOnly = str_ends_with($anchor, '-R')
        || (((int) ($row['overage_amount_cents'] ?? 0)) === 0 && ((int) ($row['retainer_amount_cents'] ?? 0)) > 0);

    $squareUrl = trim((string) ($row['public_url'] ?? ''));
    $legacySquareUrl = ($squareUrl !== '' && (str_contains($squareUrl, 'squareup.com') || str_contains($squareUrl, 'square.site')))
        ? $squareUrl
        : '';

    $squareId = trim((string) ($row['square_invoice_id'] ?? ''));
    $retainerUrl = trim((string) ($row['retainer_public_url'] ?? ''));
    $overageUrl = trim((string) ($row['overage_public_url'] ?? ''));
    $sri = trim((string) ($row['square_retainer_invoice_id'] ?? ''));
    $soi = trim((string) ($row['square_overage_invoice_id'] ?? ''));
    if ($retainerUrl === '' && $isRetainerOnly && $legacySquareUrl !== '') {
        $retainerUrl = $legacySquareUrl;
    }
    if ($overageUrl === '' && $isOverageOnly && $legacySquareUrl !== '') {
        $overageUrl = $legacySquareUrl;
    }
    if ($sri === '' && $isRetainerOnly && $squareId !== '') {
        $sri = $squareId;
    }
    if ($soi === '' && $isOverageOnly && $squareId !== '') {
        $soi = $squareId;
    }

    $token = trim((string) ($row['public_token'] ?? ''));
    if ($token === '') {
        $token = dsc_billing_generate_public_token();
    }
    $canonical = dsc_billing_canonical_invoice_url($token);

    $due = dsc_billing_due_dates_for_publish();
    $retainerDue = trim((string) ($row['retainer_due_date'] ?? ''));
    if ($retainerDue === '') {
        $retainerDue = $due['retainer_due_date'];
    }
    $overageDue = trim((string) ($row['overage_due_date'] ?? ''));
    if ($overageDue === '' && (int) ($row['overage_amount_cents'] ?? 0) > 0) {
        $overageDue = $due['overage_due_date'];
    }

    $rps = trim((string) ($row['retainer_payment_status'] ?? ''));
    if ($rps === '' && $isRetainerOnly) {
        $rps = (string) ($row['payment_status'] ?? 'published');
    }
    $ops = trim((string) ($row['overage_payment_status'] ?? ''));
    if ($ops === '' && $isOverageOnly) {
        $ops = (string) ($row['payment_status'] ?? 'published');
    }

    $up = $db->prepare(
        'UPDATE outbound_invoices SET public_token = :pt, public_url = :pu, '
        . 'square_retainer_invoice_id = :sri, square_overage_invoice_id = :soi, '
        . 'retainer_public_url = :rpu, overage_public_url = :opu, '
        . 'retainer_due_date = :rd, overage_due_date = :od, '
        . 'retainer_payment_status = :rps, overage_payment_status = :ops, '
        . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $up->bindValue(':pt', $token, SQLITE3_TEXT);
    $up->bindValue(':pu', $canonical, SQLITE3_TEXT);
    $up->bindValue(':sri', $sri !== '' ? $sri : null, $sri !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
    $up->bindValue(':soi', $soi !== '' ? $soi : null, $soi !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
    $up->bindValue(':rpu', $retainerUrl !== '' ? $retainerUrl : null, $retainerUrl !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
    $up->bindValue(':opu', $overageUrl !== '' ? $overageUrl : null, $overageUrl !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
    $up->bindValue(':rd', $retainerDue !== '' ? $retainerDue : null, $retainerDue !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
    $up->bindValue(':od', $overageDue !== '' ? $overageDue : null, $overageDue !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
    $up->bindValue(':rps', $rps !== '' ? $rps : null, $rps !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
    $up->bindValue(':ops', $ops !== '' ? $ops : null, $ops !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
    $up->bindValue(':id', $outboundId, SQLITE3_INTEGER);
    $up->execute();
}

/** @return array{ok:bool, updated:int, errors:list<string>} */
function dsc_billing_backfill_psf_invoice_documents(SQLite3 $db): array {
    $map = [
        3 => 332,
        4 => 332,
    ];
    $updated = 0;
    $errors = [];
    $ids = $db->query('SELECT id FROM outbound_invoices ORDER BY id');
    while ($r = $ids->fetchArray(SQLITE3_ASSOC)) {
        $oid = (int) ($r['id'] ?? 0);
        if ($oid <= 0) {
            continue;
        }
        dsc_billing_hydrate_legacy_outbound_row($db, $oid);
        if (isset($map[$oid])) {
            $res = dsc_billing_attach_tasks_document_to_outbound($db, $oid, $map[$oid]);
            if ($res['ok']) {
                $updated++;
            } else {
                $errors[] = 'outbound #' . $oid . ': ' . ($res['error'] ?? 'attach failed');
            }
        } else {
            $updated++;
        }
    }
    return ['ok' => true, 'updated' => $updated, 'errors' => $errors];
}

/** Rewrite poisoned localhost client URLs from tokens + configured site origin. */
function dsc_billing_repair_localhost_client_urls(SQLite3 $db): int {
    $fixed = 0;
    $st = $db->query('SELECT id, public_url, public_token FROM outbound_invoices');
    while ($row = $st->fetchArray(SQLITE3_ASSOC)) {
        $stored = trim((string) ($row['public_url'] ?? ''));
        $needs = $stored === ''
            || str_contains($stored, 'localhost')
            || str_contains($stored, '127.0.0.1');
        if (!$needs) {
            continue;
        }
        $url = dsc_billing_client_page_url($row);
        if ($url === '' || str_contains($url, 'localhost')) {
            $token = trim((string) ($row['public_token'] ?? ''));
            if ($token !== '') {
                $url = dsc_billing_canonical_invoice_url($token);
            }
        }
        if ($url === '' || str_contains($url, 'localhost')) {
            continue;
        }
        $up = $db->prepare('UPDATE outbound_invoices SET public_url = :pu, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $up->bindValue(':pu', $url, SQLITE3_TEXT);
        $up->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);
        $up->execute();
        $fixed++;
    }
    return $fixed;
}
