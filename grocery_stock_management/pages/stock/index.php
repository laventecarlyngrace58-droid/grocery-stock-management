<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/csrf.php';

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/pages/stock/index.php'));
$baseUrl = dirname(dirname($scriptDirectory));
$baseUrl = $baseUrl === '/' || $baseUrl === '.' ? '' : rtrim($baseUrl, '/');

$pageTitle = 'Stock Management';
$pageSubtitle = 'Record stock movements and review inventory history.';
$csrfToken = getCsrfToken();
$allowedTypes = ['stock_in', 'stock_out'];
$formValues = [
    'product_id' => '',
    'transaction_type' => 'stock_in',
    'quantity' => '',
    'transaction_date' => date('Y-m-d\TH:i'),
    'reason' => '',
    'notes' => '',
];
$errors = [];
$pageError = '';
$formStatus = 200;
$products = [];
$productsById = [];
$transactions = [];
$totalTransactions = 0;
$pageSize = 10;
$transactionStarted = false;

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$textLength = static fn (string $value): int => function_exists('mb_strlen')
    ? mb_strlen($value, 'UTF-8')
    : strlen($value);
$normalizeUnsignedInteger = static function (string $value): ?string {
    if (!preg_match('/^\d+$/D', $value)) {
        return null;
    }

    $normalized = ltrim($value, '0');
    $normalized = $normalized === '' ? '0' : $normalized;

    if (
        strlen($normalized) > 10
        || (strlen($normalized) === 10 && strcmp($normalized, '4294967295') > 0)
    ) {
        return null;
    }

    return $normalized;
};
$normalizeProductId = static function ($value) use ($normalizeUnsignedInteger): ?string {
    if (!is_string($value)) {
        return null;
    }

    $normalized = $normalizeUnsignedInteger($value);
    return $normalized === null || $normalized === '0' ? null : $normalized;
};
$parseDateTime = static function (string $value): ?string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value)) {
        return null;
    }

    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (
        $dateTime === false
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $dateTime->format('Y-m-d\TH:i') !== $value
        || (int) $dateTime->format('Y') < 1970
        || (int) $dateTime->format('Y') > 2037
    ) {
        return null;
    }

    return $dateTime->format('Y-m-d H:i:s');
};

$stockUrl = $baseUrl . '/pages/stock/index.php';
$rawType = $_GET['type'] ?? 'stock_in';
$defaultType = is_string($rawType) && in_array($rawType, $allowedTypes, true)
    ? $rawType
    : 'stock_in';
$formValues['transaction_type'] = $defaultType;

$search = '';
$rawSearch = $_GET['search'] ?? '';
if (!is_string($rawSearch)) {
    $pageError = 'Enter one search term.';
} else {
    $search = trim($rawSearch);
    if ($textLength($search) > 255) {
        $pageError = 'Search text must be 255 characters or fewer.';
    }
}

$filterType = '';
$rawFilterType = $_GET['filter_type'] ?? '';
if (!is_string($rawFilterType) || ($rawFilterType !== '' && !in_array($rawFilterType, $allowedTypes, true))) {
    $pageError = 'Choose a valid transaction type filter.';
} else {
    $filterType = $rawFilterType;
}

$filterDate = '';
$rawFilterDate = $_GET['date'] ?? '';
if (!is_string($rawFilterDate)) {
    $pageError = 'Choose a valid transaction date.';
} elseif ($rawFilterDate !== '') {
    $dateFilter = DateTimeImmutable::createFromFormat('!Y-m-d', $rawFilterDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (
        $dateFilter === false
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $dateFilter->format('Y-m-d') !== $rawFilterDate
    ) {
        $pageError = 'Choose a valid transaction date.';
    } else {
        $filterDate = $rawFilterDate;
    }
}

$rawPage = $_GET['page'] ?? '1';
if (!is_string($rawPage) || !ctype_digit($rawPage) || strlen($rawPage) > 9) {
    $pageError = 'Choose a valid history page.';
    $currentPage = 1;
} else {
    $currentPage = max(1, (int) $rawPage);
}

$success = isset($_GET['status']) && is_string($_GET['status']) && $_GET['status'] === 'recorded';
$rawSection = $_GET['section'] ?? '';
$activeNav = is_string($rawSection) && $rawSection === 'history'
    ? 'transactions'
    : ($defaultType === 'stock_out' ? 'stock-out' : 'stock-in');

try {
    $productsStatement = $conn->prepare(
        'SELECT product_id, product_name, sku, quantity, unit
        FROM products
        ORDER BY product_name ASC'
    );
    $productsStatement->execute();
    $products = $productsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $productsStatement->close();

    foreach ($products as $productRow) {
        $productsById[(string) $productRow['product_id']] = $productRow;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $rawAction = $_POST['action'] ?? '';
        if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
            $pageError = 'The form expired or is invalid. Refresh the page and try again.';
            $formStatus = 403;
        } elseif (!is_string($rawAction) || $rawAction !== 'record_stock') {
            $pageError = 'Choose a valid stock action.';
            $formStatus = 400;
        } else {
            foreach (array_keys($formValues) as $field) {
                if (!array_key_exists($field, $_POST) || !is_string($_POST[$field])) {
                    $errors[$field] = 'Enter a valid value.';
                    continue;
                }

                $formValues[$field] = trim($_POST[$field]);
            }

            if (!in_array($formValues['transaction_type'], $allowedTypes, true)) {
                $errors['transaction_type'] = 'Choose Stock In or Stock Out.';
            }

            $productId = $normalizeProductId($formValues['product_id']);
            if ($productId === null) {
                $errors['product_id'] = $products === []
                    ? 'There are no products available. Add a product before recording stock.'
                    : 'Choose a valid product.';
            } elseif (!isset($productsById[$productId])) {
                $errors['product_id'] = 'Choose a product from the available list.';
            } else {
                $formValues['product_id'] = $productId;
            }

            $quantityValue = $normalizeUnsignedInteger($formValues['quantity']);
            if ($quantityValue === null || $quantityValue === '0') {
                $errors['quantity'] = 'Enter a positive whole-number quantity.';
            }

            $transactionDateValue = $parseDateTime($formValues['transaction_date']);
            if ($transactionDateValue === null) {
                $errors['transaction_date'] = 'Enter a valid transaction date and time.';
            }

            if ($formValues['reason'] === '') {
                $errors['reason'] = 'A reason is required.';
            } elseif ($textLength($formValues['reason']) > 100) {
                $errors['reason'] = 'Reason must be 100 characters or fewer.';
            }

            if ($textLength($formValues['notes']) > 500) {
                $errors['notes'] = 'Notes must be 500 characters or fewer.';
            }

            if ($errors !== []) {
                $formStatus = 422;
            } elseif ($pageError === '') {
                try {
                    $conn->begin_transaction();
                    $transactionStarted = true;

                    $productLockStatement = $conn->prepare(
                        'SELECT quantity
                        FROM products
                        WHERE product_id = ?
                        FOR UPDATE'
                    );
                    $productLockStatement->bind_param('s', $productId);
                    $productLockStatement->execute();
                    $lockedProduct = $productLockStatement->get_result()->fetch_assoc();
                    $productLockStatement->close();

                    if ($lockedProduct === null) {
                        $conn->rollback();
                        $transactionStarted = false;
                        $errors['product_id'] = 'The selected product no longer exists.';
                        $formStatus = 404;
                    } else {
                        $currentQuantity = (int) $lockedProduct['quantity'];
                        $movementQuantity = (int) $quantityValue;
                        $maximumQuantity = 4294967295;

                        if (
                            $formValues['transaction_type'] === 'stock_out'
                            && $movementQuantity > $currentQuantity
                        ) {
                            $conn->rollback();
                            $transactionStarted = false;
                            $errors['quantity'] = sprintf(
                                'Only %d units are currently in stock.',
                                $currentQuantity
                            );
                            $formStatus = 422;
                        } elseif (
                            $formValues['transaction_type'] === 'stock_in'
                            && $movementQuantity > $maximumQuantity - $currentQuantity
                        ) {
                            $conn->rollback();
                            $transactionStarted = false;
                            $errors['quantity'] = 'This stock-in quantity exceeds the maximum supported stock level.';
                            $formStatus = 422;
                        } else {
                            $newQuantity = $formValues['transaction_type'] === 'stock_in'
                                ? (string) ($currentQuantity + $movementQuantity)
                                : (string) ($currentQuantity - $movementQuantity);

                            $updateStatement = $conn->prepare(
                                'UPDATE products
                                SET quantity = ?
                                WHERE product_id = ?'
                            );
                            $updateStatement->bind_param('ss', $newQuantity, $productId);
                            $updateStatement->execute();
                            $updateStatement->close();

                            $notesValue = $formValues['notes'] === '' ? null : $formValues['notes'];
                            $transactionType = $formValues['transaction_type'];
                            $insertStatement = $conn->prepare(
                                'INSERT INTO stock_transactions (
                                    product_id,
                                    transaction_type,
                                    quantity,
                                    reason,
                                    notes,
                                    transaction_date
                                ) VALUES (?, ?, ?, ?, ?, ?)'
                            );
                            $insertStatement->bind_param(
                                'ssssss',
                                $productId,
                                $transactionType,
                                $quantityValue,
                                $formValues['reason'],
                                $notesValue,
                                $transactionDateValue
                            );
                            $insertStatement->execute();
                            $insertStatement->close();

                            $conn->commit();
                            $transactionStarted = false;
                            header(
                                'Location: ' . $stockUrl . '?' . http_build_query([
                                    'status' => 'recorded',
                                    'type' => $transactionType,
                                ]),
                                true,
                                303
                            );
                            exit;
                        }
                    }
                } catch (Throwable $exception) {
                    if ($transactionStarted) {
                        $conn->rollback();
                        $transactionStarted = false;
                    }

                    throw $exception;
                }
            }
        }
    }

    $historyFilters = [
        'section' => 'history',
    ];
    if ($search !== '') {
        $historyFilters['search'] = $search;
    }
    if ($filterType !== '') {
        $historyFilters['filter_type'] = $filterType;
    }
    if ($filterDate !== '') {
        $historyFilters['date'] = $filterDate;
    }

    $searchPattern = '%' . $search . '%';
    $historyCountStatement = $conn->prepare(
        'SELECT COUNT(*) AS transaction_count
        FROM stock_transactions AS t
        INNER JOIN products AS p ON p.product_id = t.product_id
        WHERE (? = \'\' OR p.product_name LIKE ? OR p.sku LIKE ? OR t.reason LIKE ? OR t.notes LIKE ?)
            AND (? = \'\' OR t.transaction_type = ?)
            AND (? = \'\' OR DATE(t.transaction_date) = ?)'
    );
    $historyCountStatement->bind_param(
        'sssssssss',
        $search,
        $searchPattern,
        $searchPattern,
        $searchPattern,
        $searchPattern,
        $filterType,
        $filterType,
        $filterDate,
        $filterDate
    );
    $historyCountStatement->execute();
    $totalTransactions = (int) $historyCountStatement->get_result()->fetch_assoc()['transaction_count'];
    $historyCountStatement->close();

    $totalPages = max(1, (int) ceil($totalTransactions / $pageSize));
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $pageSize;

    $historyStatement = $conn->prepare(
        'SELECT
            t.transaction_id,
            t.transaction_date,
            t.transaction_type,
            t.quantity,
            t.reason,
            t.notes,
            p.product_name,
            p.sku,
            p.unit
        FROM stock_transactions AS t
        INNER JOIN products AS p ON p.product_id = t.product_id
        WHERE (? = \'\' OR p.product_name LIKE ? OR p.sku LIKE ? OR t.reason LIKE ? OR t.notes LIKE ?)
            AND (? = \'\' OR t.transaction_type = ?)
            AND (? = \'\' OR DATE(t.transaction_date) = ?)
        ORDER BY t.transaction_date DESC, t.transaction_id DESC
        LIMIT ? OFFSET ?'
    );
    $historyStatement->bind_param(
        'sssssssssii',
        $search,
        $searchPattern,
        $searchPattern,
        $searchPattern,
        $searchPattern,
        $filterType,
        $filterType,
        $filterDate,
        $filterDate,
        $pageSize,
        $offset
    );
    $historyStatement->execute();
    $transactions = $historyStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $historyStatement->close();
} catch (Throwable $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }

    error_log('Stock management request failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Stock information could not be processed. Please try again later.');
}

if ($pageError !== '' && $formStatus === 200) {
    $formStatus = 400;
}
if ($formStatus !== 200) {
    http_response_code($formStatus);
}

$success = $success || (isset($_GET['status']) && is_string($_GET['status']) && $_GET['status'] === 'recorded');
$selectedProduct = $productsById[$formValues['product_id']] ?? null;
$historyAction = $stockUrl . '?' . http_build_query(['section' => 'history']) . '#transaction-history';
$paginationUrl = static function (int $page) use ($stockUrl, $historyFilters): string {
    $query = $historyFilters;
    $query['page'] = $page;
    return $stockUrl . '?' . http_build_query($query) . '#transaction-history';
};

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-heading stock-page-heading">
    <div>
        <span class="eyebrow">INVENTORY OPERATIONS</span>
        <h2>Stock management</h2>
        <p>Record deliveries and outgoing stock while keeping an accurate movement history.</p>
    </div>
    <a class="btn btn-outline-primary stock-history-shortcut" href="#transaction-history">
        <i class="bi bi-clock-history" aria-hidden="true"></i>
        Transaction history
    </a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success stock-page-message" role="status">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
        Stock movement recorded successfully.
    </div>
<?php endif; ?>

<?php if ($pageError !== ''): ?>
    <div class="alert alert-warning stock-page-message" role="alert">
        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
        <?= $escape($pageError) ?>
    </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger stock-page-message" role="alert">
        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
        <strong>Stock movement not saved.</strong> Review the highlighted fields and try again.
    </div>
<?php endif; ?>

<section class="panel-card stock-form-card" aria-labelledby="stockFormTitle">
    <div class="product-form-section-heading">
        <div>
            <span class="eyebrow">RECORD MOVEMENT</span>
            <h3 id="stockFormTitle">Stock In / Stock Out</h3>
            <p>Stock and history are saved together to keep inventory consistent.</p>
        </div>
    </div>

    <?php if ($products === []): ?>
        <div class="alert alert-warning stock-page-message" role="status">
            <i class="bi bi-box-seam" aria-hidden="true"></i>
            No products are available yet.
            <a href="<?= $escape($baseUrl) ?>/pages/products/add.php">Add a product</a> before recording stock.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= $escape($stockUrl) ?>" class="stock-form" data-stock-form>
        <input type="hidden" name="action" value="record_stock">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">

        <div class="stock-form-grid">
            <div class="product-form-field">
                <label for="stockType">Transaction type <span class="required-mark">*</span></label>
                <select
                    class="form-select<?= isset($errors['transaction_type']) ? ' is-invalid' : '' ?>"
                    id="stockType"
                    name="transaction_type"
                    required
                    <?= isset($errors['transaction_type']) ? 'aria-invalid="true" aria-describedby="stockTypeError"' : '' ?>
                >
                    <option value="stock_in" <?= $formValues['transaction_type'] === 'stock_in' ? 'selected' : '' ?>>Stock In</option>
                    <option value="stock_out" <?= $formValues['transaction_type'] === 'stock_out' ? 'selected' : '' ?>>Stock Out</option>
                </select>
                <?php if (isset($errors['transaction_type'])): ?>
                    <div class="invalid-feedback" id="stockTypeError"><?= $escape($errors['transaction_type']) ?></div>
                <?php endif; ?>
            </div>

            <div class="product-form-field">
                <label for="stockProduct">Product <span class="required-mark">*</span></label>
                <select
                    class="form-select<?= isset($errors['product_id']) ? ' is-invalid' : '' ?>"
                    id="stockProduct"
                    name="product_id"
                    required
                    <?= $products === [] ? 'disabled' : '' ?>
                    <?= isset($errors['product_id']) ? 'aria-invalid="true" aria-describedby="stockProductError"' : '' ?>
                >
                    <?php if ($formValues['product_id'] !== '' && $selectedProduct === null): ?>
                        <option value="<?= $escape($formValues['product_id']) ?>" selected>Unavailable product</option>
                    <?php endif; ?>
                    <option value="" disabled <?= $formValues['product_id'] === '' ? 'selected' : '' ?>>Select a product</option>
                    <?php foreach ($products as $productOption): ?>
                        <option
                            value="<?= $escape($productOption['product_id']) ?>"
                            data-product-name="<?= $escape($productOption['product_name']) ?>"
                            data-sku="<?= $escape($productOption['sku']) ?>"
                            data-quantity="<?= $escape($productOption['quantity']) ?>"
                            data-unit="<?= $escape($productOption['unit']) ?>"
                            <?= $formValues['product_id'] === (string) $productOption['product_id'] ? 'selected' : '' ?>
                        >
                            <?= $escape($productOption['product_name']) ?> (<?= $escape($productOption['sku']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['product_id'])): ?>
                    <div class="invalid-feedback" id="stockProductError"><?= $escape($errors['product_id']) ?></div>
                <?php endif; ?>
            </div>

            <div class="stock-product-summary stock-form-field-wide" aria-live="polite">
                <div class="stock-product-identity">
                    <span class="stock-summary-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
                    <div>
                        <span class="stock-summary-label">Selected product</span>
                        <strong id="stockProductName"><?= $escape($selectedProduct['product_name'] ?? 'Choose a product') ?></strong>
                        <span id="stockProductSku"><?= $selectedProduct !== null ? 'SKU: ' . $escape($selectedProduct['sku']) : 'Product SKU' ?></span>
                    </div>
                </div>
                <div class="stock-current-quantity">
                    <span class="stock-summary-label">Current stock</span>
                    <strong><span id="stockCurrentQuantity"><?= $escape($selectedProduct['quantity'] ?? '—') ?></span> <span id="stockProductUnit"><?= $escape($selectedProduct['unit'] ?? '') ?></span></strong>
                </div>
            </div>

            <div class="product-form-field">
                <label for="stockQuantity">Quantity <span class="required-mark">*</span></label>
                <input
                    class="form-control<?= isset($errors['quantity']) ? ' is-invalid' : '' ?>"
                    type="number"
                    id="stockQuantity"
                    name="quantity"
                    min="1"
                    max="4294967295"
                    step="1"
                    value="<?= $escape($formValues['quantity']) ?>"
                    required
                    <?= isset($errors['quantity']) ? 'aria-invalid="true" aria-describedby="stockQuantityError"' : '' ?>
                >
                <?php if (isset($errors['quantity'])): ?>
                    <div class="invalid-feedback" id="stockQuantityError"><?= $escape($errors['quantity']) ?></div>
                <?php endif; ?>
            </div>

            <div class="product-form-field">
                <label for="transactionDate">Transaction date <span class="required-mark">*</span></label>
                <input
                    class="form-control<?= isset($errors['transaction_date']) ? ' is-invalid' : '' ?>"
                    type="datetime-local"
                    id="transactionDate"
                    name="transaction_date"
                    min="1970-01-01T00:00"
                    max="2037-12-31T23:59"
                    value="<?= $escape($formValues['transaction_date']) ?>"
                    required
                    <?= isset($errors['transaction_date']) ? 'aria-invalid="true" aria-describedby="transactionDateError"' : '' ?>
                >
                <?php if (isset($errors['transaction_date'])): ?>
                    <div class="invalid-feedback" id="transactionDateError"><?= $escape($errors['transaction_date']) ?></div>
                <?php endif; ?>
            </div>

            <div class="product-form-field">
                <label for="stockReason">Reason <span class="required-mark">*</span></label>
                <input
                    class="form-control<?= isset($errors['reason']) ? ' is-invalid' : '' ?>"
                    type="text"
                    id="stockReason"
                    name="reason"
                    maxlength="100"
                    value="<?= $escape($formValues['reason']) ?>"
                    placeholder="e.g. Delivery, sale, or damage"
                    required
                    <?= isset($errors['reason']) ? 'aria-invalid="true" aria-describedby="stockReasonError"' : '' ?>
                >
                <?php if (isset($errors['reason'])): ?>
                    <div class="invalid-feedback" id="stockReasonError"><?= $escape($errors['reason']) ?></div>
                <?php endif; ?>
            </div>

            <div class="product-form-field stock-form-field-wide">
                <label for="stockNotes">Notes <span class="optional-label">Optional</span></label>
                <textarea
                    class="form-control<?= isset($errors['notes']) ? ' is-invalid' : '' ?>"
                    id="stockNotes"
                    name="notes"
                    rows="3"
                    maxlength="500"
                    <?= isset($errors['notes']) ? 'aria-invalid="true" aria-describedby="stockNotesError"' : '' ?>
                ><?= $escape($formValues['notes']) ?></textarea>
                <?php if (isset($errors['notes'])): ?>
                    <div class="invalid-feedback" id="stockNotesError"><?= $escape($errors['notes']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="product-form-actions">
            <button class="btn btn-primary save-product-button" type="submit" <?= $products === [] ? 'disabled' : '' ?>>
                <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
                Record Stock Movement
            </button>
        </div>
    </form>
</section>

<section class="panel-card stock-history-panel" id="transaction-history" aria-labelledby="stockHistoryTitle">
    <div class="stock-history-header">
        <div>
            <span class="eyebrow">AUDIT TRAIL</span>
            <h3 id="stockHistoryTitle">Transaction history</h3>
            <p>Review recorded stock movements. Showing <?= $escape($totalTransactions) ?> matching records.</p>
        </div>
    </div>

    <?php if ($pageError !== '' && $search !== ''): ?>
        <div class="alert alert-warning stock-filter-message" role="alert"><?= $escape($pageError) ?></div>
    <?php endif; ?>

    <form class="stock-filter-form" method="get" action="<?= $escape($stockUrl) ?>#transaction-history">
        <input type="hidden" name="section" value="history">
        <div class="stock-filter-field stock-search-field">
            <label for="historySearch">Search history</label>
            <div class="product-search-control">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input
                    class="form-control"
                    type="search"
                    id="historySearch"
                    name="search"
                    maxlength="255"
                    value="<?= $escape($search) ?>"
                    placeholder="Product, SKU, or reason"
                >
            </div>
        </div>
        <div class="stock-filter-field">
            <label for="historyType">Transaction type</label>
            <select class="form-select" id="historyType" name="filter_type">
                <option value="">All types</option>
                <option value="stock_in" <?= $filterType === 'stock_in' ? 'selected' : '' ?>>Stock In</option>
                <option value="stock_out" <?= $filterType === 'stock_out' ? 'selected' : '' ?>>Stock Out</option>
            </select>
        </div>
        <div class="stock-filter-field">
            <label for="historyDate">Date</label>
            <input class="form-control" type="date" id="historyDate" name="date" value="<?= $escape($filterDate) ?>">
        </div>
        <button class="btn btn-primary stock-filter-submit" type="submit">
            <i class="bi bi-funnel" aria-hidden="true"></i>
            Filter
        </button>
        <?php if ($search !== '' || $filterType !== '' || $filterDate !== ''): ?>
            <a class="btn btn-outline-primary stock-filter-clear" href="<?= $escape($stockUrl) ?>#transaction-history">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($transactions === []): ?>
        <div class="empty-state stock-empty-state">
            <span class="empty-state-icon <?= $search !== '' || $filterType !== '' || $filterDate !== '' ? '' : 'empty-state-success' ?>">
                <i class="bi <?= $search !== '' || $filterType !== '' || $filterDate !== '' ? 'bi-search' : 'bi-clock-history' ?>" aria-hidden="true"></i>
            </span>
            <?php if ($search !== '' || $filterType !== '' || $filterDate !== ''): ?>
                <p>No stock movements match these filters</p>
                <span>Try changing your search or clearing one of the filters.</span>
            <?php else: ?>
                <p>No stock movements recorded yet</p>
                <span>Stock In and Stock Out activity will appear here after it is recorded.</span>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive stock-table-wrap">
            <table class="table table-hover inventory-table stock-table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">DATE</th>
                        <th scope="col">PRODUCT</th>
                        <th scope="col">SKU</th>
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
                            <td class="stock-notes-cell">
                                <?= $transaction['notes'] === null || $transaction['notes'] === '' ? '<span class="not-set">—</span>' : $escape($transaction['notes']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="stock-pagination" aria-label="Transaction history pages">
                <a class="btn btn-outline-primary <?= $currentPage <= 1 ? 'disabled' : '' ?>"
                   href="<?= $escape($paginationUrl(max(1, $currentPage - 1))) ?>"
                   <?= $currentPage <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                    Previous
                </a>
                <span>Page <?= $escape($currentPage) ?> of <?= $escape($totalPages) ?></span>
                <a class="btn btn-outline-primary <?= $currentPage >= $totalPages ? 'disabled' : '' ?>"
                   href="<?= $escape($paginationUrl(min($totalPages, $currentPage + 1))) ?>"
                   <?= $currentPage >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                    Next
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
