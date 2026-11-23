<?php
$activeNav = $activeNav ?? '';
$isActive = static fn (string $key): bool => $activeNav === $key;
$navClass = static fn (string $key): string => $isActive($key) ? 'sidebar-link active' : 'sidebar-link';
$navCurrent = static fn (string $key): string => $isActive($key) ? ' aria-current="page"' : '';
?>
<aside class="sidebar offcanvas-xl offcanvas-start" tabindex="-1" id="appSidebar" aria-labelledby="sidebarTitle">
    <div class="sidebar-brand">
        <div class="brand-mark" aria-hidden="true"><i class="bi bi-leaf-fill"></i></div>
        <div class="brand-copy">
            <span id="sidebarTitle" class="brand-name">Grocery Inventory Pro</span>
            <span class="brand-caption">STOCK MANAGEMENT</span>
        </div>
        <button class="sidebar-close d-xl-none" type="button" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Close navigation">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>

    <div class="offcanvas-body sidebar-body">
        <span class="nav-section-label">WORKSPACE</span>
        <nav class="sidebar-nav" aria-label="Main navigation">
            <a class="<?= $navClass('dashboard') ?>" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/dashboard.php"<?= $navCurrent('dashboard') ?>>
                <i class="bi bi-grid-1x2" aria-hidden="true"></i><span>Dashboard</span>
            </a>
            <a class="<?= $navClass('products') ?>" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/pages/products/index.php"<?= $navCurrent('products') ?>>
                <i class="bi bi-box-seam" aria-hidden="true"></i><span>Products</span>
            </a>
            <a class="<?= $navClass('categories') ?>" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/pages/categories/index.php"<?= $navCurrent('categories') ?>>
                <i class="bi bi-collection" aria-hidden="true"></i><span>Categories</span>
            </a>
        </nav>

        <span class="nav-section-label nav-section-spaced">STOCK MOVEMENTS</span>
        <nav class="sidebar-nav" aria-label="Stock navigation">
            <a class="<?= $navClass('stock-in') ?>" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/pages/stock/index.php?type=stock_in"<?= $navCurrent('stock-in') ?>>
                <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i><span>Stock in</span>
            </a>
            <a class="<?= $navClass('stock-out') ?>" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/pages/stock/index.php?type=stock_out"<?= $navCurrent('stock-out') ?>>
                <i class="bi bi-box-arrow-up" aria-hidden="true"></i><span>Stock out</span>
            </a>
            <a class="<?= $navClass('transactions') ?>" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/pages/stock/index.php?section=history#transaction-history"<?= $navCurrent('transactions') ?>>
                <i class="bi bi-clock-history" aria-hidden="true"></i><span>Transactions</span>
            </a>
        </nav>

        <span class="nav-section-label nav-section-spaced">ANALYTICS</span>
        <nav class="sidebar-nav" aria-label="Analytics navigation">
            <a class="<?= $navClass('alerts') ?>" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/pages/alerts/index.php"<?= $navCurrent('alerts') ?>>
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>Inventory alerts</span>
            </a>
            <a class="<?= $navClass('reports') ?>" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/pages/reports/index.php"<?= $navCurrent('reports') ?>>
                <i class="bi bi-bar-chart-line" aria-hidden="true"></i><span>Reports</span>
            </a>
        </nav>

        <div class="sidebar-tip">
            <div class="sidebar-tip-icon"><i class="bi bi-lightbulb" aria-hidden="true"></i></div>
            <div>
                <strong>Inventory tip</strong>
                <p>Review low-stock items regularly to keep shelves ready.</p>
            </div>
        </div>

        <div class="sidebar-account">
            <div class="account-avatar" aria-hidden="true"><i class="bi bi-shop"></i></div>
            <div class="account-copy">
                <strong>Store workspace</strong>
                <span>Inventory management</span>
            </div>
            <span class="sidebar-status-dot" aria-label="Workspace active"></span>
        </div>
    </div>
</aside>
