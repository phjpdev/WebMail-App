-- Reply-chain headers on mail_index for Gmail-style conversation threading.
-- Groups a conversation by the actual reply relationship (Message-ID / In-Reply-To
-- / References) instead of by subject, so separate emails that reuse a subject
-- (e.g. two different "Test" emails) no longer merge.
-- mysql -u root -p dj_webmail < database/migrations-mail-index-threading.sql

USE dj_webmail;

ALTER TABLE mail_index
    ADD COLUMN IF NOT EXISTS message_id VARCHAR(255) NULL AFTER size,
    ADD COLUMN IF NOT EXISTS in_reply_to VARCHAR(255) NULL AFTER message_id,
    ADD COLUMN IF NOT EXISTS references_ids TEXT NULL AFTER in_reply_to,
    ADD INDEX IF NOT EXISTS idx_mail_index_message_id (message_id);
