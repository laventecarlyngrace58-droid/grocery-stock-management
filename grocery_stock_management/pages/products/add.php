<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/csrf.php';

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/pages/products/add.php'));
$baseUrl = dirname(dirname($scriptDirectory));
$baseUrl = $baseUrl === '/' || $baseUrl === '.' ? '' : rtrim($baseUrl, '/');

$pageTitle = 'Add Product';
$pageSubtitle = 'Add a product to your grocery inventory.';
$activeNav = 'products';
$csrfToken = getCsrfToken();
$allowedUnits = ['each', 'pcs', 'kg', 'g', 'liter', 'bottle', 'pack', 'box'];
$formValues = [
    'product_name' => '',
    'sku' => '',
    'category_id' => '',
    'description' => '',
    'unit' => 'each',
    'quantity' => '0',
    'reorder_level' => '5',
    'cost_price' => '0.00',
    'selling_price' => '0.00',
    'expiration_date' => '',
];
$errors = [];
$categories = [];
$categoryIds = [];
$formStatus = 200;
$transactionStarted = false;
$success = isset($_GET['success']) && $_GET['success'] === '1';

$textLength = static function (string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
};

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

$normalizePrice = static function (string $value): ?string {
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $value)) {
        return null;
    }

    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $whole = ltrim($whole, '0');
    $whole = $whole === '' ? '0' : $whole;

    if (strlen($whole) > 8) {
        return null;
    }

    return $whole . '.' . str_pad($fraction, 2, '0');
};

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
        $categoryIds[(string) $category['category_id']] = true;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
            $errors['_csrf'] = 'The form expired or is invalid. Refresh the page and try again.';
            $formStatus = 403;
        }

        foreach (array_keys($formValues) as $field) {
            if (!array_key_exists($field, $_POST)) {
                continue;
            }

            if (!is_string($_POST[$field])) {
                $errors[$field] = 'Enter a valid value.';
                continue;
            }

            $formValues[$field] = trim($_POST[$field]);
        }

        if ($formValues['product_name'] === '') {
            $errors['product_name'] = 'Product name is required.';
        } elseif ($textLength($formValues['product_name']) > 150) {
            $errors['product_name'] = 'Product name must be 150 characters or fewer.';
        }

        if ($formValues['sku'] === '') {
            $errors['sku'] = 'SKU or barcode is required.';
        } elseif ($textLength($formValues['sku']) > 64) {
            $errors['sku'] = 'SKU or barcode must be 64 characters or fewer.';
        }

        $categoryValue = $normalizeUnsignedInteger($formValues['category_id']);
        if ($formValues['category_id'] === '') {
            $errors['category_id'] = 'Choose a category.';
        } elseif ($categoryValue === null || $categoryValue === '0' || !isset($categoryIds[$categoryValue])) {
            $errors['category_id'] = 'Choose a category from the available list.';
        } else {
            $formValues['category_id'] = $categoryValue;
        }

        if ($textLength($formValues['description']) > 5000) {
            $errors['description'] = 'Description must be 5,000 characters or fewer.';
        }

        if (!in_array($formValues['unit'], $allowedUnits, true)) {
            $errors['unit'] = 'Choose a unit from the available list.';
        }

        $quantityValue = $normalizeUnsignedInteger($formValues['quantity']);
        if ($quantityValue === null) {
            $errors['quantity'] = 'Enter a whole number from 0 to 4,294,967,295.';
        }

        $reorderLevelValue = $normalizeUnsignedInteger($formValues['reorder_level']);
        if ($reorderLevelValue === null) {
            $errors['reorder_level'] = 'Enter a whole number from 0 to 4,294,967,295.';
        }

        $costPriceValue = $normalizePrice($formValues['cost_price']);
        if ($costPriceValue === null) {
            $errors['cost_price'] = 'Enter a non-negative price with up to two decimal places.';
        }

        $sellingPriceValue = $normalizePrice($formValues['selling_price']);
        if ($sellingPriceValue === null) {
            $errors['selling_price'] = 'Enter a non-negative price with up to two decimal places.';
        }

        $expirationDateValue = null;
        if ($formValues['expiration_date'] !== '') {
            $expirationDate = DateTimeImmutable::createFromFormat('!Y-m-d', $formValues['expiration_date']);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (
                $expirationDate === false
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                || $expirationDate->format('Y-m-d') !== $formValues['expiration_date']
            ) {
                $errors['expiration_date'] = 'Enter a valid expiration date.';
            } else {
                $expirationDateValue = $formValues['expiration_date'];
            }
        }

        if ($errors !== []) {
            if ($formStatus !== 403) {
                $formStatus = 422;
            }
        } else {
            $skuCheckStatement = $conn->prepare(
                'SELECT product_id
                FROM products
                WHERE sku = ?
                LIMIT 1'
            );
            $skuCheckStatement->bind_param('s', $formValues['sku']);
            $skuCheckStatement->execute();
            $duplicateSku = $skuCheckStatement->get_result()->fetch_assoc() !== null;
            $skuCheckStatement->close();

            if ($duplicateSku) {
                $errors['sku'] = 'This SKU or barcode is already in use.';
                $formStatus = 409;
            } else {
                $categoryValue = (string) $categoryValue;
                $descriptionValue = $formValues['description'] === '' ? null : $formValues['description'];
                $transactionReason = 'Initial stock';
                $transactionNotes = 'Opening stock recorded when the product was created.';

                $conn->begin_transaction();
                $transactionStarted = true;

                try {
                    $insertStatement = $conn->prepare(
                        'INSERT INTO products (
                            category_id,
                            product_name,
                            sku,
                            description,
                            unit,
                            quantity,
                            reorder_level,
                            cost_price,
                            selling_price,
                            expiration_date
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $insertStatement->bind_param(
                        'ssssssssss',
                        $categoryValue,
                        $formValues['product_name'],
                        $formValues['sku'],
                        $descriptionValue,
                        $formValues['unit'],
                        $quantityValue,
                        $reorderLevelValue,
                        $costPriceValue,
                        $sellingPriceValue,
                        $expirationDateValue
                    );
                    $insertStatement->execute();
                    $productId = (string) $conn->insert_id;
                    $insertStatement->close();

                    if ($quantityValue !== '0') {
                        $transactionType = 'stock_in';
                        $transactionStatement = $conn->prepare(
                            'INSERT INTO stock_transactions (
                                product_id,
                                transaction_type,
                                quantity,
                                reason,
                                notes
                            ) VALUES (?, ?, ?, ?, ?)'
                        );
                        $transactionStatement->bind_param(
                            'sssss',
                            $productId,
                            $transactionType,
                            $quantityValue,
                            $transactionReason,
                            $transactionNotes
                        );
                        $transactionStatement->execute();
                        $transactionStatement->close();
                    }

                    $conn->commit();
                    $transactionStarted = false;

                    header('Location: add.php?success=1', true, 303);
                    exit;
                } catch (Throwable $exception) {
                    if ($transactionStarted) {
                        $conn->rollback();
                        $transactionStarted = false;
                    }

                    if ($exception instanceof mysqli_sql_exception && $exception->getCode() === 1062) {
                        $errors['sku'] = 'This SKU or barcode is already in use.';
                        $formStatus = 409;
                    } else {
                        throw $exception;
                    }
                }
            }
        }
    }
} catch (Throwable $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }

    error_log('Add product request failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The product could not be saved. Please try again later.');
}

if ($errors !== []) {
    http_response_code($formStatus);
}

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fieldClass = static fn (string $field): string => isset($errors[$field]) ? ' is-invalid' : '';

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-heading add-product-page-heading">
    <div>
        <span class="eyebrow">PRODUCT CATALOG</span>
        <h2>Add a product</h2>
        <p>Enter product details and set its starting stock level.</p>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success product-form-message" role="status">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
        Product added successfully.
        <a href="<?= $escape($baseUrl) ?>/pages/products/index.php">View products</a>
    </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger product-form-message" role="alert">
        <strong>Product not saved.</strong>
        <?php if (isset($errors['_csrf'])): ?>
            <?= $escape($errors['_csrf']) ?>
        <?php else: ?>
            Review the highlighted fields and try again.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($categories === []): ?>
    <div class="alert alert-warning product-form-message" role="status">
        <strong>No categories are available.</strong> Add a category before saving a product.
    </div>
<?php endif; ?>

<form class="panel-card product-form-card" method="post" action="<?= $escape($baseUrl) ?>/pages/products/add.php">
    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
    <div class="product-form-section-heading">
        <div>
            <h3>Product details</h3>
            <p>Fields marked <span class="required-mark">*</span> are required.</p>
        </div>
    </div>

    <div class="product-form-grid">
        <div class="product-form-field">
            <label for="productName">Product name <span class="required-mark">*</span></label>
            <input
                class="form-control<?= $fieldClass('product_name') ?>"
                type="text"
                id="productName"
                name="product_name"
                maxlength="150"
                value="<?= $escape($formValues['product_name']) ?>"
                autocomplete="off"
                required
                <?= isset($errors['product_name']) ? 'aria-invalid="true" aria-describedby="productNameError"' : '' ?>
            >
            <?php if (isset($errors['product_name'])): ?>
                <div class="invalid-feedback" id="productNameError"><?= $escape($errors['product_name']) ?></div>
            <?php endif; ?>
        </div>

        <div class="product-form-field">
            <label for="productSku">SKU / Barcode <span class="required-mark">*</span></label>
            <input
                class="form-control<?= $fieldClass('sku') ?>"
                type="text"
                id="productSku"
                name="sku"
                maxlength="64"
                value="<?= $escape($formValues['sku']) ?>"
                autocomplete="off"
                spellcheck="false"
                required
                <?= isset($errors['sku']) ? 'aria-invalid="true" aria-describedby="productSkuError"' : '' ?>
            >
            <?php if (isset($errors['sku'])): ?>
                <div class="invalid-feedback" id="productSkuError"><?= $escape($errors['sku']) ?></div>
            <?php endif; ?>
        </div>

        <div class="product-form-field">
            <label for="productCategory">Category <span class="required-mark">*</span></label>
            <select
                class="form-select<?= $fieldClass('category_id') ?>"
                id="productCategory"
                name="category_id"
                required
                <?= isset($errors['category_id']) ? 'aria-invalid="true" aria-describedby="productCategoryError"' : '' ?>
            >
                <?php if ($formValues['category_id'] !== '' && !isset($categoryIds[$formValues['category_id']])): ?>
                    <option value="<?= $escape($formValues['category_id']) ?>" selected>Unavailable category</option>
                <?php endif; ?>
                <?php if ($categories === []): ?>
                    <option value="" disabled <?= $formValues['category_id'] === '' ? 'selected' : '' ?>>No categories available</option>
                <?php else: ?>
                    <option value="" disabled <?= $formValues['category_id'] === '' ? 'selected' : '' ?>>Select a category</option>
                    <?php foreach ($categories as $category): ?>
                        <option
                            value="<?= $escape($category['category_id']) ?>"
                            <?= $formValues['category_id'] === (string) $category['category_id'] ? 'selected' : '' ?>
                        >
                            <?= $escape($category['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <?php if (isset($errors['category_id'])): ?>
                <div class="invalid-feedback" id="productCategoryError"><?= $escape($errors['category_id']) ?></div>
            <?php endif; ?>
        </div>

        <div class="product-form-field">
            <label for="productUnit">Unit <span class="required-mark">*</span></label>
            <select
                class="form-select<?= $fieldClass('unit') ?>"
                id="productUnit"
                name="unit"
                required
                <?= isset($errors['unit']) ? 'aria-invalid="true" aria-describedby="productUnitError"' : '' ?>
            >
                <?php if ($formValues['unit'] !== '' && !in_array($formValues['unit'], $allowedUnits, true)): ?>
                    <option value="<?= $escape($formValues['unit']) ?>" selected>Unsupported unit</option>
                <?php endif; ?>
                <?php foreach ($allowedUnits as $unit): ?>
                    <option value="<?= $escape($unit) ?>" <?= $formValues['unit'] === $unit ? 'selected' : '' ?>>
                        <?= $escape(ucfirst($unit)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['unit'])): ?>
                <div class="invalid-feedback" id="productUnitError"><?= $escape($errors['unit']) ?></div>
            <?php endif; ?>
        </div>

        <div class="product-form-field product-form-field-wide">
            <label for="productDescription">Description <span class="optional-label">Optional</span></label>
            <textarea
                class="form-control<?= $fieldClass('description') ?>"
                id="productDescription"
                name="description"
                rows="3"
                maxlength="5000"
                <?= isset($errors['description']) ? 'aria-invalid="true" aria-describedby="productDescriptionError"' : '' ?>
            ><?= $escape($formValues['description']) ?></textarea>
            <?php if (isset($errors['description'])): ?>
                <div class="invalid-feedback" id="productDescriptionError"><?= $escape($errors['description']) ?></div>
            <?php else: ?>
                <div class="field-help">Add helpful details such as size, brand, or storage notes.</div>
            <?php endif; ?>
        </div>

        <div class="product-form-field">
            <label for="initialQuantity">Initial stock quantity <span class="required-mark">*</span></label>
            <input
                class="form-control<?= $fieldClass('quantity') ?>"
                type="number"
                id="initialQuantity"
                name="quantity"
                min="0"
                max="4294967295"
                step="1"
                value="<?= $escape($formValues['quantity']) ?>"
                required
                <?= isset($errors['quantity']) ? 'aria-invalid="true" aria-describedby="initialQuantityError"' : '' ?>
            >
            <?php if (isset($errors['quantity'])): ?>
                <div class="invalid-feedback" id="initialQuantityError"><?= $escape($errors['quantity']) ?></div>
            <?php endif; ?>
        </div>

        <div class="product-form-field">
            <label for="reorderLevel">Reorder level <span class="required-mark">*</span></label>
            <input
                class="form-control<?= $fieldClass('reorder_level') ?>"
                type="number"
                id="reorderLevel"
                name="reorder_level"
                min="0"
                max="4294967295"
                step="1"
                value="<?= $escape($formValues['reorder_level']) ?>"
                required
                <?= isset($errors['reorder_level']) ? 'aria-invalid="true" aria-describedby="reorderLevelError"' : '' ?>
            >
            <?php if (isset($errors['reorder_level'])): ?>
                <div class="invalid-feedback" id="reorderLevelError"><?= $escape($errors['reorder_level']) ?></div>
            <?php endif; ?>
        </div>

        <div class="product-form-field">
            <label for="costPrice">Cost price <span class="required-mark">*</span></label>
            <input
                class="form-control<?= $fieldClass('cost_price') ?>"
                type="number"
                id="costPrice"
                name="cost_price"
                min="0"
                max="99999999.99"
                step="0.01"
                placeholder="0.00"
                value="<?= $escape($formValues['cost_price']) ?>"
                required
                <?= isset($errors['cost_price']) ? 'aria-invalid="true" aria-describedby="costPriceError"' : '' ?>
            >
            <?php if (isset($errors['cost_price'])): ?>
                <div class="invalid-feedback" id="costPriceError"><?= $escape($errors['cost_price']) ?></div>
            <?php endif; ?>
        </div>

        <div class="product-form-field">
            <label for="sellingPrice">Selling price <span class="required-mark">*</span></label>
            <input
                class="form-control<?= $fieldClass('selling_price') ?>"
                type="number"
                id="sellingPrice"
                name="selling_price"
                min="0"
                max="99999999.99"
                step="0.01"
                placeholder="0.00"
                value="<?= $escape($formValues['selling_price']) ?>"
                required
                <?= isset($errors['selling_price']) ? 'aria-invalid="true" aria-describedby="sellingPriceError"' : '' ?>
            >
            <?php if (isset($errors['selling_price'])): ?>
                <div class="invalid-feedback" id="sellingPriceError"><?= $escape($errors['selling_price']) ?></div>
            <?php endif; ?>
        </div>

        <div class="product-form-field">
            <label for="expirationDate">Expiration date <span class="optional-label">Optional</span></label>
            <input
                class="form-control<?= $fieldClass('expiration_date') ?>"
                type="date"
                id="expirationDate"
                name="expiration_date"
                value="<?= $escape($formValues['expiration_date']) ?>"
                <?= isset($errors['expiration_date']) ? 'aria-invalid="true" aria-describedby="expirationDateError"' : '' ?>
            >
            <?php if (isset($errors['expiration_date'])): ?>
                <div class="invalid-feedback d-block" id="expirationDateError"><?= $escape($errors['expiration_date']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="product-form-actions">
        <a class="btn btn-outline-primary" href="<?= $escape($baseUrl) ?>/pages/products/index.php">Cancel</a>
        <button class="btn btn-primary save-product-button" type="submit">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            Save Product
        </button>
    </div>
</form>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
