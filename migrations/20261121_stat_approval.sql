-- Stat lines need approval before they can be paid.
-- Adds the audit columns, then marks unpaid Statutory Holiday lines as
-- 'needs approval' with calculated_amount equal to the current amount.
-- Already-paid stat lines are left as they are.
-- Dry-run count (run before this file):
--   SELECT COUNT(*) AS unpaid_stat_to_pending
--     FROM payroll_finalized
--    WHERE category = 'Statutory Holiday'
--      AND COALESCE(paid_amount, 0) <= 0.02
--      AND LOWER(COALESCE(status, '')) NOT IN ('paid', 'remitted', 'needs approval');

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

UPDATE payroll_finalized
   SET status = 'needs approval',
       stat_calculated_amount = total_gross
 WHERE category = 'Statutory Holiday'
   AND COALESCE(paid_amount, 0) <= 0.02
   AND LOWER(COALESCE(status, '')) NOT IN ('paid', 'remitted', 'needs approval')
   AND stat_calculated_amount IS NULL;
