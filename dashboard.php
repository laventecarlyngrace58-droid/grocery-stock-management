<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/includes/inventory_status.php';

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/dashboard.php'));
$baseUrl = $scriptDirectory === '/' ? '' : rtrim($scriptDirectory, '/');
$pageTitle = 'Dashboard';
$pageSubtitle = 'A clear overview of your products, categories, and stock levels.';
$activeNav = 'dashboard';
$alertsUrl = $baseUrl . '/pages/alerts/index.php';

try {
    $statisticsStatement = $conn->prepare(
        'SELECT
            (SELECT COUNT(*) FROM products) AS total_products,
            (SELECT COUNT(*) FROM products WHERE quantity > reorder_level) AS in_stock_products,
            (SELECT COUNT(*) FROM products WHERE quantity > 0 AND quantity <= reorder_level) AS low_stock_products,
            (SELECT COUNT(*) FROM products WHERE quantity = 0) AS out_of_stock_products'
    );
    $statisticsStatement->execute();
    $statistics = $statisticsStatement->get_result()->fetch_assoc();
    $statisticsStatement->close();

    if ($statistics === null) {
        throw new RuntimeException('Dashboard statistics query returned no result.');
    }

    $productsStatement = $conn->prepare(
        'SELECT p.product_name, p.quantity, p.reorder_level, p.unit, c.category_name
        FROM products AS p
        INNER JOIN categories AS c ON c.category_id = p.category_id
        ORDER BY
            (p.quantity = 0) DESC,
            (p.quantity > 0 AND p.quantity <= p.reorder_level) DESC,
            p.product_name ASC
        LIMIT 8'
    );
    $productsStatement->execute();
    $products = $productsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $productsStatement->close();

    $lowStockStatement = $conn->prepare(
        'SELECT p.product_name, p.quantity, p.unit, c.category_name
        FROM products AS p
        INNER JOIN categories AS c ON c.category_id = p.category_id
        WHERE p.quantity > 0 AND p.quantity <= p.reorder_level
        ORDER BY p.quantity ASC, p.product_name ASC
        LIMIT 5'
    );
    $lowStockStatement->execute();
    $lowStockProducts = $lowStockStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $lowStockStatement->close();

    $recentTransactionsStatement = $conn->prepare(
        'SELECT
            t.transaction_date,
            t.transaction_type,
            t.quantity,
            t.reason,
            p.product_name,
            p.sku,
            p.unit
        FROM stock_transactions AS t
        INNER JOIN products AS p ON p.product_id = t.product_id
        ORDER BY t.transaction_date DESC, t.transaction_id DESC
        LIMIT 5'
    );
    $recentTransactionsStatement->execute();
    $recentTransactions = $recentTransactionsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $recentTransactionsStatement->close();
} catch (Throwable $exception) {
    error_log('Dashboard data query failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Dashboard data could not be loaded. Please try again later.');
}

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

require __DIR__ . '/includes/header.php';
?>

<section class="welcome-banner" aria-labelledby="welcome-title">
    <div class="welcome-content">
        <span class="eyebrow">STORE OVERVIEW</span>
        <h2 id="welcome-title">Your inventory, at a glance</h2>
        <p>Stay on top of product availability and keep your shelves ready.</p>
    </div>
    <div class="welcome-status">
        <span class="welcome-status-icon"><i class="bi bi-check2" aria-hidden="true"></i></span>
        <span>Live inventory overview</span>
    </div>
</section>

<section class="stats-grid" aria-label="Inventory statistics">
    <article class="stat-card">
        <div class="stat-card-top">
            <span class="stat-label">Total products</span>
            <span class="stat-icon stat-icon-green"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
        </div>
        <div class="stat-value"><?= $escape($statistics['total_products']) ?></div>
        <div class="stat-note">Products tracked in your store</div>
    </article>
    <article class="stat-card">
        <div class="stat-card-top">
            <span class="stat-label">In stock</span>
            <span class="stat-icon stat-icon-green"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
        </div>
        <div class="stat-value"><?= $escape($statistics['in_stock_products']) ?></div>
        <div class="stat-note">Products above their reorder level</div>
    </article>
    <article class="stat-card">
        <div class="stat-card-top">
            <span class="stat-label">Low stock</span>
            <span class="stat-icon stat-icon-amber"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span>
        </div>
        <div class="stat-value"><?= $escape($statistics['low_stock_products']) ?></div>
        <div class="stat-note">At or below the reorder level</div>
    </article>
    <article class="stat-card">
        <div class="stat-card-top">
            <span class="stat-label">Out of stock</span>
            <span class="stat-icon stat-icon-red"><i class="bi bi-archive" aria-hidden="true"></i></span>
        </div>
        <div class="stat-value"><?= $escape($statistics['out_of_stock_products']) ?></div>
        <div class="stat-note">Products that need replenishing</div>
    </article>
</section>

<?php if ((int) $statistics['out_of_stock_products'] > 0 || (int) $statistics['low_stock_products'] > 0): ?>
    <section class="inventory-alert-notices" aria-label="Inventory warnings">
        <?php if ((int) $statistics['out_of_stock_products'] > 0): ?>
            <div class="alert alert-danger inventory-alert-notice" role="alert">
                <div class="inventory-alert-notice-copy">
                    <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
                    <span><strong>Critical:</strong> <?= $escape($statistics['out_of_stock_products']) ?> <?= (int) $statistics['out_of_stock_products'] === 1 ? 'product is' : 'products are' ?> out of stock.</span>
                </div>
                <a class="inventory-alert-notice-link" href="<?= $escape($alertsUrl) ?>?status=out_of_stock">
                    Review out-of-stock products <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        <?php endif; ?>
        <?php if ((int) $statistics['low_stock_products'] > 0): ?>
            <div class="alert alert-warning inventory-alert-notice" role="alert">
                <div class="inventory-alert-notice-copy">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <span><strong>Warning:</strong> <?= $escape($statistics['low_stock_products']) ?> <?= (int) $statistics['low_stock_products'] === 1 ? 'product is' : 'products are' ?> at or below reorder level.</span>
                </div>
                <a class="inventory-alert-notice-link" href="<?= $escape($alertsUrl) ?>?status=low_stock">
                    Review low-stock products <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="dashboard-panels" aria-label="Inventory details">
    <article class="panel-card alert-panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">REPLENISHMENT</span>
                <h2>Low stock alerts</h2>
            </div>
            <span class="alert-count"><?= $escape($statistics['low_stock_products']) ?> items</span>
        </div>

        <?php if ($lowStockProducts === []): ?>
            <div class="empty-state">
                <span class="empty-state-icon empty-state-success"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
                <p>All caught up</p>
                <span>No products are currently below their reorder level.</span>
            </div>
        <?php else: ?>
            <ul class="alert-list">
                <?php foreach ($lowStockProducts as $product): ?>
                    <li class="alert-item">
                        <div class="product-avatar product-avatar-warning" aria-hidden="true">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        <div class="alert-product">
                            <span class="alert-product-name"><?= $escape($product['product_name']) ?></span>
                            <span class="alert-product-category"><?= $escape($product['category_name']) ?></span>
                        </div>
                        <span class="quantity-pill quantity-pill-low">
                            <?= $escape($product['quantity']) ?> <span><?= $escape($product['unit']) ?></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <a class="text-action" href="<?= $escape($alertsUrl) ?>?status=low_stock">
            Review low-stock products <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </a>
    </article>

    <article class="panel-card inventory-panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">LATEST STOCK OVERVIEW</span>
                <h2>Inventory summary</h2>
            </div>
            <a class="text-action" href="<?= $escape($baseUrl) ?>/pages/products/index.php">
                All products <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <?php if ($products === []): ?>
            <div class="empty-state inventory-empty">
                <span class="empty-state-icon"><i class="bi bi-basket3" aria-hidden="true"></i></span>
                <p>No products in your inventory yet</p>
                <span>Once products are added, their category, quantity, and stock status will appear here.</span>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table inventory-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">PRODUCT</th>
                            <th scope="col">CATEGORY</th>
                            <th scope="col">QUANTITY</th>
                            <th scope="col">STOCK STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <?php $status = inventoryStatusFor((int) $product['quantity'], (int) $product['reorder_level']); ?>
                            <tr>
                                <td class="product-name-cell">
                                    <span class="table-product-icon"><i class="bi bi-box" aria-hidden="true"></i></span>
                                    <span><?= $escape($product['product_name']) ?></span>
                                </td>
                                <td class="category-cell"><?= $escape($product['category_name']) ?></td>
                                <td class="quantity-cell"><?= $escape($product['quantity']) ?> <span><?= $escape($product['unit']) ?></span></td>
                                <td><span class="status-badge <?= $escape($status['class']) ?>"><span class="status-dot" aria-hidden="true"></span><?= $escape($status['label']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ((int) $statistics['total_products'] > count($products)): ?>
                <div class="table-footnote">
                    Showing <?= count($products) ?> of <?= $escape($statistics['total_products']) ?> products, prioritized by stock status.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </article>
</section>

<section class="panel-card recent-stock-panel" aria-labelledby="recentStockTitle">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">LATEST ACTIVITY</span>
            <h2 id="recentStockTitle">Recent stock movements</h2>
        </div>
        <a class="text-action" href="<?= $escape($baseUrl) ?>/pages/stock/index.php?section=history#transaction-history">
            Transaction history <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </a>
    </div>

    <?php if ($recentTransactions === []): ?>
        <div class="empty-state recent-stock-empty">
            <span class="empty-state-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
            <p>No stock movements recorded yet</p>
            <span>Stock In and Stock Out activity will appear here after it is recorded.</span>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover inventory-table recent-stock-table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">DATE</th>
                        <th scope="col">PRODUCT</th>
                        <th scope="col">SKU</th>
                        <th scope="col">TYPE</th>
                        <th scope="col">QUANTITY</th>
                        <th scope="col">REASON</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentTransactions as $transaction): ?>
                        <?php
                        $isStockIn = $transaction['transaction_type'] === 'stock_in';
                        $typeLabel = $isStockIn ? 'Stock In' : 'Stock Out';
                        $typeClass = $isStockIn ? 'stock-transaction-in' : 'stock-transaction-out';
                        ?>
                        <tr>
                            <td class="stock-date-cell"><?= $escape(date('M j, Y g:i A', strtotime((string) $transaction['transaction_date']))) ?></td>
                            <td class="product-name-cell"><?= $escape($transaction['product_name']) ?></td>
                            <td class="stock-sku-cell"><?= $escape($transaction['sku']) ?></td>
                            <td>
                                <span class="stock-transaction-badge <?= $escape($typeClass) ?>">
                                    <i class="bi <?= $isStockIn ? 'bi-arrow-down-left' : 'bi-arrow-up-right' ?>" aria-hidden="true"></i>
                                    <?= $escape($typeLabel) ?>
                                </span>
                            </td>
                            <td class="quantity-cell"><?= $escape($transaction['quantity']) ?> <span><?= $escape($transaction['unit']) ?></span></td>
                            <td><?= $escape($transaction['reason']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
