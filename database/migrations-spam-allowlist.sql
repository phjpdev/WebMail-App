-- Per-sender spam allow-list: senders whose mail should never stay in Junk.
-- Populated when a user rescues a message from Junk into the Inbox; FilterService
-- then auto-rescues (un-spams) their future mail out of Junk.
-- mysql -u root -p dj_webmail < database/migrations-spam-allowlist.sql

USE dj_webmail;

CREATE TABLE IF NOT EXISTS spam_allowlist (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_spam_allowlist_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
