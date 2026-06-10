-- Phase 2 schema updates
-- Run: mysql -u root -p dj_webmail < database/migrations-phase2.sql

USE dj_webmail;

ALTER TABLE users
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER active,
    ADD COLUMN signature TEXT NULL AFTER must_change_password,
    ADD COLUMN preferences JSON NULL AFTER signature;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50) NOT NULL DEFAULT '',
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_ip_time (ip_address, attempted_at),
    INDEX idx_login_attempts_user_time (username, attempted_at)
) ENGINE=InnoDB;

ALTER TABLE processed_messages
    ADD INDEX idx_processed_folder_time (folder_path, processed_at);
