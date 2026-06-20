<?php
/**
 * includes/header.php
 * Expects (optionally) $pageTitle to be set by the including page.
 * Opens the HTML document, the app shell, and the sidebar.
 */
$pageTitle = $pageTitle ?? 'Dashboard';
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - ICT BD Bus Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="app-shell">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="app-main">
        <header class="app-topbar">
            <button class="sidebar-toggle d-lg-none" type="button" id="sidebarToggle" aria-label="Toggle navigation">
                <i class="bi bi-list"></i>
            </button>
            <h1 class="app-page-title"><?= e($pageTitle) ?></h1>
            <div class="app-topbar-user dropdown">
                <button class="btn app-user-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="app-user-avatar"><?= e(strtoupper(substr((string)($user['full_name'] ?? '?'), 0, 1))) ?></span>
                    <span class="app-user-name"><?= e($user['full_name'] ?? 'Guest') ?></span>
                    <span class="app-user-role badge text-bg-secondary"><?= e($user['role'] ?? '') ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                </ul>
            </div>
        </header>

        <div class="app-content">
            <?php if ($flash = get_flash()): ?>
                <div class="alert alert-<?= e($flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger')) ?> alert-dismissible fade show" role="alert">
                    <?= e($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>