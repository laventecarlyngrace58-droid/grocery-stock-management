<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/csrf.php';

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/pages/categories/index.php'));
$baseUrl = dirname(dirname($scriptDirectory));
$baseUrl = $baseUrl === '/' || $baseUrl === '.' ? '' : rtrim($baseUrl, '/');

$pageTitle = 'Categories';
$pageSubtitle = 'Organize your products into clear, easy-to-manage groups.';
$activeNav = 'categories';
$csrfToken = getCsrfToken();
$formValues = [
    'category_id' => '',
    'category_name' => '',
    'description' => '',
];
$errors = [];
$pageError = '';
$categories = [];
$editingCategoryId = null;
$formStatus = 200;
$search = '';
$searchIsValid = true;
$successMessages = [
    'created' => 'Category added successfully.',
    'updated' => 'Category updated successfully.',
    'deleted' => 'Category deleted successfully.',
];

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$textLength = static fn (string $value): int => function_exists('mb_strlen')
    ? mb_strlen($value, 'UTF-8')
    : strlen($value);
$normalizeId = static function ($value): ?string {
    if (!is_string($value) || !preg_match('/^\d+$/D', $value)) {
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
    $pageError = 'Enter one search term.';
    $searchIsValid = false;
    http_response_code(400);
} else {
    $search = trim($rawSearch);
    if ($textLength($search) > 255) {
        $pageError = 'Search text must be 255 characters or fewer.';
        $searchIsValid = false;
        http_response_code(400);
    }
}

$redirectWithStatus = static function (string $status) use ($baseUrl, $search): never {
    $query = ['status' => $status];
    if ($search !== '') {
        $query['search'] = $search;
    }

    header(
        'Location: ' . $baseUrl . '/pages/categories/index.php?' . http_build_query($query),
        true,
        303
    );
    exit;
};

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $searchIsValid) {
        $rawAction = $_POST['action'] ?? '';
        if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
            $pageError = 'The form expired or is invalid. Refresh the page and try again.';
            $formStatus = 403;
        } elseif (!is_string($rawAction) || !in_array($rawAction, ['save', 'delete'], true)) {
            $pageError = 'Choose a valid category action.';
            $formStatus = 400;
        } elseif ($rawAction === 'delete') {
            $categoryId = $normalizeId($_POST['category_id'] ?? null);
            if ($categoryId === null) {
                $pageError = 'Choose a valid category to delete.';
                $formStatus = 400;
            } else {
                $transactionStarted = false;
                try {
                    $conn->begin_transaction();
                    $transactionStarted = true;

                    $lockStatement = $conn->prepare(
                        'SELECT category_id
                        FROM categories
                        WHERE category_id = ?
                        FOR UPDATE'
                    );
                    $lockStatement->bind_param('s', $categoryId);
                    $lockStatement->execute();
                    $categoryExists = $lockStatement->get_result()->fetch_assoc() !== null;
                    $lockStatement->close();

                    if (!$categoryExists) {
                        $conn->rollback();
                        $transactionStarted = false;
                        $pageError = 'That category no longer exists. Refresh the list and try again.';
                        $formStatus = 404;
                    } else {
                        $productCountStatement = $conn->prepare(
                            'SELECT COUNT(*) AS product_count
                            FROM products
                            WHERE category_id = ?'
                        );
                        $productCountStatement->bind_param('s', $categoryId);
                        $productCountStatement->execute();
                        $productCount = (int) $productCountStatement->get_result()->fetch_assoc()['product_count'];
                        $productCountStatement->close();

                        if ($productCount > 0) {
                            $conn->rollback();
                            $transactionStarted = false;
                            $pageError = sprintf(
                                'This category contains %d product%s and cannot be deleted. Reassign or remove those products first.',
                                $productCount,
                                $productCount === 1 ? '' : 's'
                            );
                            $formStatus = 409;
                        } else {
                            $deleteStatement = $conn->prepare(
                                'DELETE FROM categories
                                WHERE category_id = ?'
                            );
                            $deleteStatement->bind_param('s', $categoryId);
                            $deleteStatement->execute();
                            $deleteStatement->close();

                            $conn->commit();
                            $transactionStarted = false;
                            $redirectWithStatus('deleted');
                        }
                    }
                } catch (mysqli_sql_exception $exception) {
                    if ($transactionStarted) {
                        $conn->rollback();
                        $transactionStarted = false;
                    }

                    if ($exception->getCode() === 1451) {
                        $pageError = 'This category is still in use by products and cannot be deleted. Reassign or remove those products first.';
                        $formStatus = 409;
                    } else {
                        throw $exception;
                    }
                }
            }
        } else {
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

            if ($formValues['category_id'] !== '') {
                $editingCategoryId = $normalizeId($formValues['category_id']);
                if ($editingCategoryId === null) {
                    $errors['category_id'] = 'Choose a valid category to edit.';
                    $formStatus = 400;
                } else {
                    $formValues['category_id'] = $editingCategoryId;
                }
            }

            if ($formValues['category_name'] === '') {
                $errors['category_name'] = 'Category name is required.';
            } elseif ($textLength($formValues['category_name']) > 100) {
                $errors['category_name'] = 'Category name must be 100 characters or fewer.';
            }

            if ($textLength($formValues['description']) > 255) {
                $errors['description'] = 'Description must be 255 characters or fewer.';
            }

            if ($errors === []) {
                $transactionStarted = false;
                try {
                    $conn->begin_transaction();
                    $transactionStarted = true;

                    if ($editingCategoryId !== null) {
                        $lockStatement = $conn->prepare(
                            'SELECT category_id
                            FROM categories
                            WHERE category_id = ?
                            FOR UPDATE'
                        );
                        $lockStatement->bind_param('s', $editingCategoryId);
                        $lockStatement->execute();
                        $categoryExists = $lockStatement->get_result()->fetch_assoc() !== null;
                        $lockStatement->close();

                        if (!$categoryExists) {
                            $conn->rollback();
                            $transactionStarted = false;
                            $pageError = 'That category no longer exists. Refresh the list and try again.';
                            $formStatus = 404;
                        }
                    }

                    if ($pageError === '') {
                        $excludedCategoryId = $editingCategoryId ?? '0';
                        $duplicateStatement = $conn->prepare(
                            'SELECT category_id
                            FROM categories
                            WHERE category_name = ?
                                AND category_id <> ?
                            LIMIT 1'
                        );
                        $duplicateStatement->bind_param(
                            'ss',
                            $formValues['category_name'],
                            $excludedCategoryId
                        );
                        $duplicateStatement->execute();
                        $duplicateName = $duplicateStatement->get_result()->fetch_assoc() !== null;
                        $duplicateStatement->close();

                        if ($duplicateName) {
                            $conn->rollback();
                            $transactionStarted = false;
                            $errors['category_name'] = 'A category with this name already exists.';
                            $formStatus = 409;
                        } else {
                            $descriptionValue = $formValues['description'] === ''
                                ? null
                                : $formValues['description'];

                            if ($editingCategoryId === null) {
                                $saveStatement = $conn->prepare(
                                    'INSERT INTO categories (category_name, description)
                                    VALUES (?, ?)'
                                );
                                $saveStatement->bind_param(
                                    'ss',
                                    $formValues['category_name'],
                                    $descriptionValue
                                );
                                $saveStatement->execute();
                                $saveStatement->close();
                                $successStatus = 'created';
                            } else {
                                $saveStatement = $conn->prepare(
                                    'UPDATE categories
                                    SET category_name = ?, description = ?
                                    WHERE category_id = ?'
                                );
                                $saveStatement->bind_param(
                                    'sss',
                                    $formValues['category_name'],
                                    $descriptionValue,
                                    $editingCategoryId
                                );
                                $saveStatement->execute();
                                $saveStatement->close();
                                $successStatus = 'updated';
                            }

                            $conn->commit();
                            $transactionStarted = false;
                            $redirectWithStatus($successStatus);
                        }
                    }
                } catch (mysqli_sql_exception $exception) {
                    if ($transactionStarted) {
                        $conn->rollback();
                        $transactionStarted = false;
                    }

                    if ($exception->getCode() === 1062) {
                        $errors['category_name'] = 'A category with this name already exists.';
                        $formStatus = 409;
                    } else {
                        throw $exception;
                    }
                }
            } elseif ($formStatus === 200) {
                $formStatus = 422;
            }
        }
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['edit'])) {
        $editingCategoryId = $normalizeId($_GET['edit']);
        if ($editingCategoryId === null) {
            $pageError = 'Choose a valid category to edit.';
            $formStatus = 400;
        } else {
            $editStatement = $conn->prepare(
                'SELECT category_id, category_name, description
                FROM categories
                WHERE category_id = ?
                LIMIT 1'
            );
            $editStatement->bind_param('s', $editingCategoryId);
            $editStatement->execute();
            $editCategory = $editStatement->get_result()->fetch_assoc();
            $editStatement->close();

            if ($editCategory === null) {
                $pageError = 'That category could not be found.';
                $formStatus = 404;
                $editingCategoryId = null;
            } else {
                $formValues['category_id'] = (string) $editCategory['category_id'];
                $formValues['category_name'] = (string) $editCategory['category_name'];
                $formValues['description'] = (string) ($editCategory['description'] ?? '');
            }
        }
    }

    $searchPattern = '%' . $search . '%';
    $categoriesStatement = $conn->prepare(
        'SELECT
            c.category_id,
            c.category_name,
            c.description,
            c.created_at,
            COUNT(p.product_id) AS product_count
        FROM categories AS c
        LEFT JOIN products AS p ON p.category_id = c.category_id
        WHERE (? = \'\' OR c.category_name LIKE ? OR c.description LIKE ?)
        GROUP BY c.category_id, c.category_name, c.description, c.created_at
        ORDER BY c.category_name ASC'
    );
    $categoriesStatement->bind_param('sss', $search, $searchPattern, $searchPattern);
    $categoriesStatement->execute();
    $categories = $categoriesStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $categoriesStatement->close();
} catch (Throwable $exception) {
    if (isset($transactionStarted) && $transactionStarted) {
        $conn->rollback();
    }

    error_log('Category management request failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Categories could not be processed. Please try again later.');
}

if ($formStatus !== 200) {
    http_response_code($formStatus);
}

$successStatus = $_GET['status'] ?? '';
$successMessage = is_string($successStatus) ? ($successMessages[$successStatus] ?? '') : '';
$hasSearch = $search !== '';
$categoryPageUrl = $baseUrl . '/pages/categories/index.php';

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-heading categories-page-heading">
    <div>
        <span class="eyebrow">INVENTORY ORGANIZATION</span>
        <h2>Manage categories</h2>
        <p>Create and maintain the groups used to organize your products.</p>
    </div>
    <span class="category-count">
        <i class="bi bi-collection" aria-hidden="true"></i>
        <?= count($categories) ?> <?= count($categories) === 1 ? 'category' : 'categories' ?>
    </span>
</div>

<?php if ($successMessage !== ''): ?>
    <div class="alert alert-success category-page-message" role="status">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
        <?= $escape($successMessage) ?>
    </div>
<?php endif; ?>

<?php if ($pageError !== ''): ?>
    <div class="alert alert-danger category-page-message" role="alert">
        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
        <?= $escape($pageError) ?>
    </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger category-page-message" role="alert">
        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
        <strong>Category not saved.</strong> Review the highlighted fields and try again.
    </div>
<?php endif; ?>

<div class="categories-layout">
    <section class="panel-card category-form-card" aria-labelledby="categoryFormTitle">
        <div class="product-form-section-heading">
            <div class="category-form-heading">
                <span class="category-form-icon" aria-hidden="true">
                    <i class="bi <?= $editingCategoryId === null ? 'bi-folder-plus' : 'bi-pencil-square' ?>"></i>
                </span>
                <div>
                    <h3 id="categoryFormTitle"><?= $editingCategoryId === null ? 'Add a category' : 'Edit category' ?></h3>
                    <p><?= $editingCategoryId === null ? 'Create a group for similar products.' : 'Update this category’s details.' ?></p>
                </div>
            </div>
        </div>

        <form method="post" action="<?= $escape($categoryPageUrl . ($hasSearch ? '?search=' . rawurlencode($search) : '')) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="category_id" value="<?= $escape($editingCategoryId ?? '') ?>">

            <div class="product-form-field category-field">
                <label for="categoryName">Category name <span class="required-mark">*</span></label>
                <input
                    class="form-control<?= isset($errors['category_name']) ? ' is-invalid' : '' ?>"
                    type="text"
                    id="categoryName"
                    name="category_name"
                    maxlength="100"
                    value="<?= $escape($formValues['category_name']) ?>"
                    autocomplete="off"
                    required
                    <?= isset($errors['category_name']) ? 'aria-invalid="true" aria-describedby="categoryNameError"' : '' ?>
                >
                <?php if (isset($errors['category_name'])): ?>
                    <div class="invalid-feedback" id="categoryNameError"><?= $escape($errors['category_name']) ?></div>
                <?php endif; ?>
            </div>

            <div class="product-form-field category-field">
                <label for="categoryDescription">Description <span class="optional-label">Optional</span></label>
                <textarea
                    class="form-control<?= isset($errors['description']) ? ' is-invalid' : '' ?>"
                    id="categoryDescription"
                    name="description"
                    rows="4"
                    maxlength="255"
                    <?= isset($errors['description']) ? 'aria-invalid="true" aria-describedby="categoryDescriptionError"' : '' ?>
                ><?= $escape($formValues['description']) ?></textarea>
                <?php if (isset($errors['description'])): ?>
                    <div class="invalid-feedback" id="categoryDescriptionError"><?= $escape($errors['description']) ?></div>
                <?php else: ?>
                    <div class="field-help">Add a short note describing the products in this group.</div>
                <?php endif; ?>
            </div>

            <div class="category-form-actions">
                <?php if ($editingCategoryId !== null): ?>
                    <a class="btn btn-outline-primary" href="<?= $escape($categoryPageUrl . ($hasSearch ? '?search=' . rawurlencode($search) : '')) ?>">
                        Cancel
                    </a>
                <?php endif; ?>
                <button class="btn btn-primary category-save-button" type="submit">
                    <i class="bi <?= $editingCategoryId === null ? 'bi-plus-lg' : 'bi-check-lg' ?>" aria-hidden="true"></i>
                    <?= $editingCategoryId === null ? 'Add Category' : 'Save Changes' ?>
                </button>
            </div>
        </form>
    </section>

    <section class="panel-card category-list-panel" aria-labelledby="categoryListTitle">
        <div class="category-list-header">
            <div>
                <h3 id="categoryListTitle">All categories</h3>
                <p>Review each category and the products assigned to it.</p>
            </div>
            <span class="product-count"><?= count($categories) ?> found</span>
        </div>

        <form class="category-search-form" method="get" action="<?= $escape($categoryPageUrl) ?>">
            <div class="product-filter-field">
                <label for="categorySearch">Search categories</label>
                <div class="product-search-control">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input
                        class="form-control"
                        type="search"
                        id="categorySearch"
                        name="search"
                        maxlength="255"
                        value="<?= $escape($search) ?>"
                        placeholder="Search name or description"
                    >
                </div>
            </div>
            <button class="btn btn-primary category-search-button" type="submit">
                <i class="bi bi-search" aria-hidden="true"></i>
                Search
            </button>
            <?php if ($hasSearch): ?>
                <a class="btn btn-outline-primary category-clear-button" href="<?= $escape($categoryPageUrl) ?>">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($categories === []): ?>
            <div class="empty-state category-empty-state">
                <span class="empty-state-icon <?= $hasSearch ? '' : 'empty-state-success' ?>">
                    <i class="bi <?= $hasSearch ? 'bi-search' : 'bi-collection' ?>" aria-hidden="true"></i>
                </span>
                <?php if ($hasSearch): ?>
                    <p>No categories match this search</p>
                    <span>Try a different name or description, or clear the search.</span>
                <?php else: ?>
                    <p>No categories yet</p>
                    <span>Add your first category using the form. Categories help keep products organized.</span>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive category-table-wrap">
                <table class="table table-hover inventory-table category-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">CATEGORY</th>
                            <th scope="col">DESCRIPTION</th>
                            <th scope="col">PRODUCTS</th>
                            <th scope="col">CREATED</th>
                            <th scope="col">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $category): ?>
                            <?php
                            $editQuery = ['edit' => (string) $category['category_id']];
                            if ($hasSearch) {
                                $editQuery['search'] = $search;
                            }
                            $editUrl = $categoryPageUrl . '?' . http_build_query($editQuery);
                            $productCount = (int) $category['product_count'];
                            ?>
                            <tr>
                                <td class="category-name-cell">
                                    <span class="category-name">
                                        <i class="bi bi-folder2-open" aria-hidden="true"></i>
                                        <?= $escape($category['category_name']) ?>
                                    </span>
                                </td>
                                <td class="category-description-cell">
                                    <?php if ($category['description'] === null || $category['description'] === ''): ?>
                                        <span class="not-set">No description</span>
                                    <?php else: ?>
                                        <?= $escape($category['description']) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="category-products-badge">
                                        <i class="bi bi-box-seam" aria-hidden="true"></i>
                                        <?= $productCount ?> <?= $productCount === 1 ? 'product' : 'products' ?>
                                    </span>
                                </td>
                                <td class="category-date-cell"><?= $escape(date('M j, Y', strtotime((string) $category['created_at']))) ?></td>
                                <td>
                                    <div class="category-actions">
                                        <a
                                            class="btn btn-icon-action edit-action"
                                            href="<?= $escape($editUrl) ?>"
                                            aria-label="Edit <?= $escape($category['category_name']) ?>"
                                            title="Edit category"
                                        >
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                        </a>
                                        <form
                                            method="post"
                                            action="<?= $escape($categoryPageUrl . ($hasSearch ? '?search=' . rawurlencode($search) : '')) ?>"
                                            onsubmit="return confirm('Delete this category? This action cannot be undone.');"
                                        >
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="category_id" value="<?= $escape($category['category_id']) ?>">
                                            <button
                                                class="btn btn-icon-action delete-action"
                                                type="submit"
                                                aria-label="Delete <?= $escape($category['category_name']) ?>"
                                                title="Delete category"
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
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
