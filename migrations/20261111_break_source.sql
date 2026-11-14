-- Label rows in `breaks` so an automatic meal break can be shown and removed.
-- Existing rows stay 'manual'. 'auto' is the 30-minute Auto break.
-- Safe to run more than once. Does not change attendance or payroll totals by itself.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'breaks' AND COLUMN_NAME = 'source') = 0,
    'ALTER TABLE breaks ADD COLUMN `source` VARCHAR(16) NOT NULL DEFAULT ''manual''',
    'SELECT 1'
  )
);
PREPARE brk_mig_source FROM @sql;
EXECUTE brk_mig_source;
DEALLOCATE PREPARE brk_mig_source;
