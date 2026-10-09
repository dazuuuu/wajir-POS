-- Ensure upgraded installations have the customer table used by order,
-- invoice, credit, and dashboard queries. This is intentionally independent
-- of migration 044 because an earlier ALTER in that migration may prevent its
-- later CREATE TABLE statements from being reached.

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(160) NOT NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(255) NULL,
    company_name VARCHAR(160) NULL,
    is_b2b TINYINT(1) NOT NULL DEFAULT 0,
    credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    credit_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    loyalty_points DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    loyalty_tier ENUM('standard','silver','gold','platinum') NOT NULL DEFAULT 'standard',
    notes TEXT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cust_tenant (tenant_id),
    KEY idx_cust_phone (tenant_id, phone),
    KEY idx_cust_email (tenant_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
