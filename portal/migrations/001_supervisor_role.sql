-- Run once on existing installations before creating supervisor accounts.
BEGIN;
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE users ADD CONSTRAINT users_role_check
    CHECK (role IN ('admin', 'agent', 'supervisor'));
COMMIT;
