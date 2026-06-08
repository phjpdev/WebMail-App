-- Seed users for Milestone 1
-- Default passwords: admin123 / employee123 (change immediately after setup)

USE dj_webmail;

INSERT INTO users (name, username, password_hash, role, active) VALUES
(
    'Administrator',
    'admin',
    '$2b$10$9tb6vHRvc1sxQkCsFDx5ueeBSUfavzBZUrwoYv/bppIxbdSsTHfvO',
    'admin',
    1
),
(
    'Test Employee',
    'employee',
    '$2b$10$7C8ubzO2hPe7c4BXb4ocA.Ny2B/D9QZsVS40wIgrXYC60T1.rUSWG',
    'employee',
    1
);
