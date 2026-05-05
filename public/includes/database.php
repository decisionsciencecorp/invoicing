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
