BEGIN;

ALTER TABLE users ADD COLUMN IF NOT EXISTS lob VARCHAR(100) NULL;
ALTER TABLE agent_sessions ADD COLUMN IF NOT EXISTS lob VARCHAR(100) NULL;

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_lob_check;
ALTER TABLE agent_sessions DROP CONSTRAINT IF EXISTS agent_sessions_lob_check;

ALTER TABLE users ALTER COLUMN lob TYPE VARCHAR(100);
ALTER TABLE agent_sessions ALTER COLUMN lob TYPE VARCHAR(100);

ALTER TABLE users
    ADD CONSTRAINT users_lob_check CHECK (lob IS NULL OR lob IN (
        'Sales', 'Collection', 'Backend',
        'Sales, Collection', 'Sales, Backend', 'Collection, Backend',
        'Sales, Collection, Backend'
    ));
ALTER TABLE agent_sessions
    ADD CONSTRAINT agent_sessions_lob_check CHECK (lob IS NULL OR lob IN (
        'Sales', 'Collection', 'Backend',
        'Sales, Collection', 'Sales, Backend', 'Collection, Backend',
        'Sales, Collection, Backend'
    ));

COMMIT;
