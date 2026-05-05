<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

$adminPageTitle = $adminPageTitle ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($adminPageTitle, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(dsc_invoicing_href('css/style.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1 class="site-title"><?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="site-subtitle">Admin</p>
        </div>
    </header>
    <div class="container">
        <main class="main-content">
