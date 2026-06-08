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
    access_code_hash VARCHAR(255) NULL,
    role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
    active TINYINT(1) NOT NULL DEFAULT 1,
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
    UNIQUE KEY uq_processed_uid_folder (imap_uid, folder_path)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
