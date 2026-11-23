<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/inventory_status.php';

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/pages/products/index.php'));
$baseUrl = dirname(dirname($scriptDirectory));
$baseUrl = $baseUrl === '/' || $baseUrl === '.' ? '' : rtrim($baseUrl, '/');

$pageTitle = 'Products';
$pageSubtitle = 'Browse products, check stock levels, and review pricing.';
$activeNav = 'products';
$csrfToken = getCsrfToken();
$search = '';
$categoryId = null;
$inputError = '';
$feedbackMessage = '';
$feedbackStyle = 'success';
$feedbackCode = 200;
$transactionStarted = false;

$normalizeProductId = static function ($value): ?string {
    if (!is_string($value) || !ctype_digit($value)) {
        return null;
    }

    $normalized = ltrim($value, '0');
    $normalized = $normalized === '' ? '0' : $normalized;

    if (
        $normalized === '0'
        || strlen($normalized) > 10
        || (strlen($normalized) === 10 && strcmp($normalized, '4294967295') > 0)
    ) {
        return null;
    }

    return $normalized;
};

$rawSearch = $_GET['search'] ?? '';
if (!is_string($rawSearch)) {
    $inputError = 'Enter a single search term.';
} else {
    $search = trim($rawSearch);
}

$rawCategoryId = $_GET['category_id'] ?? '';
if (!is_string($rawCategoryId)) {
    $inputError = 'Choose a valid category.';
} elseif ($rawCategoryId !== '') {
    if (!ctype_digit($rawCategoryId) || (int) $rawCategoryId < 1) {
        $inputError = 'Choose a valid category.';
    } else {
        $categoryId = (int) $rawCategoryId;
    }
}

if ($inputError !== '') {
    http_response_code(400);
}

$statusMessages = [
    'updated' => ['success', 'Product updated successfully.'],
    'deleted' => ['success', 'Product deleted successfully.'],
    'blocked' => ['warning', 'This product has stock transaction history and cannot be deleted. Its inventory history has been preserved.'],
    'not_found' => ['warning', 'That product no longer exists. Refresh the list and try again.'],
];
$rawStatus = $_GET['status'] ?? '';
if (is_string($rawStatus) && isset($statusMessages[$rawStatus])) {
    [$feedbackStyle, $feedbackMessage] = $statusMessages[$rawStatus];
}

$productsUrl = $baseUrl . '/pages/products/index.php';
$filterParams = [];
if ($search !== '') {
    $filterParams['search'] = $search;
}
if ($categoryId !== null) {
    $filterParams['category_id'] = (string) $categoryId;
}
$filterQuery = $filterParams === [] ? '' : '?' . http_build_query($filterParams);
$redirectToProducts = static function (string $status) use ($productsUrl, $filterParams): never {
    $query = ['status' => $status] + $filterParams;
    header('Location: ' . $productsUrl . '?' . http_build_query($query), true, 303);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $rawAction = $_POST['action'] ?? '';
    $productIdToDelete = $normalizeProductId($_POST['product_id'] ?? null);

    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $feedbackStyle = 'warning';
        $feedbackMessage = 'The form expired or is invalid. Refresh the page and try again.';
        $feedbackCode = 403;
    } elseif ($inputError !== '') {
        $feedbackStyle = 'warning';
        $feedbackMessage = $inputError;
        $feedbackCode = 400;
    } elseif (!is_string($rawAction) || $rawAction !== 'delete') {
        $feedbackStyle = 'warning';
        $feedbackMessage = 'Choose a valid product action.';
        $feedbackCode = 400;
    } elseif ($productIdToDelete === null) {
        $feedbackStyle = 'warning';
        $feedbackMessage = 'A valid product ID is required for deletion.';
        $feedbackCode = 400;
    } else {
        try {
            $conn->begin_transaction();
            $transactionStarted = true;

            $lockStatement = $conn->prepare(
                'SELECT product_id
                FROM products
                WHERE product_id = ?
                FOR UPDATE'
            );
            $lockStatement->bind_param('s', $productIdToDelete);
            $lockStatement->execute();
            $productExists = $lockStatement->get_result()->fetch_assoc() !== null;
            $lockStatement->close();

            if (!$productExists) {
                $conn->rollback();
                $transactionStarted = false;
                $feedbackStyle = 'warning';
                $feedbackMessage = 'That product no longer exists. Refresh the list and try again.';
                $feedbackCode = 404;
            } else {
                $historyStatement = $conn->prepare(
                    'SELECT COUNT(*) AS transaction_count
                    FROM stock_transactions
                    WHERE product_id = ?'
                );
                $historyStatement->bind_param('s', $productIdToDelete);
                $historyStatement->execute();
                $transactionCount = (int) $historyStatement->get_result()->fetch_assoc()['transaction_count'];
                $historyStatement->close();

                if ($transactionCount > 0) {
                    $conn->rollback();
                    $transactionStarted = false;
                    $feedbackStyle = 'warning';
                    $feedbackMessage = $statusMessages['blocked'][1];
                    $feedbackCode = 409;
                } else {
                    $deleteStatement = $conn->prepare(
                        'DELETE FROM products
                        WHERE product_id = ?'
                    );
                    $deleteStatement->bind_param('s', $productIdToDelete);
                    $deleteStatement->execute();
                    $deleteStatement->close();

                    $conn->commit();
                    $transactionStarted = false;
                    $redirectToProducts('deleted');
                }
            }
        } catch (mysqli_sql_exception $exception) {
            if ($transactionStarted) {
                $conn->rollback();
                $transactionStarted = false;
            }

            if ($exception->getCode() === 1451) {
                $feedbackStyle = 'warning';
                $feedbackMessage = $statusMessages['blocked'][1];
                $feedbackCode = 409;
            } else {
                error_log('Product deletion failed: ' . $exception->getMessage());
                http_response_code(500);
                exit('The product could not be deleted. Please try again later.');
            }
        }
    }
}

try {
    $categoriesStatement = $conn->prepare(
        'SELECT category_id, category_name
        FROM categories
        ORDER BY category_name ASC'
    );
    $categoriesStatement->execute();
    $categories = $categoriesStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $categoriesStatement->close();

    $searchPattern = '%' . $search . '%';
    $categoryFilter = $categoryId ?? 0;
    $productsStatement = $conn->prepare(
        'SELECT
            p.product_id,
            p.product_name,
            p.sku,
            p.quantity,
            p.unit,
            p.reorder_level,
            p.cost_price,
            p.selling_price,
            p.expiration_date,
            c.category_name
        FROM products AS p
        INNER JOIN categories AS c ON c.category_id = p.category_id
        WHERE (? = \'\'
            OR p.product_name LIKE ?
            OR p.sku LIKE ?)
            AND (? = 0 OR p.category_id = ?)
        ORDER BY p.product_name ASC'
    );
    $productsStatement->bind_param(
        'sssii',
        $search,
        $searchPattern,
        $searchPattern,
        $categoryFilter,
        $categoryFilter
    );
    $productsStatement->execute();
    $products = $productsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $productsStatement->close();
} catch (Throwable $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }

    error_log('Product list query failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Products could not be loaded. Please try again later.');
}

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$hasFilters = $search !== '' || $categoryId !== null;

if ($feedbackCode !== 200) {
    http_response_code($feedbackCode);
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-heading products-page-heading">
    <div>
        <span class="eyebrow">INVENTORY MANAGEMENT</span>
        <h2>Product inventory</h2>
        <p>Search your catalog and review each product's stock and pricing details.</p>
    </div>
    <a
        class="btn btn-primary add-product-button"
        href="<?= $escape($baseUrl) ?>/pages/products/add.php"
        title="Add a product to the inventory"
    >
        <i class="bi bi-plus-lg" aria-hidden="true"></i>
        Add Product
    </a>
</div>

<?php if ($feedbackMessage !== ''): ?>
    <div class="alert alert-<?= $escape($feedbackStyle) ?> product-form-message" role="<?= $feedbackStyle === 'success' ? 'status' : 'alert' ?>">
        <i class="bi <?= $feedbackStyle === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>" aria-hidden="true"></i>
        <?= $escape($feedbackMessage) ?>
    </div>
<?php endif; ?>

<section class="panel-card products-panel" aria-labelledby="productsPanelTitle">
    <div class="products-toolbar">
        <div class="products-toolbar-heading">
            <div>
                <h3 id="productsPanelTitle">All products</h3>
                <p>Review product details and current stock status.</p>
            </div>
            <span class="product-count"><?= count($products) ?> products</span>
        </div>

        <form class="products-filter-form" method="get" action="<?= $escape($baseUrl) ?>/pages/products/index.php">
            <div class="product-filter-field product-search-field">
                <label for="productSearch">Search products</label>
                <div class="product-search-control">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input
                        class="form-control"
                        type="search"
                        id="productSearch"
                        name="search"
                        value="<?= $escape($search) ?>"
                        placeholder="Search by product name or SKU"
                    >
                </div>
            </div>
            <div class="product-filter-field product-category-field">
                <label for="categoryFilter">Category</label>
                <select class="form-select" id="categoryFilter" name="category_id">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option
                            value="<?= $escape($category['category_id']) ?>"
                            <?= $categoryId === (int) $category['category_id'] ? 'selected' : '' ?>
                        >
                            <?= $escape($category['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary filter-submit" type="submit">
                <i class="bi bi-funnel" aria-hidden="true"></i>
                Apply filters
            </button>
            <?php if ($hasFilters): ?>
                <a class="btn btn-outline-primary filter-reset" href="<?= $escape($baseUrl) ?>/pages/products/index.php">
                    Clear
                </a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($inputError !== ''): ?>
        <div class="alert alert-warning products-filter-message" role="alert">
            <?= $escape($inputError) ?>
        </div>
    <?php endif; ?>

    <?php if ($products === []): ?>
        <div class="empty-state products-empty-state">
            <span class="empty-state-icon <?= $hasFilters ? '' : 'empty-state-success' ?>">
                <i class="bi <?= $hasFilters ? 'bi-search' : 'bi-box-seam' ?>" aria-hidden="true"></i>
            </span>
            <?php if ($hasFilters): ?>
                <p>No products match these filters</p>
                <span>Try a different product name, SKU, or category.</span>
            <?php else: ?>
                <p>No products have been added yet</p>
                <span>Your product records will appear here once they are added to the inventory.</span>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive products-table-wrap">
            <table class="table table-hover inventory-table products-table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">PRODUCT</th>
                        <th scope="col">CATEGORY</th>
                        <th scope="col">QUANTITY</th>
                        <th scope="col">UNIT</th>
                        <th scope="col">COST PRICE</th>
                        <th scope="col">SELLING PRICE</th>
                        <th scope="col">EXPIRATION</th>
                        <th scope="col">STOCK STATUS</th>
                        <th scope="col">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <?php $status = inventoryStatusFor((int) $product['quantity'], (int) $product['reorder_level']); ?>
                        <tr>
                            <td class="product-name-cell">
                                <span class="product-name"><?= $escape($product['product_name']) ?></span>
                                <span class="product-sku">SKU: <?= $escape($product['sku']) ?></span>
                            </td>
                            <td class="category-cell"><?= $escape($product['category_name']) ?></td>
                            <td class="quantity-cell"><?= $escape($product['quantity']) ?></td>
                            <td><?= $escape($product['unit']) ?></td>
                            <td class="product-price"><?= $escape(number_format((float) $product['cost_price'], 2)) ?></td>
                            <td class="product-price"><?= $escape(number_format((float) $product['selling_price'], 2)) ?></td>
                            <td class="product-expiration">
                                <?php if ($product['expiration_date'] === null): ?>
                                    <span class="not-set">Not set</span>
                                <?php else: ?>
                                    <?= $escape(date('M j, Y', strtotime($product['expiration_date']))) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $escape($status['class']) ?>">
                                    <span class="status-dot" aria-hidden="true"></span>
                                    <?= $escape($status['label']) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $editParams = ['id' => (string) $product['product_id']] + $filterParams;
                                $editUrl = $baseUrl . '/pages/products/edit.php?' . http_build_query($editParams);
                                ?>
                                <div class="product-actions">
                                    <a
                                        class="btn btn-icon-action edit-action"
                                        href="<?= $escape($editUrl) ?>"
                                        aria-label="Edit <?= $escape($product['product_name']) ?>"
                                        title="Edit product"
                                    >
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>
                                    <form
                                        method="post"
                                        action="<?= $escape($productsUrl . $filterQuery) ?>"
                                        onsubmit="return confirm('Delete this product? Products with stock transaction history cannot be deleted.');"
                                    >
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="product_id" value="<?= $escape($product['product_id']) ?>">
                                        <button
                                            class="btn btn-icon-action delete-action"
                                            type="submit"
                                            aria-label="Delete <?= $escape($product['product_name']) ?>"
                                            title="Delete product"
                                        >
                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
