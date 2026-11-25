CREATE DATABASE IF NOT EXISTS printing_management
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE printing_management;

-- Printing businesses (tenants) managed by Super Admin.
CREATE TABLE IF NOT EXISTS tenants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    business_name VARCHAR(150) NOT NULL,
    owner_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL,

    logo VARCHAR(255) NULL DEFAULT NULL,

    address VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(100) NOT NULL,
    pincode VARCHAR(20) NOT NULL,

    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_tenants_status (status),
    INDEX idx_tenants_business_name (business_name),
    INDEX idx_tenants_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Platform and tenant users.
-- Super Admin: tenant_id IS NULL, role = super_admin
-- Tenant staff: tenant_id set, role in (business_owner, manager, accountant, employee)
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    tenant_id INT UNSIGNED NULL DEFAULT NULL,

    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    password VARCHAR(255) NOT NULL,

    role ENUM(
        'super_admin',
        'business_owner',
        'manager',
        'accountant',
        'employee'
    ) NOT NULL DEFAULT 'employee',

    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_users_email (email),
    INDEX idx_users_tenant (tenant_id),
    INDEX idx_users_role (role),
    INDEX idx_users_status (status),

    CONSTRAINT fk_users_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    customer_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    email VARCHAR(150) NULL DEFAULT NULL,
    address VARCHAR(255) NULL DEFAULT NULL,
    city VARCHAR(100) NULL DEFAULT NULL,
    notes TEXT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customers_tenant (tenant_id),
    INDEX idx_customers_status (status),
    INDEX idx_customers_name (customer_name),
    INDEX idx_customers_phone (phone),
    CONSTRAINT fk_customers_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    category VARCHAR(100) NULL DEFAULT NULL,
    description TEXT NULL,
    unit ENUM('sq_ft', 'piece', 'meter', 'kg', 'roll', 'other') NOT NULL DEFAULT 'piece',
    pricing_type ENUM('dimension_based', 'fixed_price', 'quantity_based') NOT NULL DEFAULT 'fixed_price',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_tenant (tenant_id),
    INDEX idx_products_status (status),
    INDEX idx_products_name (product_name),
    CONSTRAINT fk_products_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_rates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    rate DECIMAL(12, 4) NOT NULL,
    effective_from DATE NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rates_tenant (tenant_id),
    INDEX idx_rates_product (product_id),
    INDEX idx_rates_effective (product_id, effective_from, status),
    CONSTRAINT fk_rates_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rates_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bills (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    bill_number VARCHAR(40) NOT NULL,
    bill_date DATE NOT NULL,
    total_amount DECIMAL(14, 2) NOT NULL DEFAULT 0,
    status ENUM('draft', 'saved') NOT NULL DEFAULT 'saved',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bills_tenant_number (tenant_id, bill_number),
    INDEX idx_bills_tenant (tenant_id),
    INDEX idx_bills_customer (customer_id),
    INDEX idx_bills_date (bill_date),
    CONSTRAINT fk_bills_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_bills_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bill_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bill_id INT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    product_name_snapshot VARCHAR(150) NOT NULL,
    unit_snapshot VARCHAR(30) NOT NULL,
    pricing_type_snapshot VARCHAR(40) NOT NULL,
    width DECIMAL(12, 4) NULL DEFAULT NULL,
    height DECIMAL(12, 4) NULL DEFAULT NULL,
    quantity DECIMAL(12, 4) NOT NULL DEFAULT 1,
    rate_snapshot DECIMAL(12, 4) NOT NULL,
    area DECIMAL(14, 4) NULL DEFAULT NULL,
    amount DECIMAL(14, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bill_items_bill (bill_id),
    INDEX idx_bill_items_tenant (tenant_id),
    CONSTRAINT fk_bill_items_bill FOREIGN KEY (bill_id) REFERENCES bills(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_bill_items_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_bill_items_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: live installs also add paid_amount, balance_amount, payment_status on bills
-- via migrations/003_payments.sql.

CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    bill_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'upi', 'card', 'other') NOT NULL,
    amount DECIMAL(14, 2) NOT NULL,
    reference_number VARCHAR(100) NULL DEFAULT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payments_tenant (tenant_id),
    INDEX idx_payments_bill (bill_id),
    INDEX idx_payments_customer (customer_id),
    INDEX idx_payments_date (payment_date),
    INDEX idx_payments_method (payment_method),
    CONSTRAINT fk_payments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_payments_bill FOREIGN KEY (bill_id) REFERENCES bills(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_payments_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
