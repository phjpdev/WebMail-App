-- Add message_id to mail_index for faster duplicate lookups (optional).
-- mysql -u root -p dj_webmail < database/migrations-mail-index-message-id.sql

USE dj_webmail;

ALTER TABLE mail_index
    ADD COLUMN IF NOT EXISTS message_id VARCHAR(255) NULL AFTER size,
    ADD INDEX IF NOT EXISTS idx_mail_index_message_id (message_id);
