-- ============================================================================
-- Phase 17 — data-quality fixes. Idempotent.
-- ============================================================================

-- 1) De-duplicate deal_stages (the seed had run repeatedly → 6 stages × N copies).
--    Deals only reference the canonical low ids; keep the first row per (pipeline,name).
DELETE ds FROM deal_stages ds
JOIN (
    SELECT MIN(id) keep_id, pipeline_id, name
    FROM deal_stages GROUP BY pipeline_id, name
) k ON k.pipeline_id = ds.pipeline_id AND k.name = ds.name AND ds.id > k.keep_id;

-- prevent it ever happening again
ALTER TABLE deal_stages ADD UNIQUE KEY IF NOT EXISTS uq_pipe_stage (pipeline_id, name);

-- 2) Reassign any leads owned by NON-sales staff (HR/IT/support) to the sales manager
--    for redistribution — lead auto-assign is now restricted to sales roles going forward.
UPDATE leads l
JOIN sales_users su ON su.employee_id = l.owner_id
SET l.owner_id = (
    SELECT su2.employee_id FROM sales_users su2
    WHERE su2.role='manager' AND su2.is_active=1 AND su2.employee_id IS NOT NULL
    ORDER BY su2.id LIMIT 1
)
WHERE su.role NOT IN ('sales_exec','manager','admin');
