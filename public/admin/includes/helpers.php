<?php
declare(strict_types=1);

/**
 * @param array{title:string, subtitle?:string, actions_html?:string} $opts
 */
function inv_render_page_header(array $opts): void {
    $title = (string) ($opts['title'] ?? '');
    $subtitle = (string) ($opts['subtitle'] ?? '');
    $actions = (string) ($opts['actions_html'] ?? '');
    ?>
    <div class="page-header">
        <div class="page-header__title">
            <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
            <?php if ($subtitle !== ''): ?>
                <div class="subtitle"><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
        <?php if ($actions !== ''): ?>
            <div class="page-header__actions"><?= $actions ?></div>
        <?php endif; ?>
    </div>
    <?php
}

function inv_status_pill_class(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'paid') {
        return 'status-pill status-pill--paid';
    }
    if ($s === 'partial') {
        return 'status-pill status-pill--partial';
    }
    if ($s === 'canceled' || $s === 'failed') {
        return 'status-pill status-pill--canceled';
    }
    return 'status-pill status-pill--published';
}
