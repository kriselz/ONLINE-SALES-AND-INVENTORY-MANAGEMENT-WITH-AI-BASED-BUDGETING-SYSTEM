# Hosting setup

## Before uploading

1. Use PHP 8 or later with MySQLi, cURL, and Fileinfo enabled.
2. Create a MySQL database and database user in the hosting control panel.
3. Copy `deployment-config.example.php` to `deployment-config.php` and replace every placeholder with the values from the hosting panel. Set `APP_BASE_URL` to the HTTPS site URL; include a subfolder if the app is not installed at the domain root.
4. Keep `deployment-config.php` private. The root `.htaccess` denies direct downloads of it and the database export files. Do not put real credentials in the example file or commit the private config.
5. Enable HTTPS for the domain.

## Database and products

1. Select the database created by the host in phpMyAdmin, then import `system.sql`. It intentionally does not create a database or select a local XAMPP database.
2. Import `products.csv` into the `products` table if migrating the local catalog. Upload the referenced product images too. The CSV contains the catalog only; it does not contain users, orders, batches, prescriptions, or other application records.
3. The CSV includes demo/test products. Remove those records before using the catalog for real sales.
4. Register your own account, then promote only that account to `admin` in phpMyAdmin. The schema no longer creates a shared default administrator.

## Email, AI, and privacy

- Configure SMTP in `deployment-config.php` only if the host permits the selected SMTP service. For Gmail, set `SMTP_USERNAME` to the full Google account email address (not the sender display name) and use a Google App Password; `SMTP_FROM_NAME` is the display name. Test OTP and notification emails after deployment.
- Set `GEMINI_API_KEY` to a newly issued, restricted key if the AI feature is needed. Revoke/rotate any key that was previously embedded in application source.
- Payment-proof images are blocked from direct web access and are served only through the authenticated owner review page. Keep the upload-folder `.htaccess` files when uploading.
- Use a unique database password, restrict database access to the hosting account, enable HTTPS, keep backups private, and remove exports/logs from the web root after importing them.

The code and configuration reduce exposure but cannot guarantee that data is secure against the hosting provider, a compromised account, weak passwords, or an incorrectly configured host. Verify the host's backups, access controls, and privacy terms before storing real customer, payment, or prescription data.
