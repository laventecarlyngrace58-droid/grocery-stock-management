<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(
        'sql108.infinityfree.com',
        'if0_43514855',
        'isufst2130',
        'if0_43514855_grocery_stock'
    );

    $conn->set_charset('utf8mb4');

} catch (mysqli_sql_exception $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection failed. Please check your hosting configuration.');
}