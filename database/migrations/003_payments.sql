USE printing_management;

-- Track payment progress on bills (document status stays in status: draft/saved).
SET @col_paid := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bills' AND COLUMN_NAME = 'paid_amount'
);
SET @sql := IF(
    @col_paid = 0,
    "ALTER TABLE bills
        ADD COLUMN paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER total_amount,
        ADD COLUMN balance_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER paid_amount,
        ADD COLUMN payment_status ENUM('unpaid','partially_paid','paid') NOT NULL DEFAULT 'unpaid' AFTER balance_amount,
        ADD INDEX idx_bills_payment_status (payment_status)",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill balance for existing bills.
UPDATE bills
SET balance_amount = GREATEST(total_amount - paid_amount, 0),
    payment_status = CASE
        WHEN paid_amount <= 0 THEN 'unpaid'
        WHEN paid_amount + 0.009 >= total_amount THEN 'paid'
        ELSE 'partially_paid'
    END;

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
