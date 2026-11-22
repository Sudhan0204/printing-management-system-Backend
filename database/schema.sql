CREATE DATABASE IF NOT EXISTS printing_management
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE printing_management;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
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
        ON UPDATE CURRENT_TIMESTAMP
);