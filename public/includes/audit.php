<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Append an ops-visible audit row (Settings → Audit log).
 *
 * @param array<string,mixed>|null $detail
 */
function dsc_invoicing_audit_log(
    string $action,
    string $actor = 'system',
    ?string $entityType = null,
    ?string $entityId = null,
    ?array $detail = null,
    string $level = 'info'
): void {
    try {
        $db = getDbConnection();
        dsc_invoicing_ensure_audit_log_table($db);
        $st = $db->prepare(
            'INSERT INTO audit_log (actor, action, entity_type, entity_id, detail, level) '
            . 'VALUES (:a, :act, :et, :ei, :d, :l)'
        );
        $st->bindValue(':a', substr($actor, 0, 120), SQLITE3_TEXT);
        $st->bindValue(':act', substr($action, 0, 120), SQLITE3_TEXT);
        if ($entityType === null || $entityType === '') {
            $st->bindValue(':et', null, SQLITE3_NULL);
        } else {
            $st->bindValue(':et', substr($entityType, 0, 80), SQLITE3_TEXT);
        }
        if ($entityId === null || $entityId === '') {
            $st->bindValue(':ei', null, SQLITE3_NULL);
        } else {
            $st->bindValue(':ei', substr($entityId, 0, 120), SQLITE3_TEXT);
        }
        if ($detail === null) {
            $st->bindValue(':d', null, SQLITE3_NULL);
        } else {
            $st->bindValue(':d', json_encode($detail, JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
        }
        $st->bindValue(':l', $level === 'error' || $level === 'warning' ? $level : 'info', SQLITE3_TEXT);
        $st->execute();
    } catch (Throwable $e) {
        error_log('invoicing audit_log failed: ' . $e->getMessage());
    }
}

/**
 * @return list<array<string,mixed>>
 */
function dsc_invoicing_audit_log_list(int $limit = 100, int $offset = 0): array {
    $db = getDbConnection();
    dsc_invoicing_ensure_audit_log_table($db);
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $st = $db->prepare(
        'SELECT id, created_at, actor, action, entity_type, entity_id, detail, level '
        . 'FROM audit_log ORDER BY id DESC LIMIT :lim OFFSET :off'
    );
    $st->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $st->bindValue(':off', $offset, SQLITE3_INTEGER);
    $r = $st->execute();
    $out = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $row['id'] = (int) $row['id'];
        $out[] = $row;
    }
    return $out;
}

function dsc_invoicing_audit_log_count(): int {
    $db = getDbConnection();
    dsc_invoicing_ensure_audit_log_table($db);
    return (int) $db->querySingle('SELECT COUNT(*) FROM audit_log');
}
