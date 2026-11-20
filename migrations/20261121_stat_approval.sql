-- Stat lines wait for approval before they can be paid.
-- Approval is stored in stat_approval_status, not in payroll_finalized.status,
-- because status is an enum (or a short string) on the live database.
-- Unpaid Statutory Holiday lines become stat_approval_status 'pending'
-- with calculated_amount equal to the current amount. Their status value
-- is left alone, so pending/Unpaid still means unpaid for every category.
-- Already-paid stat lines are left as they are.
-- Safe to run again after a partial apply: every column add is skipped
-- when information_schema already has it, and the UPDATE only fills rows
-- that do not yet have an approval value.
-- Dry-run count (does not need the new column):
--   SELECT COUNT(*) AS unpaid_stat_to_pending
--     FROM payroll_finalized
--    WHERE category = 'Statutory Holiday'
--      AND COALESCE(paid_amount, 0) <= 0.02
--      AND LOWER(COALESCE(status, '')) NOT IN ('paid', 'remitted');

SET @db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='stat_calculated_amount')=0,
  'ALTER TABLE payroll_finalized ADD COLUMN stat_calculated_amount DECIMAL(10,2) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='stat_approved_amount')=0,
  'ALTER TABLE payroll_finalized ADD COLUMN stat_approved_amount DECIMAL(10,2) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='stat_approved_by')=0,
  'ALTER TABLE payroll_finalized ADD COLUMN stat_approved_by INT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='stat_approved_at')=0,
  'ALTER TABLE payroll_finalized ADD COLUMN stat_approved_at DATETIME NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='stat_edited_by')=0,
  'ALTER TABLE payroll_finalized ADD COLUMN stat_edited_by INT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='stat_edit_reason')=0,
  'ALTER TABLE payroll_finalized ADD COLUMN stat_edit_reason VARCHAR(255) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='stat_flag')=0,
  'ALTER TABLE payroll_finalized ADD COLUMN stat_flag VARCHAR(255) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='stat_approval_status')=0,
  'ALTER TABLE payroll_finalized ADD COLUMN stat_approval_status VARCHAR(20) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE payroll_finalized
   SET stat_approval_status = 'pending',
       stat_calculated_amount = IFNULL(stat_calculated_amount, total_gross)
 WHERE category = 'Statutory Holiday'
   AND COALESCE(paid_amount, 0) <= 0.02
   AND LOWER(COALESCE(status, '')) NOT IN ('paid', 'remitted')
   AND (stat_approval_status IS NULL OR stat_approval_status = '');
