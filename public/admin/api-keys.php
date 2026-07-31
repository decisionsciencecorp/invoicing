<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();

$message = '';
$newKey = '';
$section = (string) ($_GET['section'] ?? 'list');
if (!in_array($section, ['list', 'create'], true)) {
    $section = 'list';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    if (($_POST['action'] ?? '') === 'create') {
        $section = 'create';
        $name = trim((string) ($_POST['key_name'] ?? ''));
        $name = $name !== '' ? $name : 'Unnamed';
        $newKey = createApiKey($name);
        $message = 'API key created. Copy it now — it will not be shown again.';
    } elseif (($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
        $section = 'list';
        deleteApiKey((int) $_POST['id']);
        $message = 'Key deleted.';
    }
}

$keys = getAllApiKeys();

$adminPageTitle = 'API keys';
require_once __DIR__ . '/includes/header.php';
inv_render_page_header([
    'title' => 'API keys',
    'subtitle' => 'Keys for HTTP integrations and SMCP',
]);

inv_render_subtabbar([
    'list' => ['Existing', dsc_invoicing_href('admin/api-keys.php?section=list')],
    'create' => ['Create', dsc_invoicing_href('admin/api-keys.php?section=create')],
], $section, 'API key sections');
?>

<p class="text-secondary small">Keys authenticate JSON requests to <code>public/api/*.php</code> (header <code>X-API-Key</code>, <code>Authorization: Bearer</code>, or <code>?api_key=</code>).</p>

<?php if ($message !== ''): ?>
    <div class="message ok"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($section === 'create'): ?>
<div class="info-box">
    <h2 class="h5 mt-0">Create key</h2>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <label for="key_name">Key name</label>
        <input class="form-control" type="text" id="key_name" name="key_name" placeholder="e.g. Automation / SMCP" style="max-width:24rem;">
        <button type="submit" class="btn btn-primary mt-3">Create</button>
    </form>
    <?php if ($newKey !== ''): ?>
        <p class="mt-3 font-monospace text-break surface-pad p-3 rounded"><?= htmlspecialchars($newKey, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="info-box">
    <h2 class="h5 mt-0">Existing keys</h2>
    <?php if ($keys === []): ?>
        <p class="mb-0">No keys yet. <a href="<?= htmlspecialchars(dsc_invoicing_href('admin/api-keys.php?section=create'), ENT_QUOTES, 'UTF-8') ?>">Create one</a>.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Key</th>
                        <th>Created</th>
                        <th>Last used</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keys as $k): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $k['key_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="font-monospace"><?= htmlspecialchars((string) $k['api_key'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(dsc_invoicing_format_date((string) ($k['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= !empty($k['last_used']) ? htmlspecialchars(dsc_invoicing_format_date((string) $k['last_used']), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                            <td>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this key?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
