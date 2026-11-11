-- Consolidated schema and additive migrations for admin_dashboard_system.
-- This file creates tables and default email templates; it does not restore application data.
-- Do not run destructive column-removal migrations: coupon_code and discount_amount are used by the app.
-- Select the host-provided database in phpMyAdmin before importing this file.

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    actor_role VARCHAR(30) NOT NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_logs_created_at (created_at),
    KEY idx_audit_logs_actor_created (actor_user_id, created_at),
    KEY idx_audit_logs_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Core customer and order schema
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20) UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin', 'owner') DEFAULT 'user',
    address TEXT,
    otp_code VARCHAR(10),
    otp_expiry DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_pic VARCHAR(255) DEFAULT 'default.png';
ALTER TABLE users ADD COLUMN IF NOT EXISTS full_name VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active TINYINT(1) DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login DATETIME NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS otp_attempts INT DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS otp_block_until DATETIME;
ALTER TABLE users ADD COLUMN IF NOT EXISTS dob DATE NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS gender VARCHAR(20) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS city VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS postal_code VARCHAR(20) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS province VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS login_attempts INT DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_change_required TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    box INT DEFAULT 0,
    pieces INT DEFAULT 0,
    image VARCHAR(255),
    category VARCHAR(100),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE products ADD COLUMN IF NOT EXISTS benefits TEXT;
ALTER TABLE products ADD COLUMN IF NOT EXISTS supplier_id INT;
ALTER TABLE products ADD COLUMN IF NOT EXISTS cost_price DECIMAL(10,2);
ALTER TABLE products ADD COLUMN IF NOT EXISTS reorder_level INT DEFAULT 10;
ALTER TABLE products ADD COLUMN IF NOT EXISTS barcode VARCHAR(100);
ALTER TABLE products ADD COLUMN IF NOT EXISTS sku VARCHAR(100);
ALTER TABLE products ADD COLUMN IF NOT EXISTS price_piece DECIMAL(10,2);
ALTER TABLE products ADD COLUMN IF NOT EXISTS price_box DECIMAL(10,2);
ALTER TABLE products ADD COLUMN IF NOT EXISTS quantity INT;
ALTER TABLE products ADD COLUMN IF NOT EXISTS pieces_per_box INT;
ALTER TABLE products ADD COLUMN IF NOT EXISTS ingredients TEXT;
ALTER TABLE products ADD COLUMN IF NOT EXISTS usage_instructions TEXT;
ALTER TABLE products ADD COLUMN IF NOT EXISTS brand VARCHAR(150);
ALTER TABLE products ADD COLUMN IF NOT EXISTS product_form VARCHAR(100);
ALTER TABLE products ADD COLUMN IF NOT EXISTS serving_size VARCHAR(100);
ALTER TABLE products ADD COLUMN IF NOT EXISTS prescription_required TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 20;
ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_alert_sent TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_last_alert_at DATETIME NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_email_status VARCHAR(20) NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_email_error TEXT NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS min_pieces INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE products ADD COLUMN IF NOT EXISTS min_boxes INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN IF NOT EXISTS volume VARCHAR(100) NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS stock_alert_state VARCHAR(20) NULL;

CREATE TABLE IF NOT EXISTS product_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    image_url VARCHAR(500) NULL,
    image_path VARCHAR(255) NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_product_images_product (product_id),
    UNIQUE KEY unique_product_image_order (product_id, sort_order),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_login_activity (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token_hash CHAR(64) NOT NULL,
    login_method ENUM('password', 'google') NOT NULL,
    user_agent VARCHAR(512) NOT NULL DEFAULT '',
    logged_in_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL DEFAULT NULL,
    INDEX idx_user_login_activity_user_time (user_id, logged_in_at),
    INDEX idx_user_login_activity_session (session_token_hash),
    CONSTRAINT fk_user_login_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cart (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    box INT DEFAULT 0,
    pieces INT DEFAULT 0,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_cart_item (user_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    fullname VARCHAR(255) NOT NULL,
    contact_number VARCHAR(20) NULL,
    address TEXT NOT NULL,
    payment_method ENUM('cod', 'gcash', 'bank') DEFAULT 'cod',
    payment_proof VARCHAR(255) NULL,
    payment_transaction_id VARCHAR(100) NULL,
    payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
    discount_type VARCHAR(30) NULL,
    discount_id_proof VARCHAR(255) NULL,
    discount_status VARCHAR(30) NOT NULL DEFAULT 'not_applicable',
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_used_at DATETIME NULL,
    delivery_type ENUM('pickup', 'delivery') DEFAULT 'delivery',
    status ENUM('pending', 'processing', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(10,2) DEFAULT 0.00,
    shipping_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    pickup_deadline DATETIME NULL,
    coupon_code VARCHAR(50) NULL,
    coupon_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tracking_number VARCHAR(255) NULL,
    estimated_delivery DATETIME NULL,
    tracking_status TEXT NULL,
    profit_margin DECIMAL(10,2) NULL,
    delivery_date DATETIME NULL,
    prescription_image VARCHAR(255) NULL,
    prescription_status VARCHAR(20) NOT NULL DEFAULT 'none',
    prescription_reviewed_at DATETIME NULL,
    prescription_reviewed_by INT NULL,
    schedule_type VARCHAR(20) NOT NULL DEFAULT 'delivery',
    schedule_date DATE NULL,
    schedule_time TIME NULL,
    tracking_url VARCHAR(500) NULL,
    payment_reference VARCHAR(100) NULL,
    payment_verified_at DATETIME NULL,
    payment_verified_by INT NULL,
    delivery_latitude DECIMAL(10,7) NULL,
    delivery_longitude DECIMAL(10,7) NULL,
    delivery_otp_hash VARCHAR(255) NULL,
    delivery_otp_verified_at DATETIME NULL,
    delivery_proof_photo VARCHAR(255) NULL,
    rider_earnings DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tracking_shared_at DATETIME NULL,
    tracking_shared_by INT NULL,
    tracking_share_token VARCHAR(64) NULL,
    seller_id INT NULL,
    courier_name VARCHAR(100) NULL,
    delivery_status VARCHAR(30) NULL,
    delivery_failure_reason VARCHAR(100) NULL,
    delivery_action VARCHAR(100) NULL,
    rider_id INT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_proof VARCHAR(255) NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_transaction_id VARCHAR(100) NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid';
ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS pickup_deadline DATETIME NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(50) NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS coupon_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS rider_id INT NULL;

CREATE TABLE IF NOT EXISTS order_discount_ids (
    order_id INT NOT NULL PRIMARY KEY,
    image_data LONGBLOB NOT NULL,
    image_mime VARCHAR(50) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_discount_ids_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(255) NULL,
    box INT DEFAULT 0,
    pieces INT DEFAULT 0,
    price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NULL,
    quantity INT DEFAULT 0,
    box_qty INT DEFAULT 0,
    piece_qty INT DEFAULT 0,
    batch_id INT UNSIGNED NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    message TEXT NOT NULL,
    status ENUM('read', 'unread') DEFAULT 'unread',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS addresses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    recipient_name VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    address TEXT NOT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    is_default BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coupons (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL UNIQUE,
    discount_type ENUM('percent', 'fixed') NOT NULL,
    discount_value DECIMAL(10,2) NOT NULL,
    min_order DECIMAL(10,2) DEFAULT 0.00,
    usage_limit INT DEFAULT NULL,
    used_count INT DEFAULT 0,
    expiry_date DATETIME,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS support_tickets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    status ENUM('open', 'in_progress', 'closed') DEFAULT 'open',
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    category ENUM('Order Issue', 'Product Quality', 'Delivery', 'Account', 'Other') DEFAULT 'Other',
    description TEXT NULL,
    ticket_number VARCHAR(50) NULL UNIQUE,
    assigned_to INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS support_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ticket_id INT NOT NULL,
    user_id INT,
    message TEXT NOT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    attachment VARCHAR(255) NULL,
    is_internal TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT,
    email_type ENUM('order_confirmation', 'invoice', 'tracking_update', 'receipt') NOT NULL,
    recipient_email VARCHAR(100) NOT NULL,
    subject VARCHAR(255),
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('sent', 'failed') DEFAULT 'sent',
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    address TEXT NULL,
    city VARCHAR(100) NULL,
    province VARCHAR(100) NULL,
    postal_code VARCHAR(10) NULL,
    company_name VARCHAR(255) NULL,
    tin_number VARCHAR(50) NULL,
    payment_terms VARCHAR(100) NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE products
    ADD CONSTRAINT products_supplier_fk
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_number VARCHAR(50) UNIQUE NOT NULL,
    supplier_id INT NOT NULL,
    order_date DATETIME NULL,
    expected_delivery DATE NULL,
    actual_delivery DATE NULL,
    total_amount DECIMAL(10,2) NULL,
    status ENUM('Pending', 'Confirmed', 'Shipped', 'Delivered', 'Cancelled') DEFAULT 'Pending',
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NULL,
    unit_price DECIMAL(10,2) NULL,
    total_price DECIMAL(10,2) NULL,
    received_quantity INT DEFAULT 0,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS returns_refunds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_number VARCHAR(50) UNIQUE NOT NULL,
    order_id INT NOT NULL,
    user_id INT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('Requested', 'Approved', 'Rejected', 'Shipped Back', 'Completed') DEFAULT 'Requested',
    return_date DATETIME NULL,
    refund_amount DECIMAL(10,2) NULL,
    refund_date DATETIME NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_chat_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    sender_role VARCHAR(10) NOT NULL DEFAULT 'user',
    message TEXT NULL,
    medicine_name VARCHAR(255) NULL,
    prescription_image VARCHAR(255) NULL,
    prescription_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    prescription_reviewed_at DATETIME NULL,
    prescription_reviewed_by INT NULL,
    product_id INT NULL,
    prescription_id INT NULL,
    shared_product_id INT NULL,
    product_access_expires_at DATETIME NULL,
    ocr_text TEXT NULL,
    ocr_status VARCHAR(20) NOT NULL DEFAULT 'not_run',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_chat_user (user_id),
    KEY idx_chat_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    min_stock_level INT DEFAULT 10,
    alert_threshold INT DEFAULT 20,
    is_active TINYINT(1) DEFAULT 1,
    last_alert_sent DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_product_alert (product_id),
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_reorders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    current_stock INT NULL,
    reorder_quantity INT NULL,
    supplier_id INT NULL,
    requested_by INT NULL,
    expected_delivery DATE NULL,
    received_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    order_group_id VARCHAR(40) NULL,
    notes TEXT NULL,
    status ENUM('Pending', 'Ordered', 'Received', 'Cancelled') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(100) UNIQUE NOT NULL,
    template_type ENUM('Order Confirmation', 'Order Shipped', 'Order Delivered', 'Return Approved', 'Ticket Update', 'Low Stock Alert', 'Custom') DEFAULT 'Custom',
    subject VARCHAR(255) NOT NULL,
    body LONGTEXT NOT NULL,
    variables TEXT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bulk_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NULL,
    upload_type ENUM('Products', 'Suppliers', 'Prices') DEFAULT 'Products',
    total_rows INT NULL,
    successful_rows INT NULL,
    failed_rows INT NULL,
    status ENUM('Pending', 'Processing', 'Completed', 'Failed') DEFAULT 'Pending',
    error_log LONGTEXT NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS low_stock_alert_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    current_stock INT NOT NULL,
    low_stock_threshold INT NOT NULL,
    status ENUM('sent', 'failed') NOT NULL,
    error_message TEXT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_low_stock_product (product_id),
    KEY idx_low_stock_attempted (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_batches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    lot_number VARCHAR(100) NOT NULL,
    expiration_date DATE NOT NULL,
    manufacturing_date DATE NULL,
    received_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    current_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    recalled TINYINT(1) NOT NULL DEFAULT 0,
    recall_reason VARCHAR(255) NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_lot (product_id, lot_number),
    KEY idx_batch_expiration (expiration_date),
    KEY idx_batch_recalled (recalled),
    CONSTRAINT product_batches_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT product_batches_user_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE product_batches ADD COLUMN IF NOT EXISTS manufacturing_date DATE NULL AFTER expiration_date;

CREATE TABLE IF NOT EXISTS batch_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    batch_id INT UNSIGNED NULL,
    product_id INT NOT NULL,
    order_id INT NULL,
    pos_sale_id INT UNSIGNED NULL,
    quantity INT NOT NULL,
    movement_type ENUM('received', 'sold', 'adjusted', 'recalled', 'returned') NOT NULL,
    reference VARCHAR(100) NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_batch_movement_batch (batch_id),
    KEY idx_batch_movement_order (order_id),
    KEY idx_batch_movement_pos_sale (pos_sale_id),
    CONSTRAINT batch_movements_batch_fk FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE CASCADE,
    CONSTRAINT batch_movements_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT batch_movements_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    CONSTRAINT batch_movements_user_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pos_sales (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(100) NOT NULL DEFAULT 'Onsite Customer',
    subtotal_amount DECIMAL(10,2) NOT NULL,
    discount_type VARCHAR(30) NULL,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_reference VARCHAR(255) NOT NULL,
    cashier_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pos_sales_cashier (cashier_id),
    KEY idx_pos_sales_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pos_sale_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sale_id INT UNSIGNED NOT NULL,
    product_id INT NULL,
    product_name VARCHAR(255) NOT NULL,
    category VARCHAR(100) NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    KEY idx_pos_sale_items_sale (sale_id),
    CONSTRAINT fk_pos_sale_items_sale FOREIGN KEY (sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pos_sale_discount_ids (
    sale_id INT UNSIGNED NOT NULL PRIMARY KEY,
    image_data LONGBLOB NOT NULL,
    image_mime VARCHAR(50) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pos_sale_discount_ids_sale FOREIGN KEY (sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_accounts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    points_balance INT NOT NULL DEFAULT 0,
    lifetime_points INT NOT NULL DEFAULT 0,
    tier VARCHAR(20) NOT NULL DEFAULT 'Bronze',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_loyalty_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_id INT NULL,
    points INT NOT NULL,
    transaction_type VARCHAR(30) NOT NULL,
    description VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_loyalty_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_vouchers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL,
    discount_type ENUM('fixed', 'percent') NOT NULL DEFAULT 'fixed',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    min_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    usage_limit INT UNSIGNED NOT NULL DEFAULT 1,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_voucher_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS voucher_redemptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    voucher_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    order_id INT NULL,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    redeemed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_voucher_order (voucher_id, order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_substitutions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    substitute_product_id INT NOT NULL,
    reason VARCHAR(255) NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    created_by INT NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_substitute (product_id, substitute_product_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (substitute_product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS prescriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL DEFAULT '',
    file_name VARCHAR(255) NOT NULL DEFAULT '',
    file_path VARCHAR(255) NULL,
    valid_id_original_name VARCHAR(255) NULL,
    valid_id_file_name VARCHAR(255) NULL,
    notes TEXT NULL,
    status ENUM('pending', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'pending',
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    review_reason TEXT NULL,
    shared_product_id INT NULL,
    product_access_expires_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_prescriptions_user_id (user_id),
    KEY idx_prescriptions_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS prescription_chat_open_requests (
    prescription_id INT NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_at DATETIME NULL,
    KEY idx_prescription_chat_open_user (user_id, opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS prescription_product_access (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    granted_by INT NULL,
    prescription_id INT NULL,
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    quantity_limit INT UNSIGNED NOT NULL DEFAULT 0,
    quantity_purchased INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_prescription_product_user (product_id, user_id),
    KEY idx_prescription_access_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Keep older installations compatible with the current order-item quantity fields.
ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS quantity INT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS box_qty INT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS piece_qty INT DEFAULT 0;

UPDATE order_items
SET quantity = COALESCE(quantity, 0),
    box_qty = COALESCE(box_qty, box),
    piece_qty = COALESCE(piece_qty, pieces)
WHERE quantity IS NULL OR box_qty IS NULL OR piece_qty IS NULL;

ALTER TABLE order_items
    MODIFY product_name VARCHAR(255) NULL,
    MODIFY total_price DECIMAL(10,2) NULL;

-- Additive columns required by prescription submission and product sharing.
ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS original_name VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS file_name VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS file_path VARCHAR(255) NULL;
ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS valid_id_original_name VARCHAR(255) NULL;
ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS valid_id_file_name VARCHAR(255) NULL;
ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS notes TEXT NULL;
ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL;
ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS review_reason TEXT NULL;
ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS shared_product_id INT NULL;
ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS product_access_expires_at DATETIME NULL;
ALTER TABLE prescriptions MODIFY status ENUM('pending', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'pending';

ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS shared_product_id INT NULL;
ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS product_access_expires_at DATETIME NULL;
ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS prescription_id INT NULL;
ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_limit INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_purchased INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_type VARCHAR(30) NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_id_proof VARCHAR(255) NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_status VARCHAR(30) NOT NULL DEFAULT 'not_applicable';
ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_used_at DATETIME NULL;
CREATE TABLE IF NOT EXISTS order_discount_ids (
    order_id INT NOT NULL PRIMARY KEY,
    image_data LONGBLOB NOT NULL,
    image_mime VARCHAR(50) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_discount_ids_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    default_min_purchase_quantity INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO inventory_settings (id, default_min_purchase_quantity)
VALUES (1, 1)
ON DUPLICATE KEY UPDATE id = id;

-- Rider delivery schema, consolidated from rider/delivery_helpers.php
CREATE TABLE IF NOT EXISTS rider_profiles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    phone VARCHAR(30) NULL,
    vehicle_type VARCHAR(50) NULL,
    plate_number VARCHAR(30) NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at DATETIME NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    profile_image VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS delivery_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    rider_id INT NULL,
    event_type VARCHAR(40) NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_delivery_events_order (order_id),
    INDEX idx_delivery_events_rider (rider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_ratings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    user_id INT NOT NULL,
    rider_id INT NULL,
    score TINYINT UNSIGNED NOT NULL,
    comment VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_order_rating (order_id, user_id),
    INDEX idx_order_ratings_rider (rider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO email_templates (template_name, template_type, subject, body, variables, is_active)
VALUES
('Order Confirmation', 'Order Confirmation', 'Your Order {{order_id}} has been Confirmed', 'Dear {{customer_name}}, thank you for your order. Order date: {{order_date}}. Total: {{total_amount}}. Delivery address: {{delivery_address}}.', '["order_id", "customer_name", "order_date", "total_amount", "delivery_address"]', 1),
('Order Shipped', 'Order Shipped', 'Your Order {{order_id}} has been Shipped!', 'Your order {{order_id}} has shipped. Tracking: {{tracking_number}}. Expected delivery: {{expected_delivery}}.', '["order_id", "customer_name", "courier_name", "tracking_number", "expected_delivery"]', 1),
('Order Delivered', 'Order Delivered', 'Your Order {{order_id}} has been Delivered!', 'Your order has been delivered. Thank you for shopping with Josefina Farmacia.', '["order_id", "customer_name"]', 1),
('Return Approved', 'Return Approved', 'Your Return Request #{{return_id}} has been Approved', 'Your return {{return_id}} for order {{order_id}} has been approved.', '["return_id", "customer_name", "order_id", "refund_amount", "return_instructions"]', 1),
('Low Stock Alert', 'Low Stock Alert', 'Low Stock Alert: {{product_name}}', 'Low stock: {{product_name}}. Current stock: {{current_stock}}. Minimum: {{min_stock_level}}.', '["product_name", "current_stock", "min_stock_level", "product_id"]', 1);

-- Indexes used by application queries
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_products_category ON products(category);
CREATE INDEX idx_products_status ON products(status);
CREATE INDEX idx_products_name ON products(product_name);
CREATE INDEX idx_orders_user_id ON orders(user_id);
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_date ON orders(order_date);
CREATE INDEX idx_order_items_order_id ON order_items(order_id);
CREATE INDEX idx_order_items_product_id ON order_items(product_id);
CREATE INDEX idx_notifications_user_id ON notifications(user_id);
CREATE INDEX idx_notifications_status ON notifications(status);
CREATE INDEX idx_notifications_created_at ON notifications(created_at);
CREATE INDEX idx_cart_user_id ON cart(user_id);
CREATE INDEX idx_cart_product_id ON cart(product_id);
CREATE INDEX idx_addresses_user_id ON addresses(user_id);
CREATE INDEX idx_addresses_default ON addresses(is_default);
CREATE INDEX idx_coupons_expiry ON coupons(expiry_date);
CREATE INDEX idx_support_messages_ticket_id ON support_messages(ticket_id);
CREATE INDEX idx_email_logs_order_id ON email_logs(order_id);
CREATE INDEX idx_email_logs_type ON email_logs(email_type);
CREATE INDEX idx_products_supplier ON products(supplier_id);
CREATE INDEX idx_support_tickets_user ON support_tickets(user_id);
CREATE INDEX idx_support_tickets_status ON support_tickets(status);
CREATE INDEX idx_returns_status ON returns_refunds(status);
CREATE INDEX idx_purchase_orders_supplier ON purchase_orders(supplier_id);
CREATE INDEX idx_purchase_orders_status ON purchase_orders(status);
CREATE INDEX idx_inventory_alerts_product ON inventory_alerts(product_id);
CREATE INDEX idx_bulk_uploads_date ON bulk_uploads(created_at);
CREATE INDEX idx_ticket_replies_ticket ON ticket_replies(ticket_id);

-- Additive sync for consolidated Capstone schema items not in the base setup.
ALTER TABLE orders ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(50) NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE products ADD COLUMN IF NOT EXISTS servings_per_container INT NULL;
ALTER TABLE inventory_reorders ADD COLUMN IF NOT EXISTS expected_delivery_time TIME DEFAULT '09:00:00';
ALTER TABLE inventory_reorders ADD COLUMN IF NOT EXISTS received_quantity INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE inventory_reorders ADD COLUMN IF NOT EXISTS order_group_id VARCHAR(40) NULL;

CREATE TABLE IF NOT EXISTS logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(255) NULL,
    log_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_logs_user_id (user_id),
    CONSTRAINT logs_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS otp_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    identifier VARCHAR(100) NOT NULL,
    otp_code VARCHAR(6) NOT NULL,
    otp_expiry DATETIME NOT NULL,
    is_used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_otp_user_id (user_id),
    KEY idx_otp_identifier (identifier),
    CONSTRAINT otp_codes_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NULL,
    product_name VARCHAR(255) NULL,
    price DECIMAL(10,2) NULL,
    quantity INT NULL,
    image VARCHAR(255) NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    pieces INT DEFAULT 0,
    box INT DEFAULT 0,
    price_piece DECIMAL(10,2) DEFAULT 0.00,
    price_box DECIMAL(10,2) DEFAULT 0.00,
    pieces_per_box INT DEFAULT 12,
    KEY idx_product_history_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
