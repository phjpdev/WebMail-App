-- Full-history browsing: true server totals, backfill watermark, badge floor.
-- server_total  = last known real IMAP message count (imap_total keeps its
--                 existing "local row count" semantics — badge code depends on it).
-- oldest_uid    = lowest UID the backfill has indexed (resume watermark).
-- backfill_done = 1 when the folder's full history is indexed.
-- mail_index.backfilled = 1 for rows written by the backfill (CLI or deep-page
--                 fetch); such rows never count toward sidebar unread badges.
-- Run against YOUR database (name varies per install):
--   mysql -u root -p YOUR_DB_NAME < database/migrations-mail-history.sql
-- Or use the browser runner: copy deploy/run-history-migration.php to the app
-- root and open it (it uses the app's own .env DB settings), then delete it.

ALTER TABLE mail_sync_state
    ADD COLUMN server_total INT UNSIGNED NULL DEFAULT NULL AFTER imap_total,
    ADD COLUMN oldest_uid INT UNSIGNED NULL DEFAULT NULL AFTER server_total,
    ADD COLUMN backfill_done TINYINT(1) NOT NULL DEFAULT 0 AFTER oldest_uid;

ALTER TABLE mail_index
    ADD COLUMN backfilled TINYINT(1) NOT NULL DEFAULT 0 AFTER seen;

-- Covering index for the sidebar badge GROUP BY: the scanned slice is only
-- (backfilled=0, seen=0) rows — the recent-window unread — even at millions
-- of backfilled rows.
ALTER TABLE mail_index
    ADD INDEX idx_mail_index_badge (backfilled, seen, folder_path);
