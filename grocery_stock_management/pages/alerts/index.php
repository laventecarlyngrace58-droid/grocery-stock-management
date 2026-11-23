<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/inventory_status.php';

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/pages/alerts/index.php'));
$baseUrl = dirname(dirname($scriptDirectory));
$baseUrl = $baseUrl === '/' || $baseUrl === '.' ? '' : rtrim($baseUrl, '/');

$pageTitle = 'Inventory Alerts';
$pageSubtitle = 'Monitor stock levels and review products that need attention.';
$activeNav = 'alerts';
$search = '';
$statusFilter = '';
$inputError = '';
$totalProducts = 0;
$products = [];
$statusOptions = [
    'in_stock' => 'In Stock',
    'low_stock' => 'Low Stock',
    'out_of_stock' => 'Out of Stock',
];

$rawSearch = $_GET['search'] ?? '';
if (!is_string($rawSearch)) {
    $inputError = 'Enter one search term.';
} else {
    $search = trim($rawSearch);
    $searchLength = function_exists('mb_strlen') ? mb_strlen($search, 'UTF-8') : strlen($search);
    if ($searchLength > 255) {
        $inputError = 'Search text must be 255 characters or fewer.';
    }
}

$rawStatus = $_GET['status'] ?? '';
if (!is_string($rawStatus) || ($rawStatus !== '' && !isset($statusOptions[$rawStatus]))) {
    $inputError = 'Choose a valid inventory status.';
} else {
    $statusFilter = $rawStatus;
}

if ($inputError !== '') {
    http_response_code(400);
}

try {
    $countStatement = $conn->prepare('SELECT COUNT(*) AS total_products FROM products');
    $countStatement->execute();
    $totalProducts = (int) $countStatement->get_result()->fetch_assoc()['total_products'];
    $countStatement->close();

    if ($inputError === '') {
        $searchPattern = '%' . $search . '%';
        $productsStatement = $conn->prepare(
            'SELECT
                p.product_name,
                p.sku,
                p.quantity,
                p.unit,
                p.reorder_level,
                c.category_name
            FROM products AS p
            INNER JOIN categories AS c ON c.category_id = p.category_id
            WHERE (? = \'\'
                OR p.product_name LIKE ?
                OR p.sku LIKE ?)
                AND (
                    ? = \'\'
                    OR (? = \'in_stock\' AND p.quantity > p.reorder_level)
                    OR (? = \'low_stock\' AND p.quantity > 0 AND p.quantity <= p.reorder_level)
                    OR (? = \'out_of_stock\' AND p.quantity = 0)
                )
            ORDER BY
                CASE
                    WHEN p.quantity = 0 THEN 0
                    WHEN p.quantity <= p.reorder_level THEN 1
                    ELSE 2
                END,
                p.product_name ASC'
        );
        $productsStatement->bind_param(
            'sssssss',
            $search,
            $searchPattern,
            $searchPattern,
            $statusFilter,
            $statusFilter,
            $statusFilter,
            $statusFilter
        );
        $productsStatement->execute();
        $products = $productsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
        $productsStatement->close();
    }
} catch (Throwable $exception) {
    error_log('Inventory alerts query failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Inventory alerts could not be loaded. Please try again later.');
}

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$hasFilters = $search !== '' || $statusFilter !== '';
$alertsUrl = $baseUrl . '/pages/alerts/index.php';

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-heading inventory-alerts-heading">
    <div>
        <span class="eyebrow">STOCK MONITORING</span>
        <h2>Inventory alerts</h2>
        <p>Search products and filter by their current inventory status.</p>
    </div>
</div>

<section class="panel-card inventory-alerts-panel" aria-labelledby="inventoryAlertsTitle">
    <div class="inventory-alerts-header">
        <div>
            <h3 id="inventoryAlertsTitle">Inventory status</h3>
            <p>Showing <?= count($products) ?> of <?= $escape($totalProducts) ?> products.</p>
        </div>
    </div>

    <form class="inventory-alert-filter-form" method="get" action="<?= $escape($alertsUrl) ?>">
        <div class="product-filter-field product-search-field">
            <label for="inventoryAlertSearch">Search products</label>
            <div class="product-search-control">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input
                    class="form-control"
                    type="search"
                    id="inventoryAlertSearch"
                    name="search"
                    value="<?= $escape($search) ?>"
                    placeholder="Search by product name or SKU"
                >
            </div>
        </div>
        <div class="product-filter-field">
            <label for="inventoryStatusFilter">Inventory status</label>
            <select class="form-select" id="inventoryStatusFilter" name="status">
                <option value="">All statuses</option>
                <?php foreach ($statusOptions as $statusValue => $statusLabel): ?>
                    <option value="<?= $escape($statusValue) ?>" <?= $statusFilter === $statusValue ? 'selected' : '' ?>>
                        <?= $escape($statusLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary inventory-alert-filter-submit" type="submit">
            <i class="bi bi-funnel" aria-hidden="true"></i>
            Apply filters
        </button>
        <?php if ($hasFilters): ?>
            <a class="btn btn-outline-primary inventory-alert-filter-clear" href="<?= $escape($alertsUrl) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($inputError !== ''): ?>
        <div class="alert alert-warning inventory-alert-filter-message" role="alert">
            <?= $escape($inputError) ?>
        </div>
    <?php elseif ($products === []): ?>
        <div class="empty-state inventory-alerts-empty">
            <span class="empty-state-icon <?= $hasFilters ? '' : 'empty-state-success' ?>">
                <i class="bi <?= $hasFilters ? 'bi-search' : 'bi-box-seam' ?>" aria-hidden="true"></i>
            </span>
            <?php if ($totalProducts === 0): ?>
                <p>No products in inventory yet</p>
                <span>Products will appear here after they are added to your inventory.</span>
            <?php else: ?>
                <p>No products match these filters</p>
                <span>Try another product name, SKU, or inventory status.</span>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive inventory-alerts-table-wrap">
            <table class="table table-hover inventory-table inventory-alerts-table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">PRODUCT</th>
                        <th scope="col">SKU</th>
                        <th scope="col">CATEGORY</th>
                        <th scope="col">CURRENT QUANTITY</th>
                        <th scope="col">REORDER LEVEL</th>
                        <th scope="col">STATUS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <?php $status = inventoryStatusFor((int) $product['quantity'], (int) $product['reorder_level']); ?>
                        <tr>
                            <td class="product-name-cell"><?= $escape($product['product_name']) ?></td>
                            <td class="stock-sku-cell"><?= $escape($product['sku']) ?></td>
                            <td class="category-cell"><?= $escape($product['category_name']) ?></td>
                            <td class="quantity-cell"><?= $escape($product['quantity']) ?> <span><?= $escape($product['unit']) ?></span></td>
                            <td class="quantity-cell"><?= $escape($product['reorder_level']) ?> <span><?= $escape($product['unit']) ?></span></td>
                            <td>
                                <span class="status-badge <?= $escape($status['class']) ?>">
                                    <span class="status-dot" aria-hidden="true"></span>
                                    <?= $escape($status['label']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
