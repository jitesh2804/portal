BEGIN;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND table_name = 'agent_leave_balances'
           AND column_name = 'total_entitlement'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND table_name = 'agent_leave_balances'
           AND column_name = 'annual_allotment'
    ) THEN
        ALTER TABLE agent_leave_balances RENAME COLUMN total_entitlement TO annual_allotment;
    END IF;
END $$;

ALTER TABLE agent_leave_balances
    ALTER COLUMN annual_allotment DROP NOT NULL;

ALTER TABLE agent_leave_balances
    ADD COLUMN IF NOT EXISTS grand_total NUMERIC(8,2) NOT NULL DEFAULT 0
    CHECK (grand_total >= 0);

UPDATE agent_leave_balances
   SET grand_total = january + february + march + april + may + june
                  + july + august + september + october + november + december
 WHERE grand_total = 0;

COMMIT;
