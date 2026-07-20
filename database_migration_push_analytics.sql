-- Migration: Push Subscriptions & Parent Portal Analytics
-- Run this in phpMyAdmin or via CLI against the exbelt database.

-- ============================================================
-- Push Subscriptions (for leader PWA notifications)
-- ============================================================

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    leader_id INT UNSIGNED NOT NULL,
    endpoint VARCHAR(500) NOT NULL,
    p256dh_key VARCHAR(255) NOT NULL,
    auth_key VARCHAR(255) NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_push_leader_endpoint (leader_id, endpoint(191)),
    INDEX idx_push_leader (leader_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Parent Portal Visits (for engagement analytics)
-- ============================================================

CREATE TABLE IF NOT EXISTS parent_portal_visits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    token VARCHAR(128) NOT NULL,
    page VARCHAR(100) NOT NULL DEFAULT 'portal',
    visited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_hash VARCHAR(64) NULL COMMENT 'SHA-256 hash of IP for unique visitor estimation',
    user_agent_hash VARCHAR(64) NULL COMMENT 'SHA-256 hash of UA for unique visitor estimation',
    INDEX idx_ppv_team (team_id),
    INDEX idx_ppv_visited (visited_at),
    INDEX idx_ppv_page (page),
    INDEX idx_ppv_team_date (team_id, visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Push Notification Log (optional, for debugging/analytics)
-- ============================================================

CREATE TABLE IF NOT EXISTS push_notification_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT UNSIGNED NULL,
    leader_id INT UNSIGNED NULL,
    event_type VARCHAR(50) NOT NULL DEFAULT 'checkin_submitted',
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    url VARCHAR(500) NULL,
    status ENUM('sent', 'failed', 'expired') NOT NULL DEFAULT 'sent',
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pnl_leader (leader_id),
    INDEX idx_pnl_created (created_at),
    INDEX idx_pnl_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
