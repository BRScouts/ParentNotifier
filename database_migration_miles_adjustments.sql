-- Migration: Manual miles adjustments table
-- Allows leaders to backfill on-foot miles for teams (e.g. prior days before the system was live).
-- Run this in phpMyAdmin or via CLI against the exbelt database.

CREATE TABLE IF NOT EXISTS team_miles_adjustments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    miles DECIMAL(6,1) NOT NULL,
    note VARCHAR(255) NULL DEFAULT NULL,
    added_by INT UNSIGNED NULL COMMENT 'leader_id who added this',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tma_team (team_id),
    INDEX idx_tma_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
