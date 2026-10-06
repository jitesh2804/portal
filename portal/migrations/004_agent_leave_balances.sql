BEGIN;

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

COMMIT;
