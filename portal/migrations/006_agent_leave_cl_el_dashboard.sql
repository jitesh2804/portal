BEGIN;

ALTER TABLE agent_leave_balances
    ADD COLUMN IF NOT EXISTS month_count SMALLINT NULL CHECK (month_count IS NULL OR month_count >= 0),
    ADD COLUMN IF NOT EXISTS total_cl NUMERIC(8,2) NULL CHECK (total_cl IS NULL OR total_cl >= 0),
    ADD COLUMN IF NOT EXISTS total_el NUMERIC(8,2) NULL CHECK (total_el IS NULL OR total_el >= 0),
    ADD COLUMN IF NOT EXISTS cl_used NUMERIC(8,2) NULL CHECK (cl_used IS NULL OR cl_used >= 0),
    ADD COLUMN IF NOT EXISTS el_used NUMERIC(8,2) NULL CHECK (el_used IS NULL OR el_used >= 0),
    ADD COLUMN IF NOT EXISTS cl_in_bucket NUMERIC(8,2) NULL CHECK (cl_in_bucket IS NULL OR cl_in_bucket >= 0),
    ADD COLUMN IF NOT EXISTS el_in_bucket NUMERIC(8,2) NULL CHECK (el_in_bucket IS NULL OR el_in_bucket >= 0),
    ADD COLUMN IF NOT EXISTS total_leaves_in_bucket NUMERIC(8,2) NULL CHECK (total_leaves_in_bucket IS NULL OR total_leaves_in_bucket >= 0);

COMMIT;
