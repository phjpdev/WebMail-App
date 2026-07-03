-- Display-only sidebar grouping: a folder can be shown nested under another
-- folder in the sidebar without moving the IMAP mailbox. NULL = top level.
-- Run: mysql -u root dj_webmail < database/migrations-folder-display-parent.sql

USE dj_webmail;

ALTER TABLE folders
    ADD COLUMN display_parent_id INT UNSIGNED NULL DEFAULT NULL AFTER linked_user_id,
    ADD CONSTRAINT fk_folders_display_parent
        FOREIGN KEY (display_parent_id) REFERENCES folders(id) ON DELETE SET NULL;
