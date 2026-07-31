<?php
// Loaded after config.php (defines DB_PATH, PASSWORD_COST, …).

function getDbConnection(): SQLite3 {
    $g = &$GLOBALS['_dsc_invoicing_sqlite3'];
    if (isset($g) && $g instanceof SQLite3) {
        return $g;
    }
    $dbDir = dirname(DB_PATH);
    if (!is_dir($dbDir)) {
        @mkdir($dbDir, 0755, true);
    }
    try {
        $g = new SQLite3(DB_PATH);
        $g->enableExceptions(true);
        $g->busyTimeout(DB_TIMEOUT * 1000);
        $g->exec('PRAGMA foreign_keys = ON');
        return $g;
    } catch (Throwable $e) {
        error_log('Invoicing database connection failed: ' . $e->getMessage());
        http_response_code(500);
        die('Service unavailable.');
    }
}

/**
 * @return array{ok:bool, message:string}
 */
function checkDatabaseHealth(): array {
    if (!defined('DB_PATH')) {
        return ['ok' => false, 'message' => 'DB_PATH not defined.'];
    }
    try {
        $db = new SQLite3(DB_PATH);
        $db->enableExceptions(true);
        $db->querySingle('SELECT 1');
        return ['ok' => true, 'message' => 'Connected.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function initializeDatabase(): void {
    $db = getDbConnection();

    $db->exec("
        CREATE TABLE IF NOT EXISTS config (
            key TEXT PRIMARY KEY,
            value TEXT
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $r = $db->query("SELECT COUNT(*) as c FROM admin_users");
    $row = $r->fetchArray(SQLITE3_ASSOC);
    if ($row && (int) $row['c'] === 0) {
        $bootstrap = getenv('INVOICING_INITIAL_ADMIN_PASSWORD');
        $plain = ($bootstrap !== false && $bootstrap !== '')
            ? (string) $bootstrap
            : 'admin';
        $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
        $stmt = $db->prepare("INSERT INTO admin_users (username, password_hash) VALUES ('admin', :h)");
        $stmt->bindValue(':h', $hash, SQLITE3_TEXT);
        $stmt->execute();
    }

    dsc_invoicing_ensure_admin_users_is_active_column($db);

    $db->exec("
        CREATE TABLE IF NOT EXISTS api_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key_name TEXT NOT NULL,
            api_key TEXT UNIQUE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used DATETIME
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS api_rate_limits (
            rate_key TEXT PRIMARY KEY,
            window_start INTEGER NOT NULL,
            count INTEGER NOT NULL
        )
    ");

    // PRD §6 — idempotent scaffolding for next milestones
    $db->exec("
        CREATE TABLE IF NOT EXISTS companies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            billing_email TEXT,
            square_customer_id TEXT,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_companies_name ON companies(name)');

    $db->exec("
        CREATE TABLE IF NOT EXISTS engagements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            hourly_rate_cents INTEGER NOT NULL DEFAULT 10000,
            included_hours_per_month INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'active',
            square_subscription_id TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_engagements_company ON engagements(company_id)');

    $db->exec("
        CREATE TABLE IF NOT EXISTS time_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            engagement_id INTEGER NOT NULL REFERENCES engagements(id) ON DELETE CASCADE,
            worked_date TEXT NOT NULL,
            hours REAL NOT NULL,
            memo TEXT,
            billing_period_month TEXT NOT NULL,
            invoiced_square_invoice_id TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_time_entries_engagement_period ON time_entries(engagement_id, billing_period_month)');

    $db->exec("
        CREATE TABLE IF NOT EXISTS outbound_invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            engagement_id INTEGER NOT NULL REFERENCES engagements(id) ON DELETE CASCADE,
            anchor_month TEXT NOT NULL,
            overage_month TEXT NOT NULL,
            retainer_amount_cents INTEGER NOT NULL DEFAULT 0,
            overage_amount_cents INTEGER NOT NULL DEFAULT 0,
            total_amount_cents INTEGER NOT NULL DEFAULT 0,
            square_order_id TEXT,
            square_invoice_id TEXT,
            square_invoice_version INTEGER NOT NULL DEFAULT 0,
            public_url TEXT,
            delivery_method TEXT,
            payment_status TEXT NOT NULL DEFAULT 'published',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec(
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_outbound_inv_eng_anchor ON outbound_invoices(engagement_id, anchor_month)'
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_outbound_square_invoice ON outbound_invoices(square_invoice_id)');
    dsc_invoicing_ensure_engagement_work_stoppage_column($db);
    dsc_invoicing_ensure_engagement_flat_tier_columns($db);
    dsc_invoicing_ensure_outbound_invoice_breakdown_columns($db);
    dsc_invoicing_ensure_outbound_flat_tier_columns($db);
}

/**
 * Migrate older DB files that predate engagements.work_stoppage.
 */
function dsc_invoicing_ensure_admin_users_is_active_column(SQLite3 $db): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $has = false;
    $r = $db->query('PRAGMA table_info(admin_users)');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        if (($row['name'] ?? '') === 'is_active') {
            $has = true;
            break;
        }
    }
    if (!$has) {
        $db->exec('ALTER TABLE admin_users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
    }
}

function dsc_invoicing_ensure_engagement_work_stoppage_column(SQLite3 $db): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $has = false;
    $r = $db->query('PRAGMA table_info(engagements)');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        if (($row['name'] ?? '') === 'work_stoppage') {
            $has = true;
            break;
        }
    }
    if (!$has) {
        $db->exec('ALTER TABLE engagements ADD COLUMN work_stoppage INTEGER NOT NULL DEFAULT 0');
    }
}

/**
 * D11 — flat_tier engagements: billing_mode + Tier 1 / Tier 2 invoice amounts.
 */
function dsc_invoicing_ensure_engagement_flat_tier_columns(SQLite3 $db): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $cols = [];
    $r = $db->query('PRAGMA table_info(engagements)');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $cols[(string) ($row['name'] ?? '')] = true;
    }
    if (!isset($cols['billing_mode'])) {
        $db->exec("ALTER TABLE engagements ADD COLUMN billing_mode TEXT NOT NULL DEFAULT 'hourly'");
    }
    if (!isset($cols['tier1_amount_cents'])) {
        $db->exec('ALTER TABLE engagements ADD COLUMN tier1_amount_cents INTEGER NOT NULL DEFAULT 0');
    }
    if (!isset($cols['tier2_amount_cents'])) {
        $db->exec('ALTER TABLE engagements ADD COLUMN tier2_amount_cents INTEGER NOT NULL DEFAULT 0');
    }
}

/**
 * D11 — snapshot flat/tier metadata on outbound invoices.
 */
function dsc_invoicing_ensure_outbound_flat_tier_columns(SQLite3 $db): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $cols = [];
    $r = $db->query('PRAGMA table_info(outbound_invoices)');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $cols[(string) ($row['name'] ?? '')] = true;
    }
    if (!isset($cols['billing_mode'])) {
        $db->exec('ALTER TABLE outbound_invoices ADD COLUMN billing_mode TEXT');
    }
    if (!isset($cols['tier_key'])) {
        $db->exec('ALTER TABLE outbound_invoices ADD COLUMN tier_key TEXT');
    }
    if (!isset($cols['fee_due_date'])) {
        $db->exec('ALTER TABLE outbound_invoices ADD COLUMN fee_due_date TEXT');
    }
}

/**
 * P4 — Tasks accounting doc snapshot, public breakdown page, split Square payment links.
 */
function dsc_invoicing_ensure_outbound_invoice_breakdown_columns(SQLite3 $db): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $cols = [];
    $r = $db->query('PRAGMA table_info(outbound_invoices)');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $cols[(string) ($row['name'] ?? '')] = true;
    }
    $add = static function (string $name, string $ddl) use ($db, $cols): void {
        if (!isset($cols[$name])) {
            $db->exec($ddl);
        }
    };
    $add('public_token', 'ALTER TABLE outbound_invoices ADD COLUMN public_token TEXT');
    $add('tasks_document_id', 'ALTER TABLE outbound_invoices ADD COLUMN tasks_document_id INTEGER');
    $add('tasks_document_title', 'ALTER TABLE outbound_invoices ADD COLUMN tasks_document_title TEXT');
    $add('accounting_markdown', 'ALTER TABLE outbound_invoices ADD COLUMN accounting_markdown TEXT');
    $add('retainer_due_date', 'ALTER TABLE outbound_invoices ADD COLUMN retainer_due_date TEXT');
    $add('overage_due_date', 'ALTER TABLE outbound_invoices ADD COLUMN overage_due_date TEXT');
    $add('square_retainer_invoice_id', 'ALTER TABLE outbound_invoices ADD COLUMN square_retainer_invoice_id TEXT');
    $add('square_overage_invoice_id', 'ALTER TABLE outbound_invoices ADD COLUMN square_overage_invoice_id TEXT');
    $add('retainer_public_url', 'ALTER TABLE outbound_invoices ADD COLUMN retainer_public_url TEXT');
    $add('overage_public_url', 'ALTER TABLE outbound_invoices ADD COLUMN overage_public_url TEXT');
    $add('retainer_payment_status', 'ALTER TABLE outbound_invoices ADD COLUMN retainer_payment_status TEXT');
    $add('overage_payment_status', 'ALTER TABLE outbound_invoices ADD COLUMN overage_payment_status TEXT');
    $db->exec(
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_outbound_public_token ON outbound_invoices(public_token) '
        . 'WHERE public_token IS NOT NULL AND TRIM(public_token) != \'\''
    );
}

function get_config(string $key): ?string {
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT value FROM config WHERE key = :k');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $r = $stmt->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    return $row ? (string) $row['value'] : null;
}

function set_config(string $key, mixed $value): void {
    $db = getDbConnection();
    $stmt = $db->prepare('INSERT OR REPLACE INTO config (key, value) VALUES (:k, :v)');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $stmt->bindValue(':v', $value === null ? '' : (string) $value, SQLITE3_TEXT);
    $stmt->execute();
}
