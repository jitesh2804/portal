BEGIN;

CREATE TABLE IF NOT EXISTS agent_leave_balances (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    leave_year SMALLINT NOT NULL CHECK (leave_year BETWEEN 2000 AND 2100),
    total_entitlement NUMERIC(8,2) NOT NULL CHECK (total_entitlement >= 0),
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
    uploaded_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, leave_year)
);

COMMIT;
