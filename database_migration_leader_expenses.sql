-- ============================================================
-- Migration: Leader Expenses (personal & corporate card tracking)
-- Run this against the exbelt database
-- ============================================================

CREATE TABLE IF NOT EXISTS leader_expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    leader_id INT UNSIGNED NOT NULL COMMENT 'Leader who incurred the expense',
    amount DECIMAL(10,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'GBP',
    category VARCHAR(50) NOT NULL COMMENT 'food, travel, accommodation, supplies, activities, admin, other',
    description VARCHAR(500) NULL,
    receipt_path VARCHAR(500) NULL COMMENT 'Path to uploaded receipt image/pdf',
    is_corporate_card TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = corporate card (no reimbursement needed), 0 = personal (needs reimbursement)',
    is_reimbursed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = paid back, 0 = outstanding',
    reimbursed_by_leader_id INT UNSIGNED NULL COMMENT 'Leader who marked this as paid',
    reimbursed_at DATETIME NULL,
    expense_date DATE NOT NULL COMMENT 'Date the expense was incurred',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_le_leader (leader_id),
    INDEX idx_le_corporate (is_corporate_card),
    INDEX idx_le_reimbursed (is_reimbursed),
    INDEX idx_le_date (expense_date),
    INDEX idx_le_leader_date (leader_id, expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
