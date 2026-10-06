BEGIN;

CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    agent_id VARCHAR(100) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    password_hash TEXT NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'agent'
        CHECK (role IN ('admin','agent','supervisor')),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    lob VARCHAR(100) NULL CHECK (lob IS NULL OR lob IN (
        'Sales', 'Collection', 'Backend',
        'Sales, Collection', 'Sales, Backend', 'Collection, Backend',
        'Sales, Collection, Backend'
    )),
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
    lob VARCHAR(100) NULL CHECK (lob IS NULL OR lob IN (
        'Sales', 'Collection', 'Backend',
        'Sales, Collection', 'Sales, Backend', 'Collection, Backend',
        'Sales, Collection, Backend'
    )),
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

CREATE TABLE IF NOT EXISTS agent_leave_balances (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    leave_year SMALLINT NOT NULL CHECK (leave_year BETWEEN 2000 AND 2100),
    annual_allotment NUMERIC(8,2) NULL CHECK (annual_allotment IS NULL OR annual_allotment >= 0),
    grand_total NUMERIC(8,2) NOT NULL DEFAULT 0 CHECK (grand_total >= 0),
    january NUMERIC(8,2) NOT NULL DEFAULT 0 CHECK (january >= 0),
    february NUMERIC(8,2) NOT NULL DEFAULT 0 CHECK (february >= 0),
    march NUMERIC(8,2) NOT NULL DEFAULT 0 CHECK (march >= 0),
    april NUMERIC(8,2) NOT NULL DEFAULT 0 CHECK (april >= 0),
    may NUMERIC(8,2) NOT NULL DEFAULT 0 CHECK (may >= 0),
    june NUMERIC(8,2) NOT NULL DEFAULT 0 CHECK (june >= 0),
    july NUMERIC(8,2) NOT NULL DEFAULT 0 CHECK (july >= 0),
    august NUMERIC(8,2) NOT NULL DEFAULT 0 CHECK (august >= 0),
    september NUMERIC(8,2) NOT NULL DEFAULT 0 CHECK (september >= 0),
    october NUMERIC(8,2) NOT NULL DEFAULT 0 CHECK (october >= 0),
    november NUMERIC(8,2) NOT NULL DEFAULT 0 CHECK (november >= 0),
    december NUMERIC(8,2) NOT NULL DEFAULT 0 CHECK (december >= 0),
    source_name VARCHAR(150) NULL,
    employee_status VARCHAR(30) NULL,
    process_name VARCHAR(150) NULL,
    date_of_joining DATE NULL,
    tenure_days INTEGER NULL CHECK (tenure_days IS NULL OR tenure_days >= 0),
    month_count SMALLINT NULL CHECK (month_count IS NULL OR month_count >= 0),
    total_cl NUMERIC(8,2) NULL CHECK (total_cl IS NULL OR total_cl >= 0),
    total_el NUMERIC(8,2) NULL CHECK (total_el IS NULL OR total_el >= 0),
    cl_used NUMERIC(8,2) NULL CHECK (cl_used IS NULL OR cl_used >= 0),
    el_used NUMERIC(8,2) NULL CHECK (el_used IS NULL OR el_used >= 0),
    cl_in_bucket NUMERIC(8,2) NULL CHECK (cl_in_bucket IS NULL OR cl_in_bucket >= 0),
    el_in_bucket NUMERIC(8,2) NULL CHECK (el_in_bucket IS NULL OR el_in_bucket >= 0),
    total_leaves_in_bucket NUMERIC(8,2) NULL CHECK (total_leaves_in_bucket IS NULL OR total_leaves_in_bucket >= 0),
    uploaded_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, leave_year)
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
