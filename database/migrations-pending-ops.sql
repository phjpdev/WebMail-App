-- Journal of deferred move/delete operations. Written BEFORE the instant
-- response; the background worker marks each op done/failed. Gives the client
-- a truthful completion signal (stateful toast) and lets FilterService resume
-- operations whose PHP process died mid-run (crash-safe bulk deletes).
-- mysql -u root -p dj_webmail < database/migrations-pending-ops.sql

USE dj_webmail;

CREATE TABLE IF NOT EXISTS mail_pending_ops (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    op_type VARCHAR(16) NOT NULL,
    source_json TEXT NOT NULL,
    target_folder VARCHAR(255) NULL,
    message_ids_json TEXT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    detail VARCHAR(512) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pending_ops_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
