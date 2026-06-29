-- Add recipient columns to mail_index so correspondent-folder privacy filtering
-- and unread badges can match on To/Cc without first caching the full body.
-- Safe to run multiple times.

ALTER TABLE mail_index
    ADD COLUMN to_addrs TEXT NULL AFTER from_addr,
    ADD COLUMN cc_addrs TEXT NULL AFTER to_addrs;
