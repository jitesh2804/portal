BEGIN;

CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    agent_id VARCHAR(100) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    password_hash TEXT NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'agent'
        CHECK (role IN ('admin','agent','supervisor')),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    lob VARCHAR(20) NULL CHECK (lob IN ('Sales', 'Collection', 'Backend')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS pause_codes (
    id BIGSERIAL PRIMARY KEY,
    code_name VARCHAR(100) NOT NULL UNIQUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS agent_sessions (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    login_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    logout_at TIMESTAMPTZ NULL,
    last_seen TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    login_ip INET NULL,
    lob VARCHAR(20) NULL CHECK (lob IN ('Sales', 'Collection', 'Backend')),
    user_agent TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_agent_sessions_user_login
    ON agent_sessions(user_id, login_at DESC);

CREATE TABLE IF NOT EXISTS activity_log (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    session_id BIGINT NOT NULL REFERENCES agent_sessions(id) ON DELETE CASCADE,
    activity_type VARCHAR(20) NOT NULL
        CHECK (activity_type IN ('IDLE','PAUSE')),
    pause_code_id BIGINT NULL REFERENCES pause_codes(id) ON DELETE SET NULL,
    start_time TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    end_time TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS idx_activity_user_time
    ON activity_log(user_id, start_time DESC);

CREATE INDEX IF NOT EXISTS idx_activity_open
    ON activity_log(session_id, end_time)
    WHERE end_time IS NULL;

INSERT INTO pause_codes (code_name)
VALUES ('Tea Break'), ('Lunch Break'), ('Meeting'), ('Personal Break')
ON CONFLICT (code_name) DO NOTHING;

COMMIT;

-- IMPORTANT:
-- Create first admin through create_admin.php from browser/CLI and delete that file afterwards.
