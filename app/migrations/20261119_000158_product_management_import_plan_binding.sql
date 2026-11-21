-- One tenant batch may be bound to one immutable canonical Goal/Plan.
-- Planned batches stay 'staged' until their independently approved Action
-- begins. This migration never submits an Action or mutates Products.
ALTER TABLE pm_import_batches
 ADD COLUMN goal_id VARCHAR(80) NULL,
 ADD COLUMN plan_id VARCHAR(80) NULL;
CREATE UNIQUE INDEX uq_pm_import_plan_binding ON pm_import_batches
 (organization_id,plan_id);
