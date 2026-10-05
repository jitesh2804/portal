-- Existing users must be assigned a LOB by an admin after this migration.
-- Historical sessions stay unassigned: their original LOB is unknown.
BEGIN;
ALTER TABLE users ADD COLUMN IF NOT EXISTS lob VARCHAR(20) NULL
    CHECK (lob IN ('Sales', 'Collection', 'Backend'));
ALTER TABLE agent_sessions ADD COLUMN IF NOT EXISTS lob VARCHAR(20) NULL
    CHECK (lob IN ('Sales', 'Collection', 'Backend'));
COMMIT;
