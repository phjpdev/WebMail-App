-- Mail cache (header index + body store) — run once on existing installs
-- mysql -u root -p dj_webmail < database/migrations-mail-cache.sql

USE dj_webmail;

CREATE TABLE IF NOT EXISTS mail_index (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folder_path VARCHAR(255) NOT NULL,
    imap_uid INT UNSIGNED NOT NULL,
    from_addr VARCHAR(512) NOT NULL DEFAULT '',
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
