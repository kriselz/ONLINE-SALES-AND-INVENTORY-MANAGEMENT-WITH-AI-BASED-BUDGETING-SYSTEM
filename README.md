# ONLINE-SALES-AND-INVENTORY-MANAGEMENT-WITH-AI-BASED-BUDGETING-SYSTEM

The Online Sales and Inventory Management with AI-Based Budgeting System automates pharmacy sales, online medicine ordering, and inventory management. It is designed for customers, pharmacists, administrators, and pharmacy owners to improve operational efficiency, accuracy, and product availability. AI-assisted analysis of sales, inventory, and financial data helps support budgeting and purchasing decisions.

---

## Group Members & Roles
* **Imperial, Rhea** – Documentation
* **Gliocam, Jomar B.** – Documentation
* **Sabdao, John Rey V.** – Backend / Database Administrator
* **Sumunod, Criselle B.** – Frontend / UI-UX Developer / Documentation
* **Ybut, John Eddie F.** – Documentation

---

## Tech Stack
* **Frontend:** HTML, CSS, JavaScript
* **Backend:** PHP
* **Database:** MySQL
* **Tools & Version Control:** Git, GitHub, VS Code, XAMPP, Composer
* **Libraries / Integrations:** PHPMailer, Google API Client, optional Gemini API integration

---

## System Requirements & Prerequisites
Before you begin, ensure you have the following installed on your local machine:
* PHP 8.0 or later with `mysqli`, `curl`, and `fileinfo` enabled
* XAMPP (Apache and MySQL) or equivalent
* Composer
* Git
* A modern web browser

---

## Installation & Setup Instructions

Follow these step-by-step commands to set up the project locally:

### 1. Clone the Repository
Replace `your-username` with the GitHub username that owns the public repository:

```bash
git clone https://github.com/your-username/ONLINE-SALES-AND-INVENTORY-MANAGEMENT-WITH-AI-BASED-BUDGETING-SYSTEM.git
cd ONLINE-SALES-AND-INVENTORY-MANAGEMENT-WITH-AI-BASED-BUDGETING-SYSTEM
```

### 2. Install Dependencies
The Composer files are located in the `owner` and `user` directories:

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
   * Create a new database named `admin_dashboard_system`.
3. **Import the Database Schema:**
   * Select the database you created and import the repository's `system.sql` file.
   * The SQL file creates the tables; it does not create or select a database.
4. **Configure Database Connection:**
   * The project reads configuration from environment variables or a private `deployment-config.php` file in the project root.
   * For a default local XAMPP setup, use:

     ```text
     DB_HOST=127.0.0.1
     DB_PORT=3306
     DB_NAME=admin_dashboard_system
     DB_USER=root
     DB_PASSWORD=
     ```

   * If you use different database settings, configure the matching values in your environment or private configuration file. Do not commit credentials to GitHub.
5. **Optional product catalog:** If importing `products.csv`, import it into the `products` table and upload the referenced product images. Remove demo or test products before using the catalog for real sales.

---

## Running the Application

Place the project folder inside XAMPP's `htdocs` directory. Start Apache and MySQL, then open the relevant page:

* **Application:** `http://localhost/<project-folder>/`
* **Customer registration:** `http://localhost/<project-folder>/user/register.php`
* **Owner sign-in:** `http://localhost/<project-folder>/owner/login_a.php`
* **Pharmacist sign-in:** `http://localhost/<project-folder>/owner/pharmacist_login.php`
* **Rider sign-in:** `http://localhost/<project-folder>/rider/rider_login.php`

This is a PHP application and runs through Apache; `php artisan serve` and `npm run dev` do not apply. The root URL redirects to the owner homepage.

---

## Default Login Credentials (If applicable)

The database does not contain pre-created accounts, so the project has no default usernames or passwords. Create test accounts using the steps below; each account's username/email and password are the values entered during its creation.

| **User / Customer** | Register at `/user/register.php`. | `/user/login.php` |
| **Admin** | Register an account, then select the project database in phpMyAdmin and run the SQL below with that account's email. | `/owner/login_a.php` |
| **Pharmacist** | Sign in as admin and create an account from the **Pharmacy Staff** section. This creates the required pharmacist staff record. | `/owner/pharmacist_login.php` |
| **Rider** | Sign in as admin and create an account from the **Rider Accounts** section. | `/rider/rider_login.php` |

```sql
UPDATE users
SET role = 'admin'
WHERE email = 'your-email@example.com';
```

Replace `your-email@example.com` with the email used to register the test admin. 