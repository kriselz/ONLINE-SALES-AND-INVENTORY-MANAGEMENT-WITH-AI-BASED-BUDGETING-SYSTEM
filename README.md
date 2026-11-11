# ONLINE-SALES-AND-INVENTORY-MANAGEMENT-WITH-AI-BASED-BUDGETING-SYSTEM

The Online Sales and Inventory Management with AI-Based Budgeting System is designed to automate pharmacy sales transactions, online medicine ordering, and inventory management. It is intended for customers, pharmacists, administrators, and pharmacy owners to improve operational efficiency, accuracy, and product availability. The system also uses AI to analyze sales, inventory, and financial data to provide budgeting recommendations, support purchasing decisions, reduce operational costs, and improve business management.

---

## Group Members & Roles
* **Imperial, Rhea** – Documentation
* **Gliocam, Jomar B.** –Documentation
* **Sabdao, John Rey V.** – Backend / Database Administrator
* **Sumunod, Criselle B.** – Frontend / UI-UX Developer / Documentation
* **Ybut, John Eddie F..** – Documentation

---

## Tech Stack
* **Frontend:** [HTML, CSS, JavaScript]
* **Backend:** [PHP]
* **Database:** [MySQL]
* **Tools & Version Control:** Git, GitHub, VS Code, XAMPP, Composer]
* **Libraries / Integrations:** GPHPMailer, Google API Client, at Gemini API]


---

## System Requirements & Prerequisites
Before you begin, ensure you have the following installed on your local machine:
* PHP 8.0 or later with `mysqli`, `curl`, and `fileinfo` enabled
* XAMPP (Apache and MySQL) or equivalent
* Composer
* Git
---

## Installation & Setup Instructions

### 1. Clone the Repository

```bash
git clone https://github.com/<your-username>/<your-repository-name>.git
cd <your-repository-name>
```

Replace the URL and folder name with the values for your public repository.

### 2. Install Dependencies

Composer manifests are located in the `owner` and `user` directories:

```bash
cd owner
composer install
cd ../user
composer install
cd ..
```

---

## Database Setup & Configuration

Follow these steps to configure your local database:

1. **Start your local database server** (e.g., open XAMPP and start **Apache** and **MySQL**).
2. **Create a new database:**
   * Open phpMyAdmin (`http://localhost/phpmyadmin`) or your database client.
   * Create a new database named: `admin_dashboard_system` (Match this with your `.env` configuration).
3. **Import the Database Schema:**
   * Select the database you created and import the repository's `system.sql` file.
   * The SQL file creates the tables; it does not create or select a database.
4. **Configure Environment Variables:**
   *Open the .env file in the root directory. The project reads configuration settings from environment variables or a private deployment-config.php file located in the project root.
   *Update the database connection, email (SMTP), and Gemini API settings using your own credentials. 
    *Save the changes before running the system.
     ```
    'APP_ENV' => 'development',
    'APP_BASE_URL' => 'http://localhost/capstone',
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_NAME' => 'admin_dashboard_system',
    'DB_USER' => 'root',
    'SMTP_HOST' => 'smtp.gmail.com',
    'SMTP_PORT' => '587',
    'SMTP_ENCRYPTION' => 'tls',
    'SMTP_USERNAME' => 'yourgmail@gmail.com',
    'SMTP_PASSWORD' => 'your-app-password',
    'SMTP_FROM_EMAIL' => 'yourgmail@gmail.com',
    'SMTP_FROM_NAME' => 'Josefina Farmacia',
    'GEMINI_API_KEY' => 'api-key',
     ```
---

### Running the Application

1. Open the XAMPP Control Panel.
2. Start Apache and MySQL.
3. Make sure the project folder is located inside the `htdocs` directory.
4. Open your browser and navigate to:

- Customer Website: `http://localhost/capstone`
- phpMyAdmin: `http://localhost/phpmyadmin`

5\. The system is now ready to use.

Note: Make sure the project database has been imported into phpMyAdmin before running the application.
---

## Default Login Credentials (If applicable)
* **Admin Account:** `admin` / `admin123!`
* **Customer Account: ** `login-your-gmail`
* **Rider Account: ** `rider` / `rider123!`
* **Pharmacist Account: ** `Pharmacist`/ `rider123!`



