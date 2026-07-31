<?php
declare(strict_types=1);

/**
 * JSON API handlers — Kitchen POS pattern: validate key + rate limit + SQLite.
 * POST bodies should pass api_key via header/query or JSON field (resolved by entry script).
 */

if (!function_exists('runInvoicingApiListCompanies')) {

    function runInvoicingApiListCompanies(?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:list_companies:' . $apiKey . ':' . $clientIp, 120, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $db = getDbConnection();
        $r = $db->query(
            'SELECT id, name, billing_email, square_customer_id, notes, created_at, updated_at '
            . 'FROM companies ORDER BY name COLLATE NOCASE'
        );
        $rows = [];
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $row['id'] = (int) $row['id'];
            $rows[] = $row;
        }

        return ['success' => true, 'code' => 200, 'data' => ['companies' => $rows]];
    }

    function runInvoicingApiGetCompany(?string $apiKey, string $clientIp, int $companyId): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:get_company:' . $apiKey . ':' . $clientIp, 120, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        if ($companyId <= 0) {
            return ['success' => false, 'code' => 400, 'error' => 'Invalid company id'];
        }
        $db = getDbConnection();
        $st = $db->prepare(
            'SELECT id, name, billing_email, square_customer_id, notes, created_at, updated_at '
            . 'FROM companies WHERE id = :id'
        );
        $st->bindValue(':id', $companyId, SQLITE3_INTEGER);
        $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$row) {
            return ['success' => false, 'code' => 404, 'error' => 'Company not found'];
        }
        $row['id'] = (int) $row['id'];

        return ['success' => true, 'code' => 200, 'data' => ['company' => $row]];
    }

    /**
     * @param array<string,mixed> $data
     */
    function runInvoicingApiCreateCompany(array $data, ?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:create_company:' . $apiKey . ':' . $clientIp, 60, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'code' => 400, 'error' => 'name is required'];
        }
        $db = getDbConnection();
        $stmt = $db->prepare(
            'INSERT INTO companies (name, billing_email, square_customer_id, notes) VALUES (:n, :e, :s, :t)'
        );
        $stmt->bindValue(':n', $name, SQLITE3_TEXT);
        $stmt->bindValue(':e', trim((string) ($data['billing_email'] ?? '')), SQLITE3_TEXT);
        $stmt->bindValue(':s', trim((string) ($data['square_customer_id'] ?? '')), SQLITE3_TEXT);
        $stmt->bindValue(':t', trim((string) ($data['notes'] ?? '')), SQLITE3_TEXT);
        try {
            $stmt->execute();
        } catch (Throwable $e) {
            return ['success' => false, 'code' => 409, 'error' => 'Could not create company (duplicate name?)'];
        }
        $id = (int) $db->lastInsertRowID();

        return [
            'success' => true,
            'code' => 201,
            'data' => ['message' => 'Company created', 'company_id' => $id],
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    function runInvoicingApiUpdateCompany(array $data, ?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:update_company:' . $apiKey . ':' . $clientIp, 60, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $id = (int) ($data['company_id'] ?? $data['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'code' => 400, 'error' => 'company_id required'];
        }
        $db = getDbConnection();
        $st = $db->prepare('SELECT id FROM companies WHERE id = :id');
        $st->bindValue(':id', $id, SQLITE3_INTEGER);
        if (!$st->execute()->fetchArray(SQLITE3_ASSOC)) {
            return ['success' => false, 'code' => 404, 'error' => 'Company not found'];
        }
        $fields = [];
        if (array_key_exists('name', $data)) {
            $fields['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('billing_email', $data)) {
            $fields['billing_email'] = trim((string) $data['billing_email']);
        }
        if (array_key_exists('square_customer_id', $data)) {
            $fields['square_customer_id'] = trim((string) $data['square_customer_id']);
        }
        if (array_key_exists('notes', $data)) {
            $fields['notes'] = trim((string) $data['notes']);
        }
        if ($fields === []) {
            return ['success' => false, 'code' => 400, 'error' => 'No fields to update'];
        }
        if (isset($fields['name']) && $fields['name'] === '') {
            return ['success' => false, 'code' => 400, 'error' => 'name cannot be empty'];
        }
        $sets = [];
        foreach ($fields as $k => $v) {
            $sets[] = $k . ' = :' . $k;
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $sql = 'UPDATE companies SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $up = $db->prepare($sql);
        $up->bindValue(':id', $id, SQLITE3_INTEGER);
        foreach ($fields as $k => $v) {
            $up->bindValue(':' . $k, $v, SQLITE3_TEXT);
        }
        try {
            $up->execute();
        } catch (Throwable $e) {
            return ['success' => false, 'code' => 409, 'error' => 'Update failed (duplicate name?)'];
        }

        return ['success' => true, 'code' => 200, 'data' => ['message' => 'Company updated', 'company_id' => $id]];
    }

    /**
     * @param array<string,mixed> $data
     */
    function runInvoicingApiDeleteCompany(array $data, ?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:delete_company:' . $apiKey . ':' . $clientIp, 30, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $id = (int) ($data['company_id'] ?? $data['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'code' => 400, 'error' => 'company_id required'];
        }
        $db = getDbConnection();
        $del = $db->prepare('DELETE FROM companies WHERE id = :id');
        $del->bindValue(':id', $id, SQLITE3_INTEGER);
        $del->execute();
        if ($db->changes() === 0) {
            return ['success' => false, 'code' => 404, 'error' => 'Company not found'];
        }

        return ['success' => true, 'code' => 200, 'data' => ['message' => 'Company deleted', 'company_id' => $id]];
    }

    function runInvoicingApiListEngagements(
        ?string $apiKey,
        string $clientIp,
        ?int $companyId,
    ): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:list_engagements:' . $apiKey . ':' . $clientIp, 120, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $db = getDbConnection();
        if ($companyId !== null && $companyId > 0) {
            $st = $db->prepare(
                'SELECT id, company_id, name, hourly_rate_cents, included_hours_per_month, status, '
                . 'square_subscription_id, work_stoppage, '
                . 'COALESCE(billing_mode, \'hourly\') AS billing_mode, '
                . 'COALESCE(tier1_amount_cents, 0) AS tier1_amount_cents, '
                . 'COALESCE(tier2_amount_cents, 0) AS tier2_amount_cents, '
                . 'created_at, updated_at '
                . 'FROM engagements WHERE company_id = :c ORDER BY name COLLATE NOCASE'
            );
            $st->bindValue(':c', $companyId, SQLITE3_INTEGER);
            $r = $st->execute();
        } else {
            $r = $db->query(
                'SELECT id, company_id, name, hourly_rate_cents, included_hours_per_month, status, '
                . 'square_subscription_id, work_stoppage, '
                . 'COALESCE(billing_mode, \'hourly\') AS billing_mode, '
                . 'COALESCE(tier1_amount_cents, 0) AS tier1_amount_cents, '
                . 'COALESCE(tier2_amount_cents, 0) AS tier2_amount_cents, '
                . 'created_at, updated_at '
                . 'FROM engagements ORDER BY company_id, name COLLATE NOCASE'
            );
        }
        $rows = [];
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $row['id'] = (int) $row['id'];
            $row['company_id'] = (int) $row['company_id'];
            $row['hourly_rate_cents'] = (int) $row['hourly_rate_cents'];
            $row['included_hours_per_month'] = (int) $row['included_hours_per_month'];
            $row['work_stoppage'] = (int) $row['work_stoppage'];
            $row['tier1_amount_cents'] = (int) $row['tier1_amount_cents'];
            $row['tier2_amount_cents'] = (int) $row['tier2_amount_cents'];
            $rows[] = $row;
        }

        return ['success' => true, 'code' => 200, 'data' => ['engagements' => $rows]];
    }

    function runInvoicingApiGetEngagement(?string $apiKey, string $clientIp, int $engagementId): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:get_engagement:' . $apiKey . ':' . $clientIp, 120, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        if ($engagementId <= 0) {
            return ['success' => false, 'code' => 400, 'error' => 'Invalid engagement id'];
        }
        $db = getDbConnection();
        $st = $db->prepare(
            'SELECT id, company_id, name, hourly_rate_cents, included_hours_per_month, status, '
            . 'square_subscription_id, work_stoppage, '
            . 'COALESCE(billing_mode, \'hourly\') AS billing_mode, '
            . 'COALESCE(tier1_amount_cents, 0) AS tier1_amount_cents, '
            . 'COALESCE(tier2_amount_cents, 0) AS tier2_amount_cents, '
            . 'created_at, updated_at '
            . 'FROM engagements WHERE id = :id'
        );
        $st->bindValue(':id', $engagementId, SQLITE3_INTEGER);
        $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$row) {
            return ['success' => false, 'code' => 404, 'error' => 'Engagement not found'];
        }
        $row['id'] = (int) $row['id'];
        $row['company_id'] = (int) $row['company_id'];
        $row['hourly_rate_cents'] = (int) $row['hourly_rate_cents'];
        $row['included_hours_per_month'] = (int) $row['included_hours_per_month'];
        $row['work_stoppage'] = (int) $row['work_stoppage'];
        $row['tier1_amount_cents'] = (int) $row['tier1_amount_cents'];
        $row['tier2_amount_cents'] = (int) $row['tier2_amount_cents'];

        return ['success' => true, 'code' => 200, 'data' => ['engagement' => $row]];
    }

    /**
     * @param array<string,mixed> $data
     */
    function runInvoicingApiCreateEngagement(array $data, ?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:create_engagement:' . $apiKey . ':' . $clientIp, 60, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $companyId = (int) ($data['company_id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        if ($companyId <= 0 || $name === '') {
            return ['success' => false, 'code' => 400, 'error' => 'company_id and name required'];
        }
        $db = getDbConnection();
        $chk = $db->prepare('SELECT id FROM companies WHERE id = :id');
        $chk->bindValue(':id', $companyId, SQLITE3_INTEGER);
        if (!$chk->execute()->fetchArray(SQLITE3_ASSOC)) {
            return ['success' => false, 'code' => 404, 'error' => 'Company not found'];
        }
        $hourly = isset($data['hourly_rate_cents']) ? (int) $data['hourly_rate_cents'] : 10000;
        $included = isset($data['included_hours_per_month']) ? (int) $data['included_hours_per_month'] : 0;
        $status = trim((string) ($data['status'] ?? 'active'));
        if ($status === '') {
            $status = 'active';
        }
        $billingMode = trim((string) ($data['billing_mode'] ?? 'hourly'));
        if ($billingMode !== 'flat_tier') {
            $billingMode = 'hourly';
        }
        $t1 = isset($data['tier1_amount_cents']) ? (int) $data['tier1_amount_cents'] : 0;
        $t2 = isset($data['tier2_amount_cents']) ? (int) $data['tier2_amount_cents'] : 0;
        $ins = $db->prepare(
            'INSERT INTO engagements (company_id, name, hourly_rate_cents, included_hours_per_month, status, '
            . 'square_subscription_id, billing_mode, tier1_amount_cents, tier2_amount_cents) '
            . 'VALUES (:c, :n, :h, :i, :s, :q, :bm, :t1, :t2)'
        );
        $ins->bindValue(':c', $companyId, SQLITE3_INTEGER);
        $ins->bindValue(':n', $name, SQLITE3_TEXT);
        $ins->bindValue(':h', $hourly, SQLITE3_INTEGER);
        $ins->bindValue(':i', $included, SQLITE3_INTEGER);
        $ins->bindValue(':s', $status, SQLITE3_TEXT);
        $ins->bindValue(':q', trim((string) ($data['square_subscription_id'] ?? '')), SQLITE3_TEXT);
        $ins->bindValue(':bm', $billingMode, SQLITE3_TEXT);
        $ins->bindValue(':t1', $t1, SQLITE3_INTEGER);
        $ins->bindValue(':t2', $t2, SQLITE3_INTEGER);
        $ins->execute();
        $id = (int) $db->lastInsertRowID();

        return [
            'success' => true,
            'code' => 201,
            'data' => ['message' => 'Engagement created', 'engagement_id' => $id],
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    function runInvoicingApiUpdateEngagement(array $data, ?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:update_engagement:' . $apiKey . ':' . $clientIp, 60, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $id = (int) ($data['engagement_id'] ?? $data['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'code' => 400, 'error' => 'engagement_id required'];
        }
        $db = getDbConnection();
        $st = $db->prepare('SELECT id FROM engagements WHERE id = :id');
        $st->bindValue(':id', $id, SQLITE3_INTEGER);
        if (!$st->execute()->fetchArray(SQLITE3_ASSOC)) {
            return ['success' => false, 'code' => 404, 'error' => 'Engagement not found'];
        }
        $allowed = [
            'company_id' => SQLITE3_INTEGER,
            'name' => SQLITE3_TEXT,
            'hourly_rate_cents' => SQLITE3_INTEGER,
            'included_hours_per_month' => SQLITE3_INTEGER,
            'status' => SQLITE3_TEXT,
            'square_subscription_id' => SQLITE3_TEXT,
            'work_stoppage' => SQLITE3_INTEGER,
            'billing_mode' => SQLITE3_TEXT,
            'tier1_amount_cents' => SQLITE3_INTEGER,
            'tier2_amount_cents' => SQLITE3_INTEGER,
            'tasks_project_id' => SQLITE3_INTEGER,
            'tasks_directory_path' => SQLITE3_TEXT,
        ];
        $updates = [];
        foreach ($allowed as $col => $typ) {
            if (array_key_exists($col, $data)) {
                $updates[$col] = $typ;
            }
        }
        if ($updates === []) {
            return ['success' => false, 'code' => 400, 'error' => 'No fields to update'];
        }
        if (isset($data['billing_mode'])) {
            $bm = trim((string) $data['billing_mode']);
            $data['billing_mode'] = $bm === 'flat_tier' ? 'flat_tier' : 'hourly';
        }
        $sets = [];
        foreach ($updates as $col => $typ) {
            $sets[] = $col . ' = :' . $col;
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $sql = 'UPDATE engagements SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $up = $db->prepare($sql);
        $up->bindValue(':id', $id, SQLITE3_INTEGER);
        foreach ($updates as $col => $typ) {
            $v = $data[$col];
            if ($col === 'name' || $col === 'square_subscription_id' || $col === 'status'
                || $col === 'billing_mode' || $col === 'tasks_directory_path') {
                $v = trim((string) $v);
            }
            if ($col === 'tasks_project_id' && ((int) $v) <= 0) {
                $up->bindValue(':' . $col, null, SQLITE3_NULL);
                continue;
            }
            $up->bindValue(':' . $col, $v, $typ);
        }
        $up->execute();

        return ['success' => true, 'code' => 200, 'data' => ['message' => 'Engagement updated', 'engagement_id' => $id]];
    }

    /**
     * @param array<string,mixed> $data
     */
    function runInvoicingApiDeleteEngagement(array $data, ?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:delete_engagement:' . $apiKey . ':' . $clientIp, 30, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $id = (int) ($data['engagement_id'] ?? $data['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'code' => 400, 'error' => 'engagement_id required'];
        }
        $db = getDbConnection();
        $del = $db->prepare('DELETE FROM engagements WHERE id = :id');
        $del->bindValue(':id', $id, SQLITE3_INTEGER);
        $del->execute();
        if ($db->changes() === 0) {
            return ['success' => false, 'code' => 404, 'error' => 'Engagement not found'];
        }

        return ['success' => true, 'code' => 200, 'data' => ['message' => 'Engagement deleted', 'engagement_id' => $id]];
    }

    function runInvoicingApiListTimeEntries(
        ?string $apiKey,
        string $clientIp,
        ?int $engagementId,
        ?string $billingMonth,
        int $limit,
        int $offset,
    ): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:list_time:' . $apiKey . ':' . $clientIp, 120, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $db = getDbConnection();
        $sql =
            'SELECT id, engagement_id, worked_date, hours, memo, billing_period_month, invoiced_square_invoice_id, '
            . 'created_at, updated_at FROM time_entries WHERE 1=1';
        $params = [];
        if ($engagementId !== null && $engagementId > 0) {
            $sql .= ' AND engagement_id = :e';
            $params[':e'] = [$engagementId, SQLITE3_INTEGER];
        }
        if ($billingMonth !== null && $billingMonth !== '') {
            $sql .= ' AND billing_period_month = :m';
            $params[':m'] = [$billingMonth, SQLITE3_TEXT];
        }
        $sql .= ' ORDER BY worked_date DESC, id DESC LIMIT :lim OFFSET :off';
        $st = $db->prepare($sql);
        foreach ($params as $ph => $pair) {
            $st->bindValue($ph, $pair[0], $pair[1]);
        }
        $st->bindValue(':lim', $limit, SQLITE3_INTEGER);
        $st->bindValue(':off', $offset, SQLITE3_INTEGER);
        $r = $st->execute();
        $rows = [];
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $row['id'] = (int) $row['id'];
            $row['engagement_id'] = (int) $row['engagement_id'];
            $row['hours'] = (float) $row['hours'];
            $rows[] = $row;
        }

        return ['success' => true, 'code' => 200, 'data' => ['time_entries' => $rows, 'limit' => $limit, 'offset' => $offset]];
    }

    function runInvoicingApiGetTimeEntry(?string $apiKey, string $clientIp, int $entryId): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:get_time:' . $apiKey . ':' . $clientIp, 120, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        if ($entryId <= 0) {
            return ['success' => false, 'code' => 400, 'error' => 'Invalid id'];
        }
        $db = getDbConnection();
        $st = $db->prepare(
            'SELECT id, engagement_id, worked_date, hours, memo, billing_period_month, invoiced_square_invoice_id, '
            . 'created_at, updated_at FROM time_entries WHERE id = :id'
        );
        $st->bindValue(':id', $entryId, SQLITE3_INTEGER);
        $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$row) {
            return ['success' => false, 'code' => 404, 'error' => 'Time entry not found'];
        }
        $row['id'] = (int) $row['id'];
        $row['engagement_id'] = (int) $row['engagement_id'];
        $row['hours'] = (float) $row['hours'];

        return ['success' => true, 'code' => 200, 'data' => ['time_entry' => $row]];
    }

    /**
     * @param array<string,mixed> $data
     */
    function runInvoicingApiCreateTimeEntry(array $data, ?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:create_time:' . $apiKey . ':' . $clientIp, 90, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $engId = (int) ($data['engagement_id'] ?? 0);
        $worked = trim((string) ($data['worked_date'] ?? ''));
        $hours = isset($data['hours']) ? (float) $data['hours'] : 0.0;
        $month = trim((string) ($data['billing_period_month'] ?? ''));
        if ($engId <= 0 || $worked === '' || $month === '') {
            return ['success' => false, 'code' => 400, 'error' => 'engagement_id, worked_date, billing_period_month required'];
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return ['success' => false, 'code' => 400, 'error' => 'billing_period_month must be YYYY-MM'];
        }
        $db = getDbConnection();
        $chk = $db->prepare('SELECT id FROM engagements WHERE id = :id');
        $chk->bindValue(':id', $engId, SQLITE3_INTEGER);
        if (!$chk->execute()->fetchArray(SQLITE3_ASSOC)) {
            return ['success' => false, 'code' => 404, 'error' => 'Engagement not found'];
        }
        $ins = $db->prepare(
            'INSERT INTO time_entries (engagement_id, worked_date, hours, memo, billing_period_month) '
            . 'VALUES (:e, :w, :h, :memo, :m)'
        );
        $ins->bindValue(':e', $engId, SQLITE3_INTEGER);
        $ins->bindValue(':w', $worked, SQLITE3_TEXT);
        $ins->bindValue(':h', $hours, SQLITE3_FLOAT);
        $ins->bindValue(':memo', trim((string) ($data['memo'] ?? '')), SQLITE3_TEXT);
        $ins->bindValue(':m', $month, SQLITE3_TEXT);
        $ins->execute();
        $id = (int) $db->lastInsertRowID();

        return ['success' => true, 'code' => 201, 'data' => ['message' => 'Time entry created', 'time_entry_id' => $id]];
    }

    /**
     * @param array<string,mixed> $data
     */
    function runInvoicingApiUpdateTimeEntry(array $data, ?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:update_time:' . $apiKey . ':' . $clientIp, 90, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $id = (int) ($data['time_entry_id'] ?? $data['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'code' => 400, 'error' => 'time_entry_id required'];
        }
        $db = getDbConnection();
        $st = $db->prepare('SELECT id FROM time_entries WHERE id = :id');
        $st->bindValue(':id', $id, SQLITE3_INTEGER);
        if (!$st->execute()->fetchArray(SQLITE3_ASSOC)) {
            return ['success' => false, 'code' => 404, 'error' => 'Time entry not found'];
        }
        $allowed = ['worked_date', 'hours', 'memo', 'billing_period_month', 'invoiced_square_invoice_id'];
        $sets = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[$col] = true;
            }
        }
        if ($sets === []) {
            return ['success' => false, 'code' => 400, 'error' => 'No fields to update'];
        }

        $sql = 'UPDATE time_entries SET ';
        $parts = [];
        foreach (array_keys($sets) as $col) {
            $parts[] = $col . ' = :' . $col;
        }
        $parts[] = 'updated_at = CURRENT_TIMESTAMP';
        $sql .= implode(', ', $parts) . ' WHERE id = :id';
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        foreach (array_keys($sets) as $col) {
            $v = $data[$col];
            if ($col === 'hours') {
                $stmt->bindValue(':' . $col, (float) $v, SQLITE3_FLOAT);
            } elseif ($col === 'memo' || $col === 'worked_date' || $col === 'billing_period_month' || $col === 'invoiced_square_invoice_id') {
                $stmt->bindValue(':' . $col, trim((string) $v), SQLITE3_TEXT);
            }
        }
        $stmt->execute();

        return ['success' => true, 'code' => 200, 'data' => ['message' => 'Time entry updated', 'time_entry_id' => $id]];
    }

    /**
     * @param array<string,mixed> $data
     */
    function runInvoicingApiDeleteTimeEntry(array $data, ?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:delete_time:' . $apiKey . ':' . $clientIp, 60, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $id = (int) ($data['time_entry_id'] ?? $data['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'code' => 400, 'error' => 'time_entry_id required'];
        }
        $db = getDbConnection();
        $del = $db->prepare('DELETE FROM time_entries WHERE id = :id');
        $del->bindValue(':id', $id, SQLITE3_INTEGER);
        $del->execute();
        if ($db->changes() === 0) {
            return ['success' => false, 'code' => 404, 'error' => 'Time entry not found'];
        }

        return ['success' => true, 'code' => 200, 'data' => ['message' => 'Time entry deleted', 'time_entry_id' => $id]];
    }

    function runInvoicingApiListOutboundInvoices(
        ?string $apiKey,
        string $clientIp,
        ?int $engagementId,
        int $limit,
        int $offset,
    ): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:list_outbound:' . $apiKey . ':' . $clientIp, 120, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $db = getDbConnection();
        $sql =
            'SELECT id, engagement_id, anchor_month, overage_month, retainer_amount_cents, overage_amount_cents, '
            . 'total_amount_cents, square_order_id, square_invoice_id, square_invoice_version, public_url, '
            . 'delivery_method, payment_status, created_at, updated_at FROM outbound_invoices WHERE 1=1';
        if ($engagementId !== null && $engagementId > 0) {
            $sql .= ' AND engagement_id = ' . (int) $engagementId;
        }
        $sql .= ' ORDER BY anchor_month DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $r = $db->query($sql);
        $rows = [];
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $row['id'] = (int) $row['id'];
            $row['engagement_id'] = (int) $row['engagement_id'];
            $row['retainer_amount_cents'] = (int) $row['retainer_amount_cents'];
            $row['overage_amount_cents'] = (int) $row['overage_amount_cents'];
            $row['total_amount_cents'] = (int) $row['total_amount_cents'];
            $row['square_invoice_version'] = (int) $row['square_invoice_version'];
            $rows[] = $row;
        }

        return ['success' => true, 'code' => 200, 'data' => ['outbound_invoices' => $rows]];
    }

    function runInvoicingApiGetOutboundInvoice(?string $apiKey, string $clientIp, int $outboundId): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:get_outbound:' . $apiKey . ':' . $clientIp, 120, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        if ($outboundId <= 0) {
            return ['success' => false, 'code' => 400, 'error' => 'Invalid id'];
        }
        $db = getDbConnection();
        $st = $db->prepare(
            'SELECT id, engagement_id, anchor_month, overage_month, retainer_amount_cents, overage_amount_cents, '
            . 'total_amount_cents, square_order_id, square_invoice_id, square_invoice_version, public_url, '
            . 'delivery_method, payment_status, created_at, updated_at FROM outbound_invoices WHERE id = :id'
        );
        $st->bindValue(':id', $outboundId, SQLITE3_INTEGER);
        $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$row) {
            return ['success' => false, 'code' => 404, 'error' => 'Outbound invoice not found'];
        }
        $row['id'] = (int) $row['id'];
        $row['engagement_id'] = (int) $row['engagement_id'];
        $row['retainer_amount_cents'] = (int) $row['retainer_amount_cents'];
        $row['overage_amount_cents'] = (int) $row['overage_amount_cents'];
        $row['total_amount_cents'] = (int) $row['total_amount_cents'];
        $row['square_invoice_version'] = (int) $row['square_invoice_version'];

        return ['success' => true, 'code' => 200, 'data' => ['outbound_invoice' => $row]];
    }

    /**
     * @param array<string,mixed> $data
     */
    function runInvoicingApiPublishCombinedInvoice(array $data, ?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:publish_invoice:' . $apiKey . ':' . $clientIp, 10, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $engId = (int) ($data['engagement_id'] ?? 0);
        $anchor = trim((string) ($data['anchor_month'] ?? ''));
        $tasksDocId = (int) ($data['tasks_document_id'] ?? 0);
        $tierKey = isset($data['tier_key']) ? trim((string) $data['tier_key']) : null;
        if ($engId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $anchor)) {
            return ['success' => false, 'code' => 400, 'error' => 'engagement_id and anchor_month (YYYY-MM) required'];
        }
        require_once __DIR__ . '/../../includes/billing.php';
        $db = getDbConnection();
        $modeSt = $db->prepare('SELECT COALESCE(billing_mode, \'hourly\') AS billing_mode FROM engagements WHERE id = :id');
        $modeSt->bindValue(':id', $engId, SQLITE3_INTEGER);
        $modeRow = $modeSt->execute()->fetchArray(SQLITE3_ASSOC);
        $isFlat = $modeRow && (($modeRow['billing_mode'] ?? '') === 'flat_tier');
        if (!$isFlat && $tasksDocId <= 0) {
            return ['success' => false, 'code' => 400, 'error' => 'tasks_document_id is required'];
        }
        $res = dsc_billing_publish_combined_invoice(
            $db,
            $engId,
            $anchor,
            $tasksDocId > 0 ? $tasksDocId : null,
            $tierKey
        );
        if (empty($res['ok'])) {
            $msg = $res['error'] ?? 'Publish failed';
            $code = str_contains(strtolower($msg), 'not configured') ? 503 : 400;

            return ['success' => false, 'code' => $code, 'error' => $msg];
        }

        return [
            'success' => true,
            'code' => 200,
            'data' => [
                'message' => $res['message'] ?? 'Published',
                'outbound_id' => $res['outbound_id'] ?? null,
                'public_url' => $res['public_url'] ?? null,
                'canonical_url' => $res['canonical_url'] ?? null,
            ],
        ];
    }

    function runInvoicingApiRefreshOutboundInvoice(?string $apiKey, string $clientIp, array $data): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:refresh_outbound:' . $apiKey . ':' . $clientIp, 60, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $id = (int) ($data['outbound_id'] ?? $data['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'code' => 400, 'error' => 'outbound_id required'];
        }
        require_once __DIR__ . '/../../includes/billing.php';
        $res = dsc_billing_refresh_outbound_payment_status(getDbConnection(), $id);
        if (empty($res['ok'])) {
            return ['success' => false, 'code' => 400, 'error' => $res['error'] ?? 'Refresh failed'];
        }
        return [
            'success' => true,
            'code' => 200,
            'data' => [
                'outbound_id' => $id,
                'payment_status' => $res['payment_status'] ?? null,
                'public_url' => $res['public_url'] ?? null,
            ],
        ];
    }

    function runInvoicingApiAttachTasksDocument(?string $apiKey, string $clientIp, array $data): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:attach_tasks_doc:' . $apiKey . ':' . $clientIp, 30, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $id = (int) ($data['outbound_id'] ?? $data['id'] ?? 0);
        $docId = (int) ($data['tasks_document_id'] ?? 0);
        if ($id <= 0 || $docId <= 0) {
            return ['success' => false, 'code' => 400, 'error' => 'outbound_id and tasks_document_id required'];
        }
        require_once __DIR__ . '/../../includes/billing.php';
        $db = getDbConnection();
        dsc_billing_hydrate_legacy_outbound_row($db, $id);
        $res = dsc_billing_attach_tasks_document_to_outbound($db, $id, $docId);
        if (empty($res['ok'])) {
            return ['success' => false, 'code' => 400, 'error' => $res['error'] ?? 'Attach failed'];
        }
        return [
            'success' => true,
            'code' => 200,
            'data' => ['outbound_id' => $id, 'canonical_url' => $res['canonical_url'] ?? null],
        ];
    }

    function runInvoicingApiCancelOutboundInvoice(?string $apiKey, string $clientIp, array $data): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:cancel_outbound:' . $apiKey . ':' . $clientIp, 20, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $id = (int) ($data['outbound_id'] ?? $data['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'code' => 400, 'error' => 'outbound_id required'];
        }
        require_once __DIR__ . '/../../includes/billing.php';
        require_once __DIR__ . '/../../includes/audit.php';
        $res = dsc_billing_cancel_outbound_invoice(getDbConnection(), $id);
        if (empty($res['ok'])) {
            return ['success' => false, 'code' => 400, 'error' => $res['error'] ?? 'Cancel failed'];
        }
        dsc_invoicing_audit_log(
            'outbound.cancel',
            'api',
            'outbound_invoice',
            (string) $id,
            ['payment_status' => $res['payment_status'] ?? null, 'canceled' => $res['canceled'] ?? []]
        );
        return [
            'success' => true,
            'code' => 200,
            'data' => [
                'outbound_id' => $id,
                'payment_status' => $res['payment_status'] ?? null,
                'canceled_square_ids' => $res['canceled'] ?? [],
            ],
        ];
    }

    function runInvoicingApiListUnpaidAging(?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:list_unpaid:' . $apiKey . ':' . $clientIp, 60, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        require_once __DIR__ . '/../../includes/billing.php';
        $rows = dsc_billing_list_unpaid_aging(getDbConnection());
        return ['success' => true, 'code' => 200, 'data' => ['invoices' => $rows, 'count' => count($rows)]];
    }

    function runInvoicingApiListAuditLog(?string $apiKey, string $clientIp, int $limit, int $offset): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:list_audit:' . $apiKey . ':' . $clientIp, 60, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        require_once __DIR__ . '/../../includes/audit.php';
        return [
            'success' => true,
            'code' => 200,
            'data' => [
                'entries' => dsc_invoicing_audit_log_list($limit, $offset),
                'total' => dsc_invoicing_audit_log_count(),
            ],
        ];
    }

    function runInvoicingApiListConfig(?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:list_config:' . $apiKey . ':' . $clientIp, 30, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $db = getDbConnection();
        $r = $db->query('SELECT key, value FROM config ORDER BY key COLLATE NOCASE');
        $rows = [];
        $secretKeys = ['square_access_token', 'tasks_dsc_api_key', 'square_webhook_signature_key'];
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $k = (string) ($row['key'] ?? '');
            $v = (string) ($row['value'] ?? '');
            if (in_array($k, $secretKeys, true) && $v !== '') {
                $v = '••••' . substr($v, -4);
            }
            $rows[] = ['key' => $k, 'value' => $v];
        }
        return ['success' => true, 'code' => 200, 'data' => ['config' => $rows]];
    }

    function runInvoicingApiListApiKeysMeta(?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:list_keys:' . $apiKey . ':' . $clientIp, 30, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $db = getDbConnection();
        $r = $db->query(
            'SELECT id, name, substr(api_key, 1, 8) || \'…\' AS api_key_prefix, created_at, last_used '
            . 'FROM api_keys ORDER BY id DESC'
        );
        $rows = [];
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $row['id'] = (int) $row['id'];
            $rows[] = $row;
        }
        return ['success' => true, 'code' => 200, 'data' => ['api_keys' => $rows]];
    }

    function runInvoicingApiListAdminUsers(?string $apiKey, string $clientIp): array {
        if (!$apiKey || !validateApiKey($apiKey)) {
            return ['success' => false, 'code' => 401, 'error' => 'Invalid or missing API key'];
        }
        if (!checkRateLimit('inv_api:list_users:' . $apiKey . ':' . $clientIp, 30, 60)) {
            return ['success' => false, 'code' => 429, 'error' => 'Rate limit exceeded'];
        }
        $db = getDbConnection();
        $r = $db->query(
            'SELECT id, username, is_active, created_at FROM admin_users ORDER BY username COLLATE NOCASE'
        );
        $rows = [];
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $row['id'] = (int) $row['id'];
            $row['is_active'] = (int) ($row['is_active'] ?? 0);
            $rows[] = $row;
        }
        return ['success' => true, 'code' => 200, 'data' => ['users' => $rows]];
    }

}
