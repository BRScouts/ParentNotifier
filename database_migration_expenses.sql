-- ============================================================
-- Migration: Team Expense / Receipt Tracking System
-- Run this against the exbelt database
-- ============================================================

-- Team travel cards (one per team, stores card meta data)
CREATE TABLE IF NOT EXISTS team_cards (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    leader_name VARCHAR(150) NOT NULL COMMENT 'Name of leader the card is issued to (free text, not linked)',
    pin_number VARCHAR(20) NOT NULL COMMENT 'Card PIN',
    card_description VARCHAR(255) NOT NULL COMMENT 'e.g. Post Office, Asda, Revolut',
    initial_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Starting balance loaded onto card',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tc_team (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Transactions table (both debits from explorer expenses and credits from leader top-ups)
CREATE TABLE IF NOT EXISTS team_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    type ENUM('debit','credit') NOT NULL DEFAULT 'debit',
    amount DECIMAL(10,2) NOT NULL COMMENT 'Always positive; type indicates direction',
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    category VARCHAR(50) NULL COMMENT 'food, camping, supplies, travel, other (for debits)',
    description VARCHAR(500) NULL COMMENT 'Free text note about the transaction',
    receipt_path VARCHAR(500) NULL COMMENT 'Path to uploaded receipt image (debits only)',
    submitted_by VARCHAR(150) NOT NULL COMMENT 'Name of person submitting',
    transaction_date DATE NOT NULL COMMENT 'Date the purchase/top-up occurred',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_leader_id INT UNSIGNED NULL COMMENT 'Set if a leader added this (credits/adjustments)',
    INDEX idx_tt_team (team_id),
    INDEX idx_tt_type (type),
    INDEX idx_tt_date (transaction_date),
    INDEX idx_tt_team_date (team_id, transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
