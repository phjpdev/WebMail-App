-- Per-user read state for employee mailboxes (admin reads do not clear employee unread).
-- Run: mysql -u root -p dj_webmail < database/migrations-mail-user-read.sql

USE dj_webmail;

CREATE TABLE IF NOT EXISTS mail_user_read (
    user_id INT UNSIGNED NOT NULL,
    folder_path VARCHAR(255) NOT NULL,
    imap_uid INT UNSIGNED NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, folder_path, imap_uid),
    INDEX idx_mail_user_read_folder (folder_path, imap_uid),
    CONSTRAINT fk_mail_user_read_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
