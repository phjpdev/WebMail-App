-- Folder registry + starter filter rules (Milestone 3)
-- Adjust rules to match client's Thunderbird VM before go-live.

USE dj_webmail;

INSERT INTO folders (imap_path, display_name, folder_type, active) VALUES
('INBOX', 'Inbox', 'inbox', 1),
('INBOX.Sent', 'Sent', 'sent', 1),
('INBOX.Drafts', 'Drafts', 'other', 1),
('INBOX.Spam', 'Spam', 'spam', 1),
('INBOX.Trash', 'Trash', 'trash', 1),
('INBOX.support', 'Support', 'employee', 1),
('INBOX.Archive', 'Archive', 'other', 1),
('INBOX.Junk', 'Junk', 'spam', 1)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), folder_type = VALUES(folder_type), active = 1;

UPDATE aliases a
INNER JOIN folders f ON f.imap_path = 'INBOX.support'
SET a.default_folder_id = f.id
WHERE a.email = 'support@bebenailsmd.com';

INSERT INTO filter_rules (name, priority, active, rule_type, condition_field, condition_operator, condition_value, target_folder_id)
SELECT 'Spam sender block', 10, 1, 'spam', 'from', 'contains', 'noreply@spam.com', f.id
FROM folders f WHERE f.imap_path = 'INBOX.Spam'
AND NOT EXISTS (SELECT 1 FROM filter_rules WHERE name = 'Spam sender block');

INSERT INTO filter_rules (name, priority, active, rule_type, condition_field, condition_operator, condition_value, target_folder_id)
SELECT 'Spam keyword', 20, 1, 'spam', 'subject', 'contains', 'viagra', f.id
FROM folders f WHERE f.imap_path = 'INBOX.Spam'
AND NOT EXISTS (SELECT 1 FROM filter_rules WHERE name = 'Spam keyword');

INSERT INTO filter_rules (name, priority, active, rule_type, condition_field, condition_operator, condition_value, target_folder_id)
SELECT 'Route support alias', 30, 1, 'employee', 'to', 'equals', 'support@bebenailsmd.com', f.id
FROM folders f WHERE f.imap_path = 'INBOX.support'
AND NOT EXISTS (SELECT 1 FROM filter_rules WHERE name = 'Route support alias');
