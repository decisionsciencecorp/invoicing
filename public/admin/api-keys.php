<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();

$message = '';
$newKey = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    if (($_POST['action'] ?? '') === 'create') {
        $name = trim((string) ($_POST['key_name'] ?? ''));
        $name = $name !== '' ? $name : 'Unnamed';
        $newKey = createApiKey($name);
        $message = 'API key created. Copy it now — it will not be shown again.';
    } elseif (($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
        deleteApiKey((int) $_POST['id']);
        $message = 'Key deleted.';
    }
}

$keys = getAllApiKeys();

$adminPageTitle = 'API keys';
require_once __DIR__ . '/includes/header.php';
?>

<div class="nav-row">
    <h1>API keys</h1>
    <form method="POST" action="<?= htmlspecialchars(dsc_invoicing_href('admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
        <?= csrfField() ?>
        <button type="submit" class="btn">Logout</button>
    </form>
</div>

<p style="color:#8b949e;font-size:.875rem;">Keys authenticate JSON requests to <code>public/api/*.php</code> (header <code>X-API-Key</code>, <code>Authorization: Bearer</code>, or <code>?api_key=</code>).</p>

<?php if ($message !== ''): ?>
    <div class="message ok"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="info-box">
    <h2 style="margin-top:0;">Create key</h2>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <label for="key_name">Key name</label>
        <input type="text" id="key_name" name="key_name" placeholder="e.g. Automation / SMCP" style="max-width:24rem;width:100%;">
        <button type="submit" class="btn" style="margin-top:1rem;">Create</button>
    </form>
    <?php if ($newKey !== ''): ?>
        <p style="margin-top:1rem;font-family:monospace;word-break:break-all;background:#161b22;padding:0.75rem;border-radius:6px;"><?= htmlspecialchars($newKey, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
</div>

<div class="info-box">
    <h2 style="margin-top:0;">Existing keys</h2>
    <?php if ($keys === []): ?>
        <p>No keys yet.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid #30363d;">
                        <th style="padding:0.4rem;">Name</th>
                        <th style="padding:0.4rem;">Key</th>
                        <th style="padding:0.4rem;">Created</th>
                        <th style="padding:0.4rem;">Last used</th>
                        <th style="padding:0.4rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keys as $k): ?>
                        <tr style="border-bottom:1px solid #21262d;">
                            <td style="padding:0.35rem 0;"><?= htmlspecialchars((string) $k['key_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:0.35rem 0;font-family:monospace;"><?= htmlspecialchars((string) $k['api_key'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:0.35rem 0;"><?= htmlspecialchars(dsc_invoicing_format_date((string) ($k['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:0.35rem 0;"><?= !empty($k['last_used']) ? htmlspecialchars(dsc_invoicing_format_date((string) $k['last_used']), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                            <td style="padding:0.35rem 0;">
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this key?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                                    <button type="submit" class="btn btn-outline" style="padding:0.25rem 0.5rem;">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
