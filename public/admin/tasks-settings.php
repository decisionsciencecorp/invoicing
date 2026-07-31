<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
requireAuth();

$flash = '';
$flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'tasks_dsc') {
    requireCsrfToken();
    set_config('tasks_dsc_base_url', trim((string) ($_POST['tasks_dsc_base_url'] ?? '')));
    $key = trim((string) ($_POST['tasks_dsc_api_key'] ?? ''));
    if ($key !== '') {
        set_config('tasks_dsc_api_key', $key);
    }
    $flash = 'Tasks API settings saved.';
    $flashType = 'ok';
}

$adminPageTitle = 'Tasks API';
require_once __DIR__ . '/includes/header.php';
inv_render_page_header([
    'title' => 'Tasks',
    'subtitle' => 'Accounting documents from tasks.decisionsciencecorp.com',
]);
?>

<?php if ($flash !== ''): ?>
    <div class="message <?= $flashType === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="info-box">
    <p class="text-secondary">
        Used when publishing invoices to fetch and snapshot markdown from Sanctum Tasks.
        Env vars <code>TASKS_DSC_BASE_URL</code> / <code>TASKS_DSC_OTTOVERNAL_API_KEY</code> override when set on the server.
    </p>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="tasks_dsc">
        <label for="tasks_dsc_base_url">Base URL</label>
        <input class="form-control" type="url" id="tasks_dsc_base_url" name="tasks_dsc_base_url" value="<?= htmlspecialchars((string) (get_config('tasks_dsc_base_url') ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://tasks.decisionsciencecorp.com">
        <label for="tasks_dsc_api_key">API key (leave blank to keep existing)</label>
        <textarea class="form-control" id="tasks_dsc_api_key" name="tasks_dsc_api_key" rows="2" autocomplete="off" placeholder="X-API-Key value"></textarea>
        <button type="submit" class="btn btn-primary mt-3">Save</button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
