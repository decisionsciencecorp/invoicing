<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
requireAuth();

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 50;
$total = dsc_invoicing_audit_log_count();
$pages = max(1, (int) ceil($total / $pageSize));
if ($page > $pages) {
    $page = $pages;
}
$offset = ($page - 1) * $pageSize;
$entries = dsc_invoicing_audit_log_list($pageSize, $offset);

$adminPageTitle = 'Audit log';
require_once __DIR__ . '/includes/header.php';
inv_render_page_header([
    'title' => 'Audit log',
    'subtitle' => 'Ops trail for webhooks, cancels, and system events',
]);
?>

<p style="color:#8b949e;">Ops trail for webhooks, cancels, and other system events. Newest first.</p>

<div class="info-box">
    <?php if ($entries === []): ?>
        <p style="margin:0;color:#8b949e;">No entries yet.</p>
    <?php else: ?>
        <p style="color:#8b949e;font-size:.875rem;">Page <?= (int) $page ?> of <?= (int) $pages ?> · <?= (int) $total ?> total</p>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid #30363d;">
                        <th style="padding:0.4rem;">When</th>
                        <th style="padding:0.4rem;">Level</th>
                        <th style="padding:0.4rem;">Actor</th>
                        <th style="padding:0.4rem;">Action</th>
                        <th style="padding:0.4rem;">Entity</th>
                        <th style="padding:0.4rem;">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $e): ?>
                        <tr style="border-bottom:1px solid #21262d;vertical-align:top;">
                            <td style="padding:0.35rem 0;white-space:nowrap;"><?= htmlspecialchars((string) $e['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:0.35rem 0;"><code><?= htmlspecialchars((string) $e['level'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td style="padding:0.35rem 0;"><?= htmlspecialchars((string) $e['actor'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:0.35rem 0;"><code><?= htmlspecialchars((string) $e['action'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td style="padding:0.35rem 0;">
                                <?php if (!empty($e['entity_type'])): ?>
                                    <?= htmlspecialchars((string) $e['entity_type'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($e['entity_id'])): ?>
                                        <code>#<?= htmlspecialchars((string) $e['entity_id'], ENT_QUOTES, 'UTF-8') ?></code>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="padding:0.35rem 0;max-width:22rem;word-break:break-word;color:#8b949e;">
                                <?= htmlspecialchars((string) ($e['detail'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <p style="margin-top:1rem;">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <?php if ($p === $page): ?>
                        <strong><?= $p ?></strong>
                    <?php else: ?>
                        <a href="?page=<?= $p ?>"><?= $p ?></a>
                    <?php endif; ?>
                    <?= $p < $pages ? ' · ' : '' ?>
                <?php endfor; ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
