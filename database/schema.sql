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
