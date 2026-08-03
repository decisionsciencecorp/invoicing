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

/**
 * Secondary tab strip under a Settings (or other) parent page.
 *
 * @param array<string, array{0:string,1:string}> $tabs key => [label, href]
 */
function inv_render_subtabbar(array $tabs, string $activeKey, string $ariaLabel = 'Section'): void {
    if ($tabs === []) {
        return;
    }
    ?>
    <nav class="tabbar tabbar--sub" aria-label="<?= htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($tabs as $key => [$label, $href]): ?>
            <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
               class="<?= $activeKey === $key ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </nav>
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

/**
 * Admin table cell for outbound billing period (human label + muted raw code).
 *
 * @param array<string,mixed> $row
 */
function inv_render_outbound_period_cell(array $row): void {
    $label = dsc_billing_outbound_period_label($row);
    $raw = trim((string) ($row['anchor_month'] ?? ''));
    ?>
    <div><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
    <?php if ($raw !== '' && $raw !== $label): ?>
        <div style="color:#8b949e;font-size:.75rem;margin-top:.15rem;"><code><?= htmlspecialchars($raw, ENT_QUOTES, 'UTF-8') ?></code></div>
    <?php endif; ?>
    <?php
}
