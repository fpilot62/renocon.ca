-- Soft-delete attendance instead of removing the row.
-- Records who removed it and when. Safe to run more than once.
-- Run on the payroll database after a backup, before copying the PHP files.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'deleted_at') = 0,
    'ALTER TABLE attendance ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE att_mig_deleted_at FROM @sql;
EXECUTE att_mig_deleted_at;
DEALLOCATE PREPARE att_mig_deleted_at;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'deleted_by') = 0,
    'ALTER TABLE attendance ADD COLUMN deleted_by INT NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE att_mig_deleted_by FROM @sql;
EXECUTE att_mig_deleted_by;
DEALLOCATE PREPARE att_mig_deleted_by;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'attendance' AND INDEX_NAME = 'idx_attendance_deleted_at') = 0,
    'ALTER TABLE attendance ADD INDEX idx_attendance_deleted_at (deleted_at)',
    'SELECT 1'
  )
);
PREPARE att_mig_deleted_idx FROM @sql;
EXECUTE att_mig_deleted_idx;
DEALLOCATE PREPARE att_mig_deleted_idx;
