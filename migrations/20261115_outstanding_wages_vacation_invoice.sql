-- Vacation pay and pay-as-invoice on the outstanding wages ledger.
-- Safe to run more than once. Adds columns only when they are missing.
-- Does not change wage, payment, attendance, or pay-run tables.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'admin_outstanding_wages'
        AND COLUMN_NAME = 'vacation_pay') = 0,
    'ALTER TABLE admin_outstanding_wages ADD COLUMN vacation_pay DECIMAL(12,2) NULL DEFAULT NULL AFTER gross',
    'SELECT 1'
  )
);
PREPARE ow_mig_vacation_pay FROM @sql;
EXECUTE ow_mig_vacation_pay;
DEALLOCATE PREPARE ow_mig_vacation_pay;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db
        AND TABLE_NAME = 'admin_outstanding_wages'
        AND COLUMN_NAME = 'pay_as_invoice') = 0,
    'ALTER TABLE admin_outstanding_wages ADD COLUMN pay_as_invoice TINYINT(1) NOT NULL DEFAULT 0 AFTER vacation_pay',
    'SELECT 1'
  )
);
PREPARE ow_mig_pay_as_invoice FROM @sql;
EXECUTE ow_mig_pay_as_invoice;
DEALLOCATE PREPARE ow_mig_pay_as_invoice;
