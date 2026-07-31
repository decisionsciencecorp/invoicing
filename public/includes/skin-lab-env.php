<?php
/**
 * Invoicing UI skins — same lab as Sanctum Tasks / CRM.
 * Slugs: hey, ledger, brutalist, obsidian.
 * Resolution: ?preview_skin= → user.skin_slug → config default_skin_slug → obsidian.
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function invSkinAvailableSlugs(): array
{
    return ['hey', 'ledger', 'brutalist', 'obsidian'];
}

function invSkinNormalizeSlug(?string $slug): ?string
{
    $s = strtolower(trim((string) $slug));
    return in_array($s, invSkinAvailableSlugs(), true) ? $s : null;
}

function invSkinMasterSlug(): string
{
    try {
        $raw = get_config('default_skin_slug');
        return invSkinNormalizeSlug(is_string($raw) ? $raw : null) ?? 'obsidian';
    } catch (Throwable $e) {
        return 'obsidian';
    }
}

function invSkinUserOverrideSlug(?array $userRow): ?string
{
    if (!$userRow) {
        return null;
    }
    $raw = $userRow['skin_slug'] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }
    return invSkinNormalizeSlug((string) $raw);
}

function invSkinPreviewSlug(): ?string
{
    if (!isset($_GET['preview_skin'])) {
        return null;
    }
    return invSkinNormalizeSlug((string) $_GET['preview_skin']);
}

function invSkinEffectiveSlug(?array $userRow = null): string
{
    $preview = invSkinPreviewSlug();
    if ($preview !== null) {
        return $preview;
    }
    if ($userRow === null && function_exists('isLoggedIn') && isLoggedIn() && function_exists('getCurrentUser')) {
        $userRow = getCurrentUser();
    }
    $override = invSkinUserOverrideSlug(is_array($userRow) ? $userRow : null);
    if ($override !== null) {
        return $override;
    }
    return invSkinMasterSlug();
}

function invSkinStylesheetHref(string $slug): string
{
    $slug = invSkinNormalizeSlug($slug) ?? 'obsidian';
    return dsc_invoicing_href('assets/skins/' . $slug . '.css') . '?v=1';
}

/** Light skins paint pale chrome; navbar-dark keeps a white hamburger → invisible. */
function invSkinUsesLightNav(string $slug): bool
{
    return in_array($slug, ['hey', 'ledger', 'brutalist'], true);
}

function invSkinBootstrapTheme(string $slug): string
{
    return $slug === 'obsidian' ? 'dark' : 'light';
}

/** Comp bar on dev.invoicing only (Tasks/CRM Skin Lab pattern). */
function invSkinShouldShowCompBar(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    return $host === 'dev.invoicing.decisionsciencecorp.com'
        || $host === '127.0.0.1'
        || $host === 'localhost';
}

function invSkinSaveUserPreference(int $userId, string $slug): array
{
    $normalized = invSkinNormalizeSlug($slug);
    if ($normalized === null) {
        return ['success' => false, 'error' => 'Invalid skin'];
    }
    $db = getDbConnection();
    dsc_invoicing_ensure_admin_users_skin_slug_column($db);
    $st = $db->prepare('UPDATE admin_users SET skin_slug = :s WHERE id = :id');
    $st->bindValue(':s', $normalized, SQLITE3_TEXT);
    $st->bindValue(':id', $userId, SQLITE3_INTEGER);
    $st->execute();
    return ['success' => true, 'skin_slug' => $normalized];
}

function invSkinSaveSiteDefault(string $slug): array
{
    $normalized = invSkinNormalizeSlug($slug);
    if ($normalized === null) {
        return ['success' => false, 'error' => 'Invalid skin'];
    }
    set_config('default_skin_slug', $normalized);
    return ['success' => true, 'default_skin_slug' => $normalized];
}
