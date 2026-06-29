-- D&J Webmail — full schema (Milestone 1+)
-- Run: mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS dj_webmail
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE dj_webmail;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
    active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    signature TEXT NULL,
    preferences JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS folders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    imap_path VARCHAR(255) NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    folder_type ENUM('inbox', 'employee', 'client', 'spam', 'trash', 'sent', 'other') NOT NULL DEFAULT 'other',
    linked_user_id INT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_folders_imap_path (imap_path),
    CONSTRAINT fk_folders_user FOREIGN KEY (linked_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS aliases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    user_id INT UNSIGNED NULL,
    default_folder_id INT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_aliases_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_aliases_folder FOREIGN KEY (default_folder_id) REFERENCES folders(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS filter_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    priority INT NOT NULL DEFAULT 100,
    active TINYINT(1) NOT NULL DEFAULT 1,
    rule_type ENUM('spam', 'employee', 'client', 'company') NOT NULL,
    condition_field ENUM('to', 'from', 'subject', 'body', 'from_domain') NOT NULL,
    condition_operator ENUM('equals', 'contains', 'ends_with', 'starts_with') NOT NULL,
    condition_value VARCHAR(255) NOT NULL,
    target_folder_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_filter_rules_folder FOREIGN KEY (target_folder_id) REFERENCES folders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS processed_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    imap_uid INT UNSIGNED NOT NULL,
    folder_path VARCHAR(255) NOT NULL,
    message_id VARCHAR(255) NULL,
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_processed_uid_folder (imap_uid, folder_path),
    INDEX idx_processed_folder_time (folder_path, processed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50) NOT NULL DEFAULT '',
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_ip_time (ip_address, attempted_at),
    INDEX idx_login_attempts_user_time (username, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mail_index (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folder_path VARCHAR(255) NOT NULL,
    imap_uid INT UNSIGNED NOT NULL,
    from_addr VARCHAR(512) NOT NULL DEFAULT '',
    to_addrs TEXT NULL,
    cc_addrs TEXT NULL,
    subject VARCHAR(998) NOT NULL DEFAULT '',
    msg_date DATETIME NULL,
    seen TINYINT(1) NOT NULL DEFAULT 0,
    flagged TINYINT(1) NOT NULL DEFAULT 0,
    has_attachment TINYINT(1) NOT NULL DEFAULT 0,
    size INT UNSIGNED NOT NULL DEFAULT 0,
    synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mail_index_folder_uid (folder_path, imap_uid),
    INDEX idx_mail_index_folder_date (folder_path, msg_date DESC, imap_uid DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_bodies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folder_path VARCHAR(255) NOT NULL,
    imap_uid INT UNSIGNED NOT NULL,
    from_addr VARCHAR(512) NOT NULL DEFAULT '',
    to_addrs TEXT NULL,
    cc_addrs TEXT NULL,
    subject VARCHAR(998) NOT NULL DEFAULT '',
    msg_date DATETIME NULL,
    delivered_to VARCHAR(255) NULL,
    message_id VARCHAR(255) NULL,
    html_body MEDIUMTEXT NULL,
    plain_body MEDIUMTEXT NULL,
    attachments_json JSON NULL,
    cached_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mail_bodies_folder_uid (folder_path, imap_uid),
    INDEX idx_mail_bodies_folder (folder_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_sync_state (
    folder_path VARCHAR(255) NOT NULL PRIMARY KEY,
    imap_total INT UNSIGNED NOT NULL DEFAULT 0,
    headers_cached INT UNSIGNED NOT NULL DEFAULT 0,
    last_sync_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
