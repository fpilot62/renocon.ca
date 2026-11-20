-- Who created or changed an attendance row.
-- Adds attendance_audit, plus nullable created_by and updated_by on attendance.
-- Safe to run more than once. Does not rewrite existing punches.
-- Run on the payroll database after a backup.

CREATE TABLE IF NOT EXISTS attendance_audit (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL,
  org_id INT NULL,
  site_id INT NULL,
  target_user_id INT NULL,
  action ENUM('create','update','delete','finalize','unfinalize') NOT NULL,
  actor_user_id INT NULL,
  actor_name VARCHAR(191) NULL,
  actor_role VARCHAR(64) NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  source_page VARCHAR(191) NULL,
  before_json MEDIUMTEXT NULL,
  after_json MEDIUMTEXT NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_attendance_audit_attendance (attendance_id),
  KEY idx_attendance_audit_target (target_user_id),
  KEY idx_attendance_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'created_by') = 0,
    'ALTER TABLE attendance ADD COLUMN created_by INT NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE att_audit_created_by FROM @sql;
EXECUTE att_audit_created_by;
DEALLOCATE PREPARE att_audit_created_by;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'updated_by') = 0,
    'ALTER TABLE attendance ADD COLUMN updated_by INT NULL DEFAULT NULL',
    'SELECT 1'
  )
);
PREPARE att_audit_updated_by FROM @sql;
EXECUTE att_audit_updated_by;
DEALLOCATE PREPARE att_audit_updated_by;
