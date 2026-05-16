-- Miracle Morning safe schema only. No client/member/payment/visitor data included.
-- Legacy compatibility note: transactions.friday_date stores the Sunday meeting/report date.

CREATE TABLE IF NOT EXISTS members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    company_name VARCHAR(200) DEFAULT NULL,
    category VARCHAR(150) DEFAULT NULL,
    mobile VARCHAR(30) DEFAULT NULL,
    email VARCHAR(190) DEFAULT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_member_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT DEFAULT NULL,
    type ENUM('Member','Visitor','Observer') NOT NULL,
    visitor_name VARCHAR(150) DEFAULT NULL,
    visitor_mobile VARCHAR(30) DEFAULT NULL,
    visitor_email VARCHAR(190) DEFAULT NULL,
    visitor_company VARCHAR(200) DEFAULT NULL,
    visitor_profession VARCHAR(200) DEFAULT NULL,
    referrer_name VARCHAR(150) DEFAULT NULL,
    observer_chapter VARCHAR(150) DEFAULT NULL,
    observer_category VARCHAR(150) DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(50) DEFAULT NULL,
    friday_date DATE NOT NULL,
    status ENUM('Pending','Paid','Rejected') NOT NULL DEFAULT 'Pending',
    business_card VARCHAR(255) DEFAULT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_at DATETIME DEFAULT NULL,
    is_partial TINYINT(1) NOT NULL DEFAULT 0,
    partial_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
    partial_balance DECIMAL(10,2) NOT NULL DEFAULT 0,
    original_total DECIMAL(10,2) DEFAULT NULL,
    INDEX idx_member_type_date (member_id, type, friday_date),
    INDEX idx_type_status_date (type, status, friday_date),
    INDEX idx_submitted_at (submitted_at),
    CONSTRAINT fk_transactions_member
        FOREIGN KEY (member_id) REFERENCES members(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kitty_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(50) DEFAULT NULL,
    status ENUM('Pending','Paid','Rejected') NOT NULL DEFAULT 'Pending',
    notes TEXT DEFAULT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_at DATETIME DEFAULT NULL,
    INDEX idx_kitty_member_status (member_id, status),
    CONSTRAINT fk_kitty_member
        FOREIGN KEY (member_id) REFERENCES members(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS visitor_dues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT DEFAULT NULL,
    txn_id INT DEFAULT NULL,
    visitor_name VARCHAR(150) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('Pending','Paid','Rejected') NOT NULL DEFAULT 'Pending',
    paid_at DATETIME DEFAULT NULL,
    payment_method VARCHAR(50) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_visitor_dues_member_status (member_id, status),
    INDEX idx_visitor_dues_txn (txn_id),
    CONSTRAINT fk_visitor_dues_member
        FOREIGN KEY (member_id) REFERENCES members(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_visitor_dues_txn
        FOREIGN KEY (txn_id) REFERENCES transactions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS visitor_completion (
    txn_id INT PRIMARY KEY,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_visitor_completion_txn
        FOREIGN KEY (txn_id) REFERENCES transactions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_role VARCHAR(20) NOT NULL,
    user_name VARCHAR(100) NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    target_id INT DEFAULT NULL,
    ip_address VARCHAR(45),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action (action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
