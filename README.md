# Grocery Stock Management System

Native PHP, MySQL, HTML, CSS, Bootstrap 5, and JavaScript project for local development with XAMPP.

## Project structure

- `assets/css/` — application stylesheets.
- `assets/js/` — browser-side JavaScript.
- `config/` — database and application configuration.
- `database/` — SQL setup scripts.
- `includes/` — reusable page layout and shared PHP code.
- `pages/categories/` — category management pages.
- `pages/products/` — product management pages.
- `pages/reports/` — inventory reports.
- `pages/stock/` — stock movement and transaction pages.

## Stage 1: create the database

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open `http://localhost/phpmyadmin`.
3. Choose **Import**, select `database/grocery_stock_db.sql`, and run the import.
4. Confirm that `grocery_stock_db` appears with the `categories`, `products`, and `stock_transactions` tables.

The schema creates the database if it does not exist. Category names and product SKUs must be unique; stock quantities are unsigned; transaction quantities must be positive; and foreign keys prevent removing categories or products that have dependent records. The PHP application will enforce the stock balance and write a transaction whenever stock changes.

## Run the application

1. Keep **Apache** and **MySQL** running in XAMPP.
2. Open `http://localhost/grocery_stock_management/`.
3. Use the sidebar to access products, categories, stock movements, inventory alerts, and reports.

The shared MySQLi connection is in `config/db_connect.php`. Connection errors are written to the PHP error log; the browser receives a generic message instead of database details.
