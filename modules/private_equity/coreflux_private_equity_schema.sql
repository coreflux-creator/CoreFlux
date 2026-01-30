
-- Table: pe_cap_tables
CREATE TABLE pe_cap_tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    scenario_id INT NOT NULL,
    shareholder VARCHAR(255) NOT NULL,
    class ENUM('Common', 'Preferred', 'Convertible') NOT NULL,
    ownership_pct DECIMAL(10,6) DEFAULT 0,
    invested_amount DECIMAL(18,2) DEFAULT 0,
    convertible_note BOOLEAN DEFAULT FALSE,
    memo_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Table: pe_scenarios
CREATE TABLE pe_scenarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    scenario_name VARCHAR(255) NOT NULL,
    pre_money DECIMAL(18,2) DEFAULT 0,
    post_money DECIMAL(18,2) DEFAULT 0,
    exit_value DECIMAL(18,2) DEFAULT 0,
    cap_rate DECIMAL(18,2) DEFAULT 0,
    option_pool_pct DECIMAL(10,6) DEFAULT 0,
    participation_cap DECIMAL(10,2) DEFAULT 2,
    trigger_tiers BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Table: pe_results
CREATE TABLE pe_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cap_table_id INT NOT NULL,
    scenario_id INT NOT NULL,
    proceeds DECIMAL(18,2) DEFAULT 0,
    roi DECIMAL(10,4) DEFAULT 0,
    irr DECIMAL(10,4) DEFAULT 0,
    tier_triggered VARCHAR(32),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
