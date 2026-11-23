<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/inventory_status.php';

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/pages/reports/index.php'));
$baseUrl = dirname(dirname($scriptDirectory));
$baseUrl = $baseUrl === '/' || $baseUrl === '.' ? '' : rtrim($baseUrl, '/');

$pageTitle = 'Reports & Analytics';
$pageSubtitle = 'Review inventory levels and analyze recorded stock movements.';
$pageBodyClass = 'reports-page-body';
$activeNav = 'reports';
$reportUrl = $baseUrl . '/pages/reports/index.php';
$alertsUrl = $baseUrl . '/pages/alerts/index.php';
$pageSize = 20;
$inputError = '';
$startDate = '';
$endDate = '';
$transactionType = '';
$productSearch = '';
$categoryId = null;
$currentPage = 1;
$inventorySummary = [
    'total_products' => 0,
    'total_quantity' => '0',
    'in_stock_products' => 0,
    'low_stock_products' => 0,
    'out_of_stock_products' => 0,
];
$movementSummary = [
    'transaction_count' => 0,
    'stock_in_quantity' => '0',
    'stock_out_quantity' => '0',
];
$transactions = [];
$lowStockProducts = [];
$outOfStockProducts = [];
$categories = [];
$categoriesById = [];
$allowedTypes = [
    'stock_in' => 'Stock In',
    'stock_out' => 'Stock Out',
];

$setInputError = static function (string $message) use (&$inputError): void {
    if ($inputError === '') {
        $inputError = $message;
    }
};
$isValidDate = static function (string $value): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $dateErrors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        && $date->format('Y-m-d') === $value
        && (int) $date->format('Y') >= 1970
        && (int) $date->format('Y') <= 2037;
};

$rawStartDate = $_GET['start_date'] ?? '';
if (!is_string($rawStartDate)) {
    $setInputError('Enter a valid start date between 1970 and 2037.');
} else {
    $startDate = $rawStartDate;
    if ($startDate !== '' && !$isValidDate($startDate)) {
        $setInputError('Enter a valid start date between 1970 and 2037.');
    }
}

$rawEndDate = $_GET['end_date'] ?? '';
if (!is_string($rawEndDate)) {
    $setInputError('Enter a valid end date between 1970 and 2037.');
} else {
    $endDate = $rawEndDate;
    if ($endDate !== '' && !$isValidDate($endDate)) {
        $setInputError('Enter a valid end date between 1970 and 2037.');
    }
}

if ($startDate !== '' && $endDate !== '' && $isValidDate($startDate) && $isValidDate($endDate) && $startDate > $endDate) {
    $setInputError('The start date must be on or before the end date.');
}

$rawType = $_GET['type'] ?? '';
if (!is_string($rawType) || ($rawType !== '' && !isset($allowedTypes[$rawType]))) {
    $setInputError('Choose a valid transaction type.');
} else {
    $transactionType = $rawType;
}

$rawProductSearch = $_GET['product_search'] ?? '';
if (!is_string($rawProductSearch)) {
    $setInputError('Enter one product search term.');
} else {
    $productSearch = trim($rawProductSearch);
    $searchLength = function_exists('mb_strlen')
        ? mb_strlen($productSearch, 'UTF-8')
        : strlen($productSearch);
    if ($searchLength > 255) {
        $setInputError('Product search must be 255 characters or fewer.');
    }
}

$rawCategoryId = $_GET['category_id'] ?? '';
if (!is_string($rawCategoryId)) {
    $setInputError('Choose a valid category.');
} elseif ($rawCategoryId !== '') {
    $normalizedCategoryId = ltrim($rawCategoryId, '0');
    $normalizedCategoryId = $normalizedCategoryId === '' ? '0' : $normalizedCategoryId;
    if (
        !ctype_digit($rawCategoryId)
        || $normalizedCategoryId === '0'
        || strlen($normalizedCategoryId) > 10
        || (strlen($normalizedCategoryId) === 10 && strcmp($normalizedCategoryId, '4294967295') > 0)
    ) {
        $setInputError('Choose a valid category.');
    } else {
        $categoryId = (int) $normalizedCategoryId;
    }
}

$rawPage = $_GET['page'] ?? '1';
if (!is_string($rawPage) || !ctype_digit($rawPage) || strlen($rawPage) > 9 || (int) $rawPage < 1) {
    $setInputError('Choose a valid report page.');
} else {
    $currentPage = (int) $rawPage;
}

$whereSql = 'WHERE (? = \'\' OR t.transaction_date >= CONCAT(?, \' 00:00:00\'))
    AND (? = \'\' OR t.transaction_date < DATE_ADD(?, INTERVAL 1 DAY))
    AND (? = \'\' OR t.transaction_type = ?)
    AND (? = \'\' OR p.product_name LIKE ? OR p.sku LIKE ?)
    AND (? = 0 OR p.category_id = ?)';

try {
    $categoriesStatement = $conn->prepare(
        'SELECT category_id, category_name
        FROM categories
        ORDER BY category_name ASC'
    );
    $categoriesStatement->execute();
    $categories = $categoriesStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $categoriesStatement->close();

    foreach ($categories as $category) {
        $categoriesById[(string) $category['category_id']] = $category;
    }

    if ($categoryId !== null && !isset($categoriesById[(string) $categoryId])) {
        $setInputError('Choose a category that exists.');
    }

    if ($inputError !== '') {
        http_response_code(400);
    }

    $inventoryStatement = $conn->prepare(
        'SELECT
            COUNT(*) AS total_products,
            COALESCE(SUM(quantity), 0) AS total_quantity,
            COALESCE(SUM(quantity > reorder_level), 0) AS in_stock_products,
            COALESCE(SUM(quantity > 0 AND quantity <= reorder_level), 0) AS low_stock_products,
            COALESCE(SUM(quantity = 0), 0) AS out_of_stock_products
        FROM products'
    );
    $inventoryStatement->execute();
    $inventorySummary = $inventoryStatement->get_result()->fetch_assoc();
    $inventoryStatement->close();

    $lowStockStatement = $conn->prepare(
        'SELECT p.product_name, p.sku, p.quantity, p.unit, p.reorder_level, c.category_name
        FROM products AS p
        INNER JOIN categories AS c ON c.category_id = p.category_id
        WHERE p.quantity > 0 AND p.quantity <= p.reorder_level
        ORDER BY p.quantity ASC, p.product_name ASC
        LIMIT 5'
    );
    $lowStockStatement->execute();
    $lowStockProducts = $lowStockStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $lowStockStatement->close();

    $outOfStockStatement = $conn->prepare(
        'SELECT p.product_name, p.sku, p.quantity, p.unit, p.reorder_level, c.category_name
        FROM products AS p
        INNER JOIN categories AS c ON c.category_id = p.category_id
        WHERE p.quantity = 0
        ORDER BY p.product_name ASC
        LIMIT 5'
    );
    $outOfStockStatement->execute();
    $outOfStockProducts = $outOfStockStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $outOfStockStatement->close();

    if ($inputError === '') {
        $categoryFilter = $categoryId ?? 0;
        $searchPattern = '%' . $productSearch . '%';
        $bindReportFilters = static function (mysqli_stmt $statement, ?int $limit = null, ?int $offset = null) use (
            $startDate,
            $endDate,
            $transactionType,
            $productSearch,
            $searchPattern,
            $categoryFilter
        ): void {
            if ($limit === null || $offset === null) {
                $statement->bind_param(
                    'sssssssssii',
                    $startDate,
                    $startDate,
                    $endDate,
                    $endDate,
                    $transactionType,
                    $transactionType,
                    $productSearch,
                    $searchPattern,
                    $searchPattern,
                    $categoryFilter,
                    $categoryFilter
                );
                return;
            }

            $statement->bind_param(
                'sssssssssiiii',
                $startDate,
                $startDate,
                $endDate,
                $endDate,
                $transactionType,
                $transactionType,
                $productSearch,
                $searchPattern,
                $searchPattern,
                $categoryFilter,
                $categoryFilter,
                $limit,
                $offset
            );
        };

        $summaryStatement = $conn->prepare(
            'SELECT
                COUNT(*) AS transaction_count,
                COALESCE(SUM(CASE WHEN t.transaction_type = \'stock_in\' THEN t.quantity ELSE 0 END), 0) AS stock_in_quantity,
                COALESCE(SUM(CASE WHEN t.transaction_type = \'stock_out\' THEN t.quantity ELSE 0 END), 0) AS stock_out_quantity
            FROM stock_transactions AS t
            INNER JOIN products AS p ON p.product_id = t.product_id
            INNER JOIN categories AS c ON c.category_id = p.category_id
            ' . $whereSql
        );
        $bindReportFilters($summaryStatement);
        $summaryStatement->execute();
        $movementSummary = $summaryStatement->get_result()->fetch_assoc();
        $summaryStatement->close();

        $totalPages = max(1, (int) ceil((int) $movementSummary['transaction_count'] / $pageSize));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $pageSize;

        $transactionsStatement = $conn->prepare(
            'SELECT
                t.transaction_date,
                t.transaction_type,
                t.quantity,
                t.reason,
                t.notes,
                p.product_name,
                p.sku,
                p.unit,
                c.category_name
            FROM stock_transactions AS t
            INNER JOIN products AS p ON p.product_id = t.product_id
            INNER JOIN categories AS c ON c.category_id = p.category_id
            ' . $whereSql . '
            ORDER BY t.transaction_date DESC, t.transaction_id DESC
            LIMIT ? OFFSET ?'
        );
        $bindReportFilters($transactionsStatement, $pageSize, $offset);
        $transactionsStatement->execute();
        $transactions = $transactionsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
        $transactionsStatement->close();
    } else {
        $totalPages = 1;
    }
} catch (Throwable $exception) {
    error_log('Reports query failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Reports could not be loaded. Please try again later.');
}

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filterParams = [];
foreach ([
    'start_date' => $startDate,
    'end_date' => $endDate,
    'type' => $transactionType,
    'product_search' => $productSearch,
    'category_id' => $categoryId === null ? '' : (string) $categoryId,
] as $key => $value) {
    if ($value !== '') {
        $filterParams[$key] = $value;
    }
}
$hasFilters = $filterParams !== [];
$paginationUrl = static function (int $page) use ($reportUrl, $filterParams): string {
    return $reportUrl . '?' . http_build_query($filterParams + ['page' => (string) $page]);
};
$formatReportDate = static function (string $value) use ($isValidDate): string {
    return $isValidDate($value) ? date('M j, Y', strtotime($value)) : 'Invalid date';
};
$dateRangeLabel = 'All dates';
if ($startDate !== '' || $endDate !== '') {
    $dateRangeLabel = ($startDate === '' ? 'Beginning' : $formatReportDate($startDate))
        . ' – '
        . ($endDate === '' ? 'Current' : $formatReportDate($endDate));
}
$selectedTypeLabel = $transactionType === '' ? 'All types' : $allowedTypes[$transactionType];
$selectedCategoryLabel = $categoryId === null
    ? 'All categories'
    : (string) ($categoriesById[(string) $categoryId]['category_name'] ?? 'Invalid category');
$alertReports = [
    [
        'key' => 'low_stock',
        'title' => 'Low-stock products',
        'description' => 'Products with a positive quantity at or below their reorder level.',
        'count' => (int) $inventorySummary['low_stock_products'],
        'products' => $lowStockProducts,
    ],
    [
        'key' => 'out_of_stock',
        'title' => 'Out-of-stock products',
        'description' => 'Products with no stock currently available.',
        'count' => (int) $inventorySummary['out_of_stock_products'],
        'products' => $outOfStockProducts,
    ],
];

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-heading report-page-heading">
    <div>
        <span class="eyebrow">BUSINESS INSIGHTS</span>
        <h2>Reports &amp; analytics</h2>
        <p>Review current inventory and analyze recorded stock movements.</p>
    </div>
    <div class="report-page-actions">
        <button class="btn btn-primary" type="button" id="printReportButton">
            <i class="bi bi-printer" aria-hidden="true"></i>
            Print Report
        </button>
    </div>
</div>

<div class="report-print-heading">
    <h1>Grocery Inventory Pro — Reports &amp; Analytics</h1>
    <p><strong>Transaction date range:</strong> <?= $escape($dateRangeLabel) ?></p>
    <p>
        <strong>Filters:</strong>
        <?= $escape($selectedTypeLabel) ?> ·
        <?= $escape($selectedCategoryLabel) ?>
        <?php if ($productSearch !== ''): ?>
            · Product search: <?= $escape($productSearch) ?>
        <?php endif; ?>
    </p>
    <p><strong>Generated:</strong> <?= $escape(date('M j, Y g:i A')) ?></p>
</div>

<?php if ($inputError !== ''): ?>
    <div class="alert alert-warning report-filter-message" role="alert">
        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
        <?= $escape($inputError) ?>
    </div>
<?php endif; ?>

<section class="report-summary-section" aria-labelledby="inventorySummaryTitle">
    <div class="report-section-heading">
        <div>
            <span class="eyebrow">CURRENT STOCK</span>
            <h2 id="inventorySummaryTitle">Inventory summary</h2>
            <p>Live totals based on product quantities and reorder levels.</p>
        </div>
    </div>

    <div class="report-summary-grid">
        <article class="stat-card report-stat-card">
            <div class="stat-card-top">
                <span class="stat-label">Total products</span>
                <span class="stat-icon stat-icon-green"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
            </div>
            <div class="stat-value"><?= $escape($inventorySummary['total_products']) ?></div>
            <div class="stat-note">Products in the catalog</div>
        </article>
        <article class="stat-card report-stat-card">
            <div class="stat-card-top">
                <span class="stat-label">Inventory quantity</span>
                <span class="stat-icon stat-icon-blue"><i class="bi bi-boxes" aria-hidden="true"></i></span>
            </div>
            <div class="stat-value"><?= $escape($inventorySummary['total_quantity']) ?></div>
            <div class="stat-note">Across all product units</div>
        </article>
        <article class="stat-card report-stat-card">
            <div class="stat-card-top">
                <span class="stat-label">In stock</span>
                <span class="stat-icon stat-icon-green"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
            </div>
            <div class="stat-value"><?= $escape($inventorySummary['in_stock_products']) ?></div>
            <div class="stat-note">Above reorder level</div>
        </article>
        <article class="stat-card report-stat-card">
            <div class="stat-card-top">
                <span class="stat-label">Low stock</span>
                <span class="stat-icon stat-icon-amber"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span>
            </div>
            <div class="stat-value"><?= $escape($inventorySummary['low_stock_products']) ?></div>
            <div class="stat-note">Reorder soon</div>
        </article>
        <article class="stat-card report-stat-card">
            <div class="stat-card-top">
                <span class="stat-label">Out of stock</span>
                <span class="stat-icon stat-icon-red"><i class="bi bi-archive" aria-hidden="true"></i></span>
            </div>
            <div class="stat-value"><?= $escape($inventorySummary['out_of_stock_products']) ?></div>
            <div class="stat-note">Needs replenishment</div>
        </article>
    </div>
</section>

<section class="report-alert-section" aria-labelledby="stockAlertsTitle">
    <div class="report-section-heading">
        <div>
            <span class="eyebrow">REPLENISHMENT</span>
            <h2 id="stockAlertsTitle">Stock alerts</h2>
            <p>Review products that are running low or currently unavailable.</p>
        </div>
    </div>

    <div class="report-alert-grid">
        <?php foreach ($alertReports as $alertReport): ?>
            <article class="panel-card report-alert-card">
                <div class="report-alert-card-heading">
                    <div>
                        <h3><?= $escape($alertReport['title']) ?></h3>
                        <p><?= $escape($alertReport['description']) ?></p>
                    </div>
                    <span class="report-alert-count"><?= $escape($alertReport['count']) ?></span>
                </div>

                <?php if ($alertReport['products'] === []): ?>
                    <div class="report-alert-empty">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>
                        <span>No products in this status.</span>
                    </div>
                <?php else: ?>
                    <div class="table-responsive report-alert-table-wrap">
                        <table class="table inventory-table report-alert-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">PRODUCT</th>
                                    <th scope="col">CATEGORY</th>
                                    <th scope="col">QUANTITY</th>
                                    <th scope="col">STATUS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alertReport['products'] as $product): ?>
                                    <?php $status = inventoryStatusFor((int) $product['quantity'], (int) $product['reorder_level']); ?>
                                    <tr>
                                        <td class="product-name-cell">
                                            <span class="product-name"><?= $escape($product['product_name']) ?></span>
                                            <span class="product-sku">SKU: <?= $escape($product['sku']) ?></span>
                                        </td>
                                        <td class="category-cell"><?= $escape($product['category_name']) ?></td>
                                        <td class="quantity-cell"><?= $escape($product['quantity']) ?> <span><?= $escape($product['unit']) ?></span></td>
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

                <a class="text-action report-alert-action" href="<?= $escape($alertsUrl) ?>?status=<?= $escape($alertReport['key']) ?>">
                    View all <?= $escape(strtolower($alertReport['title'])) ?>
                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="panel-card report-movement-panel" aria-labelledby="stockMovementTitle">
    <div class="report-movement-heading">
        <div>
            <span class="eyebrow">TRANSACTION ANALYSIS</span>
            <h2 id="stockMovementTitle">Stock movement report</h2>
            <p>Totals and records below reflect the selected filters.</p>
        </div>
    </div>

    <form class="report-filter-form" method="get" action="<?= $escape($reportUrl) ?>">
        <div class="report-filter-field">
            <label for="reportStartDate">Start date</label>
            <input class="form-control" type="date" id="reportStartDate" name="start_date" value="<?= $escape($startDate) ?>" min="1970-01-01" max="2037-12-31">
        </div>
        <div class="report-filter-field">
            <label for="reportEndDate">End date</label>
            <input class="form-control" type="date" id="reportEndDate" name="end_date" value="<?= $escape($endDate) ?>" min="1970-01-01" max="2037-12-31">
        </div>
        <div class="report-filter-field">
            <label for="reportType">Transaction type</label>
            <select class="form-select" id="reportType" name="type">
                <option value="">All types</option>
                <?php foreach ($allowedTypes as $typeValue => $typeLabel): ?>
                    <option value="<?= $escape($typeValue) ?>" <?= $transactionType === $typeValue ? 'selected' : '' ?>>
                        <?= $escape($typeLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="report-filter-field report-search-field">
            <label for="reportProductSearch">Product</label>
            <input
                class="form-control"
                type="search"
                id="reportProductSearch"
                name="product_search"
                value="<?= $escape($productSearch) ?>"
                maxlength="255"
                placeholder="Name or SKU"
            >
        </div>
        <div class="report-filter-field">
            <label for="reportCategory">Category</label>
            <select class="form-select" id="reportCategory" name="category_id">
                <option value="">All categories</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= $escape($category['category_id']) ?>" <?= $categoryId === (int) $category['category_id'] ? 'selected' : '' ?>>
                        <?= $escape($category['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary report-filter-submit" type="submit">
            <i class="bi bi-funnel" aria-hidden="true"></i>
            Apply filters
        </button>
        <a class="btn btn-outline-primary report-filter-clear" href="<?= $escape($reportUrl) ?>">Clear filters</a>
    </form>

    <div class="report-movement-summary" aria-label="Filtered stock movement totals">
        <div>
            <span class="report-movement-summary-label">Total Stock In</span>
            <strong><?= $escape($movementSummary['stock_in_quantity']) ?></strong>
        </div>
        <div>
            <span class="report-movement-summary-label">Total Stock Out</span>
            <strong><?= $escape($movementSummary['stock_out_quantity']) ?></strong>
        </div>
        <div>
            <span class="report-movement-summary-label">Transactions</span>
            <strong><?= $escape($movementSummary['transaction_count']) ?></strong>
        </div>
    </div>

    <?php if ($inputError !== ''): ?>
        <div class="report-results-message" role="status">Correct the filters above to run the report.</div>
    <?php elseif ($transactions === []): ?>
        <div class="empty-state report-empty-state">
            <span class="empty-state-icon"><i class="bi bi-clipboard-data" aria-hidden="true"></i></span>
            <p>No stock transactions found</p>
            <span>Transactions that match the selected filters will appear here.</span>
        </div>
    <?php else: ?>
        <div class="table-responsive report-table-wrap">
            <table class="table table-hover inventory-table report-table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">DATE</th>
                        <th scope="col">PRODUCT</th>
                        <th scope="col">SKU</th>
                        <th scope="col">CATEGORY</th>
                        <th scope="col">TYPE</th>
                        <th scope="col">QUANTITY</th>
                        <th scope="col">REASON</th>
                        <th scope="col">NOTES</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <?php
                        $isStockIn = $transaction['transaction_type'] === 'stock_in';
                        $typeLabel = $isStockIn ? 'Stock In' : 'Stock Out';
                        $typeClass = $isStockIn ? 'stock-transaction-in' : 'stock-transaction-out';
                        ?>
                        <tr>
                            <td class="report-date-cell"><?= $escape(date('M j, Y g:i A', strtotime((string) $transaction['transaction_date']))) ?></td>
                            <td class="product-name-cell"><?= $escape($transaction['product_name']) ?></td>
                            <td class="stock-sku-cell"><?= $escape($transaction['sku']) ?></td>
                            <td class="category-cell"><?= $escape($transaction['category_name']) ?></td>
                            <td>
                                <span class="stock-transaction-badge <?= $escape($typeClass) ?>">
                                    <?= $escape($typeLabel) ?>
                                </span>
                            </td>
                            <td class="quantity-cell"><?= $escape($transaction['quantity']) ?> <span><?= $escape($transaction['unit']) ?></span></td>
                            <td class="report-notes-cell"><?= $escape($transaction['reason']) ?></td>
                            <td class="report-notes-cell"><?= $transaction['notes'] === null || $transaction['notes'] === '' ? '—' : $escape($transaction['notes']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="report-pagination" aria-label="Stock movement report pages">
                <a class="btn btn-outline-primary<?= $currentPage <= 1 ? ' disabled' : '' ?>" href="<?= $escape($paginationUrl(max(1, $currentPage - 1))) ?>"<?= $currentPage <= 1 ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                    Previous
                </a>
                <span>Page <?= $escape($currentPage) ?> of <?= $escape($totalPages) ?></span>
                <a class="btn btn-outline-primary<?= $currentPage >= $totalPages ? ' disabled' : '' ?>" href="<?= $escape($paginationUrl(min($totalPages, $currentPage + 1))) ?>"<?= $currentPage >= $totalPages ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
                    Next
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
