-- Seed send-as aliases for Milestone 2
-- Add more rows here until Milestone 3 admin UI exists.

USE dj_webmail;

INSERT INTO aliases (email, display_name, active) VALUES
('support@bebenailsmd.com', 'Support', 1)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), active = 1;
