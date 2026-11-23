# Grocery Inventory Pro
## Grocery Stock Management System

## 1. Project Overview

**Grocery Inventory Pro** is a web-based Grocery Inventory and Stock Management System developed to help manage grocery products, monitor stock quantities, record inventory movements, and identify products that need replenishment.

The system provides a centralized platform where users can organize products by category, monitor stock levels, record stock-in and stock-out transactions, view transaction history, and generate inventory reports.

The main purpose of the system is to make inventory monitoring easier, more organized, accurate, and efficient compared with manually tracking stock using notebooks or spreadsheets.

The application is designed with a simple and user-friendly interface so users can navigate the different inventory management functions easily.

---

## 2. Project Objectives

The main objective of Grocery Inventory Pro is to develop a functional inventory management system that helps organize and monitor grocery store inventory.

### Specific Objectives

- To manage grocery products and their information.
- To organize products according to categories.
- To monitor available product quantities.
- To record stock-in transactions when new products arrive.
- To record stock-out transactions when products are sold, released, or removed from inventory.
- To maintain a history of stock movements.
- To automatically identify products with low stock or no available stock.
- To provide inventory summaries and reports.
- To reduce manual errors in inventory monitoring.
- To make inventory management faster and more convenient.

---

## 3. Problem the System Solves

Manual inventory management can create difficulties for grocery store owners or inventory personnel.

Some common problems include:

- Difficulty tracking the current quantity of products.
- Forgetting to record incoming or outgoing stocks.
- Incorrect inventory calculations.
- Difficulty identifying products that are nearly depleted.
- Lack of organized transaction records.
- Time-consuming inventory checking.
- Difficulty preparing inventory summaries and reports.

Grocery Inventory Pro addresses these problems by providing a digital platform for recording, organizing, and monitoring inventory information.

---

## 4. Target Users

The system is intended for people who need to monitor and manage grocery inventory.

### Possible Users

- Grocery store owners
- Store managers
- Inventory personnel
- Stock custodians
- Small business operators

The system is particularly suitable for small grocery stores or businesses that need a straightforward inventory management solution.

---

## 5. Technologies Used

### PHP

PHP is the server-side programming language used to process application requests, perform database operations, validate submitted information, and manage the system's functionality.

When a user submits a form, PHP processes the information and communicates with the database.

### MySQL

MySQL is the database management system used to store the application's inventory information, including product details, categories, and stock transaction records.

### MySQLi

MySQLi is used by PHP to communicate with the MySQL database. It allows the application to execute database queries and retrieve or update information.

### HTML5

HTML is used to structure the content of the web pages, including forms, tables, buttons, navigation elements, and dashboard sections.

### CSS3

CSS is used to style the application, including colors, spacing, layouts, typography, and visual elements.

### Bootstrap 5

Bootstrap is used to help create a responsive and organized user interface. It helps the application adapt to different screen sizes.

### JavaScript

JavaScript is used for client-side interactions and improves the user experience by making certain interface elements interactive.

### XAMPP

XAMPP is used during local development. It provides the local web server environment and database services needed to run and test the PHP and MySQL application.

---

## 6. System Features

### 6.1 Dashboard

The Dashboard serves as the main overview page of the system.

It displays summarized inventory information to help users understand the current condition of their inventory without having to open each module individually.

It includes:

- Total products
- Products currently in stock
- Low-stock products
- Out-of-stock products
- Low-stock alerts
- Inventory summary

**Purpose:** To provide users with a quick overview of their inventory condition.

### 6.2 Product Management

The Product Management module allows users to manage the grocery products stored in the system.

Users can:

- Add new products.
- View existing products.
- Edit product information.
- Delete products.
- Search for products.
- Filter products by category.
- Monitor product quantities.
- Review product prices and expiration information where available.

Product records contain information used to identify and monitor each item in the inventory.

**Purpose:** To maintain an organized and updated product catalog.

### 6.3 Category Management

The Category Management module organizes products into groups based on their type.

Examples of grocery categories include:

- Beverages
- Snacks
- Canned Goods
- Dairy Products
- Personal Care
- Household Items

Categories make it easier to organize products and locate specific items.

**Purpose:** To improve product organization and simplify inventory browsing.

### 6.4 Stock In

Stock In is used when new inventory is received and added to the store.

For example, if the current quantity of milk is 10 pieces and the store receives 5 additional pieces, the system updates the quantity to 15.

**Stock-in calculation:**

New Stock = Previous Stock + Received Quantity

Example:

Previous stock = 10

Received stock = 5

New stock = 10 + 5 = 15

**Purpose:** To record incoming inventory and maintain an updated stock quantity.

### 6.5 Stock Out

Stock Out is used when products are removed from available inventory, such as when items are sold, released, or otherwise deducted.

For example, if the inventory contains 15 pieces of milk and 3 pieces are removed, the system updates the quantity to 12.

**Stock-out calculation:**

Remaining Stock = Previous Stock − Released Quantity

Example:

Previous stock = 15

Stock out = 3

Remaining stock = 15 − 3 = 12

**Purpose:** To accurately track inventory reductions.

### 6.6 Transactions

The Transactions module records inventory movements performed within the system.

It provides a history of stock-in and stock-out activities.

Transaction records help users review:

- Product involved
- Transaction type
- Quantity moved
- Transaction date
- Related transaction details

**Purpose:** To maintain a record of inventory activity and help users monitor changes in stock.

### 6.7 Inventory Alerts

The Inventory Alerts module helps identify products that need attention.

It monitors product quantities and compares them with the product's reorder level.

**Low Stock:** A product's quantity is at or below its reorder level, depending on the system's configured logic.

**Out of Stock:** The product quantity has reached zero.

These alerts help the store identify which products may need replenishment.

**Purpose:** To prevent products from running out unexpectedly and support timely restocking decisions.

### 6.8 Reports and Analytics

The Reports module provides summarized information about inventory and stock movements.

It helps users understand inventory activity and review the available stock.

Reports and analytics can support:

- Inventory monitoring
- Stock movement review
- Product quantity analysis
- Identifying products that require replenishment
- Inventory decision-making

**Purpose:** To help users make better inventory-related decisions based on recorded information.

---

## 7. Database Overview

The system uses MySQL as its database to store and organize inventory information.

The database serves as the central storage location for the system's records.

The information stored includes:

- Product information
- Category information
- Inventory quantities
- Reorder levels
- Stock movement records
- Related inventory details

The database allows the system to retrieve, insert, update, and delete records as users interact with the application.

### Why a database is important

Without a database, the system would have difficulty maintaining consistent inventory records.

Using a database allows information to be stored persistently, meaning data remains available even after the browser is closed or the application is restarted.

It also helps maintain organized records and allows different system modules to access related information.

---

## 8. How the System Works

The system follows a basic web application process:

1. The user opens the Grocery Inventory Pro website.
2. The user selects a module from the navigation menu.
3. The user performs an action, such as adding a product or recording stock in.
4. The PHP application processes the submitted information.
5. PHP communicates with the MySQL database through MySQLi.
6. The database stores or retrieves the necessary information.
7. The system displays the updated information to the user.
8. Dashboard statistics, inventory summaries, and alerts reflect the recorded inventory data.

### Basic system flow

User → Web Interface → PHP Processing → MySQL Database → Updated Results → User

---

## 9. Inventory Computation

The system monitors stock by updating product quantities based on inventory movements.

### Stock In

Stock In increases inventory.

Formula:

New Quantity = Current Quantity + Stock Received

### Stock Out

Stock Out decreases inventory.

Formula:

New Quantity = Current Quantity − Stock Released

### Low Stock

The system checks the product's available quantity against its reorder level.

A product is considered low stock when it reaches or falls below its configured reorder threshold, according to the system's rules.

### Out of Stock

A product is out of stock when its available quantity is zero.

These calculations help maintain accurate inventory information.

---

## 10. User Interface and Design

Grocery Inventory Pro uses a modern inventory dashboard design.

The interface includes:

- Green-themed navigation sidebar
- Dashboard cards
- Product tables
- Search and filtering controls
- Action buttons
- Status indicators
- Stock alerts
- Organized navigation
- Responsive layout

The green color theme is associated with grocery products, freshness, and inventory management.

The layout is designed to make important information easy to identify and help users navigate between system modules.

---

## 11. Security and Data Handling

The system incorporates programming practices intended to improve security and data reliability, including:

- Prepared statements for database queries
- Input validation
- Output escaping
- CSRF protection where implemented
- Structured database connections
- Error handling

Prepared statements help protect database queries against SQL injection when used correctly.

Input validation helps ensure submitted information meets expected requirements.

Output escaping helps reduce the risk of malicious content being interpreted as executable HTML.

CSRF protection helps prevent unauthorized form submissions from certain types of cross-site attacks.

---

## 12. Deployment

The system was initially developed and tested locally using XAMPP.

After local development and testing, the system was deployed to InfinityFree hosting so it could be accessed through the internet.

### Local Environment

- XAMPP
- PHP
- MySQL
- Local project directory

### Online Environment

- InfinityFree web hosting
- Online MySQL database
- Uploaded PHP project files
- Public website URL

### Deployment Process

1. Prepare and test the application locally.
2. Create an online hosting account.
3. Create an online MySQL database.
4. Export the local database.
5. Import the SQL file into the online database.
6. Upload the project files to the hosting directory.
7. Configure the online database connection.
8. Open the live website.
9. Test the online application and its functionality.

---

## 13. Testing

The application was tested locally and after deployment to verify that its main functions were working.

### Online Testing

The following modules were checked on the hosted website:

- Dashboard loading
- Database connection
- Product listing
- Product creation
- Stock In
- Stock Out
- Transaction records

Testing helps verify that the application works correctly in the online environment and that database changes are reflected in the interface.

---

## 14. Limitations

The system is designed primarily for grocery inventory management and may not include every feature of a large commercial inventory platform.

Possible limitations include:

- It is intended for relatively simple inventory operations.
- It may not provide advanced accounting or point-of-sale functions.
- It may not include barcode scanning.
- It may not include supplier purchase order management.
- It may not include advanced multi-branch inventory synchronization.
- The accuracy of the inventory depends on users recording stock movements correctly.

These limitations can serve as opportunities for future improvement.

---

## 15. Future Enhancements

Possible future improvements include:

- User login and role-based access control
- Barcode scanning
- Supplier management
- Purchase order management
- Sales integration
- Automated inventory notifications
- More advanced analytics
- Exporting reports to PDF or Excel
- Multi-store inventory management
- Improved security and access controls

These enhancements could make the system more suitable for larger businesses or more complex inventory operations.

---

## 16. Conclusion

Grocery Inventory Pro is a web-based Grocery Inventory and Stock Management System that provides a digital solution for managing grocery products and inventory movements.

It combines PHP, MySQL, HTML, CSS, Bootstrap, and JavaScript to create an organized and user-friendly application.

Through its Dashboard, Product Management, Category Management, Stock In, Stock Out, Transactions, Inventory Alerts, and Reports modules, the system helps users monitor their inventory, record stock movements, and identify products that require replenishment.

The deployment of the system to an online hosting platform also allows the application to be accessed through a live website, demonstrating how a locally developed web application can be deployed and connected to an online database.

Overall, the project demonstrates the practical application of web development, database management, system design, inventory tracking, and deployment.

---

# PRESENTATION STUDY NOTES

## How to explain the project in simple words

"Grocery Inventory Pro is a web-based system that helps grocery stores manage their products and monitor their stocks. Instead of manually tracking products, users can add products, organize them into categories, record incoming and outgoing stocks, view transaction history, and monitor low-stock products. The system stores its information in a MySQL database and uses PHP to process the data. We also deployed the project online so it can be accessed through a live website."

## What is the purpose of your project?

"The purpose of our project is to make grocery inventory management easier, faster, and more organized. It helps users monitor product quantities, record stock movements, and identify products that need to be replenished."

## Why did you use PHP?

"We used PHP because it is a server-side programming language that can process forms, perform database operations, and handle the functionality of our web application."

## Why did you use MySQL?

"We used MySQL to store our product information, categories, quantities, and transaction records. It allows our data to remain stored and organized even when the application is closed."

## What is the difference between Stock In and Stock Out?

"Stock In means adding new products or quantities to the inventory, while Stock Out means subtracting quantities when products are removed from available stock."

## What is the purpose of Inventory Alerts?

"Inventory Alerts help us identify products that are running low or are already out of stock so we can replenish them on time."

## What is the purpose of Transactions?

"Transactions provide a history of stock movements. They help us track when inventory was added or removed and how much quantity was involved."

## What is the purpose of the Dashboard?

"The Dashboard provides a summary of the inventory, including total products, products in stock, low-stock products, and out-of-stock products. It helps users quickly understand the current inventory condition."

## Why is your system web-based?

"We made it web-based so users can access the system through a browser without needing to install a separate desktop application on every device. We also deployed it online to demonstrate its accessibility."

## What did you learn from this project?

"Through this project, I learned how PHP connects to a database, how CRUD operations work, how stock quantities are updated, how to organize system modules, how to test application functions, and how to deploy a web application online. I also learned the importance of troubleshooting and testing because a system must work not only locally but also after deployment."

## What is the biggest challenge?

"One of the challenges was deploying the application online and configuring the database connection correctly. The local database settings were different from the online hosting credentials and configuration, so we had to troubleshoot the connection until the live system worked properly."

## What makes your project useful?

"Our project is useful because it helps organize inventory records, makes stock monitoring easier, reduces the need for manual tracking, and helps users identify products that need restocking."

---

## Project Links

**Live Website:** https://groceryinventorypro.great-site.net

**GitHub Repository:** [Insert your GitHub repository link here]
