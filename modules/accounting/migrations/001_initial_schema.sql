-- CoreFlux Accounting Module - Initial Schema
-- Version: 1.0.0
-- 
-- Run this migration to set up the accounting tables.
-- Tables are prefixed with 'acct_' to avoid conflicts.

-- =====================================================
-- CHART OF ACCOUNTS
-- =====================================================

CREATE TABLE IF NOT EXISTS acct_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    
    -- Account identification
    account_number VARCHAR(20) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    
    -- Account classification
    account_type ENUM('asset', 'liability', 'equity', 'revenue', 'expense') NOT NULL,
    sub_type VARCHAR(50),  -- e.g., 'current_asset', 'fixed_asset', 'operating_expense'
    
    -- Hierarchy (for sub-accounts)
    parent_id INT DEFAULT NULL,
    
    -- Behavior
    normal_balance ENUM('debit', 'credit') NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    is_system BOOLEAN DEFAULT FALSE,  -- System accounts can't be deleted
    
    -- Tracking
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    -- Constraints
    UNIQUE KEY unique_account_per_tenant (tenant_id, account_number),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES acct_accounts(id) ON DELETE SET NULL,
    INDEX idx_tenant_type (tenant_id, account_type),
    INDEX idx_tenant_active (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- FISCAL PERIODS
-- =====================================================

CREATE TABLE IF NOT EXISTS acct_fiscal_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    
    name VARCHAR(100) NOT NULL,  -- e.g., "January 2026", "Q1 2026", "FY 2026"
    period_type ENUM('month', 'quarter', 'year') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    
    -- Status
    is_closed BOOLEAN DEFAULT FALSE,
    closed_at TIMESTAMP NULL,
    closed_by INT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_dates (tenant_id, start_date, end_date),
    INDEX idx_tenant_closed (tenant_id, is_closed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- JOURNAL ENTRIES (Header)
-- =====================================================

CREATE TABLE IF NOT EXISTS acct_journal_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    
    -- Entry identification
    entry_number VARCHAR(20) NOT NULL,  -- Auto-generated: JE-2026-00001
    entry_date DATE NOT NULL,
    
    -- Classification
    entry_type ENUM('standard', 'adjusting', 'closing', 'reversing') DEFAULT 'standard',
    source VARCHAR(50),  -- e.g., 'manual', 'ap_invoice', 'ar_receipt'
    source_id INT,       -- Reference to source document
    
    -- Content
    description TEXT,
    memo TEXT,
    
    -- Status
    status ENUM('draft', 'posted', 'reversed') DEFAULT 'draft',
    posted_at TIMESTAMP NULL,
    posted_by INT,
    
    -- Reversal tracking
    reversed_at TIMESTAMP NULL,
    reversed_by INT,
    reversal_entry_id INT,  -- Points to the reversing entry
    
    -- Audit
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    -- Constraints
    UNIQUE KEY unique_entry_per_tenant (tenant_id, entry_number),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (reversal_entry_id) REFERENCES acct_journal_entries(id) ON DELETE SET NULL,
    INDEX idx_tenant_date (tenant_id, entry_date),
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_tenant_type (tenant_id, entry_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- JOURNAL ENTRY LINES (Details)
-- =====================================================

CREATE TABLE IF NOT EXISTS acct_journal_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    journal_entry_id INT NOT NULL,
    
    -- Account reference
    account_id INT NOT NULL,
    
    -- Amounts (one should be zero)
    debit_amount DECIMAL(15,2) DEFAULT 0.00,
    credit_amount DECIMAL(15,2) DEFAULT 0.00,
    
    -- Line details
    description VARCHAR(500),
    line_order INT DEFAULT 0,
    
    -- Optional: Department/cost center tracking
    department_id INT,
    project_id INT,
    
    FOREIGN KEY (journal_entry_id) REFERENCES acct_journal_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES acct_accounts(id) ON DELETE RESTRICT,
    INDEX idx_entry (journal_entry_id),
    INDEX idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- ACCOUNT BALANCES (Denormalized for performance)
-- =====================================================

CREATE TABLE IF NOT EXISTS acct_account_balances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    account_id INT NOT NULL,
    fiscal_period_id INT NOT NULL,
    
    -- Period activity
    period_debit DECIMAL(15,2) DEFAULT 0.00,
    period_credit DECIMAL(15,2) DEFAULT 0.00,
    
    -- Running balance
    ending_balance DECIMAL(15,2) DEFAULT 0.00,
    
    -- Last updated
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_balance (tenant_id, account_id, fiscal_period_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES acct_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (fiscal_period_id) REFERENCES acct_fiscal_periods(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- VENDORS (for Accounts Payable)
-- =====================================================

CREATE TABLE IF NOT EXISTS acct_vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    
    -- Identification
    vendor_code VARCHAR(20),
    name VARCHAR(255) NOT NULL,
    
    -- Contact
    contact_name VARCHAR(255),
    email VARCHAR(255),
    phone VARCHAR(50),
    
    -- Address
    address_line1 VARCHAR(255),
    address_line2 VARCHAR(255),
    city VARCHAR(100),
    state VARCHAR(100),
    postal_code VARCHAR(20),
    country VARCHAR(100),
    
    -- Payment info
    payment_terms INT DEFAULT 30,  -- Net days
    default_expense_account_id INT,
    
    -- Tax
    tax_id VARCHAR(50),
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (default_expense_account_id) REFERENCES acct_accounts(id) ON DELETE SET NULL,
    INDEX idx_tenant_active (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- AP INVOICES (Bills from vendors)
-- =====================================================

CREATE TABLE IF NOT EXISTS acct_ap_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    vendor_id INT NOT NULL,
    
    -- Invoice details
    invoice_number VARCHAR(100) NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    
    -- Amounts
    subtotal DECIMAL(15,2) NOT NULL,
    tax_amount DECIMAL(15,2) DEFAULT 0.00,
    total_amount DECIMAL(15,2) NOT NULL,
    amount_paid DECIMAL(15,2) DEFAULT 0.00,
    balance_due DECIMAL(15,2) GENERATED ALWAYS AS (total_amount - amount_paid) STORED,
    
    -- Status
    status ENUM('draft', 'pending', 'partial', 'paid', 'void') DEFAULT 'draft',
    
    -- Posting
    journal_entry_id INT,
    
    -- Notes
    description TEXT,
    memo TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (vendor_id) REFERENCES acct_vendors(id) ON DELETE RESTRICT,
    FOREIGN KEY (journal_entry_id) REFERENCES acct_journal_entries(id) ON DELETE SET NULL,
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_tenant_due (tenant_id, due_date),
    INDEX idx_vendor (vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- CUSTOMERS (for Accounts Receivable)
-- =====================================================

CREATE TABLE IF NOT EXISTS acct_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    
    -- Identification
    customer_code VARCHAR(20),
    name VARCHAR(255) NOT NULL,
    
    -- Contact
    contact_name VARCHAR(255),
    email VARCHAR(255),
    phone VARCHAR(50),
    
    -- Address
    address_line1 VARCHAR(255),
    address_line2 VARCHAR(255),
    city VARCHAR(100),
    state VARCHAR(100),
    postal_code VARCHAR(20),
    country VARCHAR(100),
    
    -- Payment info
    payment_terms INT DEFAULT 30,
    credit_limit DECIMAL(15,2),
    default_revenue_account_id INT,
    
    -- Tax
    tax_id VARCHAR(50),
    is_tax_exempt BOOLEAN DEFAULT FALSE,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (default_revenue_account_id) REFERENCES acct_accounts(id) ON DELETE SET NULL,
    INDEX idx_tenant_active (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- AR INVOICES (Bills to customers)
-- =====================================================

CREATE TABLE IF NOT EXISTS acct_ar_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    customer_id INT NOT NULL,
    
    -- Invoice details
    invoice_number VARCHAR(100) NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    
    -- Amounts
    subtotal DECIMAL(15,2) NOT NULL,
    tax_amount DECIMAL(15,2) DEFAULT 0.00,
    total_amount DECIMAL(15,2) NOT NULL,
    amount_paid DECIMAL(15,2) DEFAULT 0.00,
    balance_due DECIMAL(15,2) GENERATED ALWAYS AS (total_amount - amount_paid) STORED,
    
    -- Status
    status ENUM('draft', 'sent', 'partial', 'paid', 'void', 'overdue') DEFAULT 'draft',
    
    -- Posting
    journal_entry_id INT,
    
    -- Notes
    description TEXT,
    memo TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    UNIQUE KEY unique_invoice (tenant_id, invoice_number),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES acct_customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (journal_entry_id) REFERENCES acct_journal_entries(id) ON DELETE SET NULL,
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_tenant_due (tenant_id, due_date),
    INDEX idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- MODULE SETTINGS
-- =====================================================

CREATE TABLE IF NOT EXISTS acct_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_setting (tenant_id, setting_key),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- SEED DEFAULT CHART OF ACCOUNTS (Run per tenant)
-- =====================================================
-- This is a stored procedure to create default accounts for a new tenant

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS acct_seed_default_accounts(IN p_tenant_id INT, IN p_created_by INT)
BEGIN
    -- Assets (1xxx)
    INSERT INTO acct_accounts (tenant_id, account_number, name, account_type, sub_type, normal_balance, is_system, created_by) VALUES
    (p_tenant_id, '1000', 'Cash and Cash Equivalents', 'asset', 'current_asset', 'debit', TRUE, p_created_by),
    (p_tenant_id, '1010', 'Checking Account', 'asset', 'current_asset', 'debit', FALSE, p_created_by),
    (p_tenant_id, '1020', 'Savings Account', 'asset', 'current_asset', 'debit', FALSE, p_created_by),
    (p_tenant_id, '1100', 'Accounts Receivable', 'asset', 'current_asset', 'debit', TRUE, p_created_by),
    (p_tenant_id, '1200', 'Inventory', 'asset', 'current_asset', 'debit', FALSE, p_created_by),
    (p_tenant_id, '1300', 'Prepaid Expenses', 'asset', 'current_asset', 'debit', FALSE, p_created_by),
    (p_tenant_id, '1500', 'Fixed Assets', 'asset', 'fixed_asset', 'debit', TRUE, p_created_by),
    (p_tenant_id, '1510', 'Equipment', 'asset', 'fixed_asset', 'debit', FALSE, p_created_by),
    (p_tenant_id, '1520', 'Furniture & Fixtures', 'asset', 'fixed_asset', 'debit', FALSE, p_created_by),
    (p_tenant_id, '1590', 'Accumulated Depreciation', 'asset', 'fixed_asset', 'credit', TRUE, p_created_by);
    
    -- Liabilities (2xxx)
    INSERT INTO acct_accounts (tenant_id, account_number, name, account_type, sub_type, normal_balance, is_system, created_by) VALUES
    (p_tenant_id, '2000', 'Accounts Payable', 'liability', 'current_liability', 'credit', TRUE, p_created_by),
    (p_tenant_id, '2100', 'Accrued Expenses', 'liability', 'current_liability', 'credit', FALSE, p_created_by),
    (p_tenant_id, '2200', 'Payroll Liabilities', 'liability', 'current_liability', 'credit', FALSE, p_created_by),
    (p_tenant_id, '2300', 'Sales Tax Payable', 'liability', 'current_liability', 'credit', FALSE, p_created_by),
    (p_tenant_id, '2500', 'Long-term Debt', 'liability', 'long_term_liability', 'credit', FALSE, p_created_by);
    
    -- Equity (3xxx)
    INSERT INTO acct_accounts (tenant_id, account_number, name, account_type, sub_type, normal_balance, is_system, created_by) VALUES
    (p_tenant_id, '3000', 'Owner\'s Equity', 'equity', 'equity', 'credit', TRUE, p_created_by),
    (p_tenant_id, '3100', 'Retained Earnings', 'equity', 'equity', 'credit', TRUE, p_created_by),
    (p_tenant_id, '3200', 'Owner\'s Draws', 'equity', 'equity', 'debit', FALSE, p_created_by);
    
    -- Revenue (4xxx)
    INSERT INTO acct_accounts (tenant_id, account_number, name, account_type, sub_type, normal_balance, is_system, created_by) VALUES
    (p_tenant_id, '4000', 'Revenue', 'revenue', 'operating_revenue', 'credit', TRUE, p_created_by),
    (p_tenant_id, '4100', 'Service Revenue', 'revenue', 'operating_revenue', 'credit', FALSE, p_created_by),
    (p_tenant_id, '4200', 'Product Sales', 'revenue', 'operating_revenue', 'credit', FALSE, p_created_by),
    (p_tenant_id, '4900', 'Other Income', 'revenue', 'other_revenue', 'credit', FALSE, p_created_by);
    
    -- Expenses (5xxx-6xxx)
    INSERT INTO acct_accounts (tenant_id, account_number, name, account_type, sub_type, normal_balance, is_system, created_by) VALUES
    (p_tenant_id, '5000', 'Cost of Goods Sold', 'expense', 'cost_of_sales', 'debit', TRUE, p_created_by),
    (p_tenant_id, '6000', 'Operating Expenses', 'expense', 'operating_expense', 'debit', TRUE, p_created_by),
    (p_tenant_id, '6100', 'Salaries & Wages', 'expense', 'operating_expense', 'debit', FALSE, p_created_by),
    (p_tenant_id, '6200', 'Rent Expense', 'expense', 'operating_expense', 'debit', FALSE, p_created_by),
    (p_tenant_id, '6300', 'Utilities', 'expense', 'operating_expense', 'debit', FALSE, p_created_by),
    (p_tenant_id, '6400', 'Office Supplies', 'expense', 'operating_expense', 'debit', FALSE, p_created_by),
    (p_tenant_id, '6500', 'Insurance', 'expense', 'operating_expense', 'debit', FALSE, p_created_by),
    (p_tenant_id, '6600', 'Professional Fees', 'expense', 'operating_expense', 'debit', FALSE, p_created_by),
    (p_tenant_id, '6700', 'Depreciation Expense', 'expense', 'operating_expense', 'debit', FALSE, p_created_by),
    (p_tenant_id, '6900', 'Miscellaneous Expense', 'expense', 'operating_expense', 'debit', FALSE, p_created_by);
    
END //

DELIMITER ;
