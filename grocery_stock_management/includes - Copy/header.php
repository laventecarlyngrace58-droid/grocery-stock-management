<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123524">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | Grocery Inventory Pro</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($baseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/css/style.css" rel="stylesheet">
</head>
<body<?= isset($pageBodyClass) && $pageBodyClass !== '' ? ' class="' . htmlspecialchars($pageBodyClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '' ?>>
<div class="app-shell">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="main-panel">
        <header class="topbar" id="pageTop">
            <div class="topbar-leading">
                <button class="sidebar-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Open navigation">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
                <div class="topbar-title">
                    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
                    <p><?= htmlspecialchars($pageSubtitle ?? 'Manage your store inventory at a glance.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                </div>
            </div>
            <div class="topbar-meta">
                <span class="connection-indicator"><span class="connection-dot" aria-hidden="true"></span>Connected to inventory</span>
                <div class="topbar-date"><i class="bi bi-calendar3" aria-hidden="true"></i><span><?= htmlspecialchars(date('M j, Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></div>
            </div>
        </header>
        <main class="page-content">
