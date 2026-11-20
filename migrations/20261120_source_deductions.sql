-- T4127 source deductions. Additive only.
-- Adds claim columns, employer-cost columns, and rate / opening / period tables.
-- Does not drop, rewrite, or recalculate existing payroll rows.
-- Safe to run more than once. Run on the payroll database after a backup.
-- Then load the dated rate packs (inserts a row only when that date is missing):
--   php payroll_engine/seed_rates.php

SET @db := DATABASE();

-- users: TD1 and exemption flags. NULL claim means "no TD1 on file" (use the basic amount).

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='td1_federal_total')=0, 'ALTER TABLE users ADD COLUMN td1_federal_total DECIMAL(12,2) NULL', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='td1_provincial_total')=0, 'ALTER TABLE users ADD COLUMN td1_provincial_total DECIMAL(12,2) NULL', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='td1_tax_exempt')=0, 'ALTER TABLE users ADD COLUMN td1_tax_exempt TINYINT NOT NULL DEFAULT 0', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='td1_additional_tax')=0, 'ALTER TABLE users ADD COLUMN td1_additional_tax DECIMAL(12,2) NOT NULL DEFAULT 0', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='ontario_dependants')=0, 'ALTER TABLE users ADD COLUMN ontario_dependants INT NOT NULL DEFAULT 0', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='cpp_exempt')=0, 'ALTER TABLE users ADD COLUMN cpp_exempt TINYINT NOT NULL DEFAULT 0', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='ei_exempt')=0, 'ALTER TABLE users ADD COLUMN ei_exempt TINYINT NOT NULL DEFAULT 0', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='wsib_exempt')=0, 'ALTER TABLE users ADD COLUMN wsib_exempt TINYINT NOT NULL DEFAULT 0', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

-- organizations: Ontario EHT. Default is one eligible employer with the full $1,000,000 exemption.

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='organizations' AND COLUMN_NAME='eht_eligible')=0, 'ALTER TABLE organizations ADD COLUMN eht_eligible TINYINT NOT NULL DEFAULT 1', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='organizations' AND COLUMN_NAME='eht_exemption_amount')=0, 'ALTER TABLE organizations ADD COLUMN eht_exemption_amount DECIMAL(12,2) NOT NULL DEFAULT 1000000.00', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='organizations' AND COLUMN_NAME='eht_group_over_cap')=0, 'ALTER TABLE organizations ADD COLUMN eht_group_over_cap TINYINT NOT NULL DEFAULT 0', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

-- sites: province of employment and the employer-specific WSIB/WCB percent. A NULL percent means "not entered".

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sites' AND COLUMN_NAME='province')=0, 'ALTER TABLE sites ADD COLUMN province VARCHAR(32) NULL', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sites' AND COLUMN_NAME='wsib_percent')=0, 'ALTER TABLE sites ADD COLUMN wsib_percent DECIMAL(8,4) NULL', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

-- payroll_finalized: CPP2, EHT, and the earnings the T4 and ROE need. Existing amounts stay put.

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='cpp2')=0, 'ALTER TABLE payroll_finalized ADD COLUMN cpp2 DECIMAL(12,2) NOT NULL DEFAULT 0', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='cpp2_er')=0, 'ALTER TABLE payroll_finalized ADD COLUMN cpp2_er DECIMAL(12,2) NOT NULL DEFAULT 0', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='eht')=0, 'ALTER TABLE payroll_finalized ADD COLUMN eht DECIMAL(12,2) NOT NULL DEFAULT 0', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='pensionable_earnings')=0, 'ALTER TABLE payroll_finalized ADD COLUMN pensionable_earnings DECIMAL(12,2) NULL', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='insurable_earnings')=0, 'ALTER TABLE payroll_finalized ADD COLUMN insurable_earnings DECIMAL(12,2) NULL', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='deduction_edition')=0, 'ALTER TABLE payroll_finalized ADD COLUMN deduction_edition VARCHAR(80) NULL', 'SELECT 1'));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

CREATE TABLE IF NOT EXISTS payroll_rate_packs (
  id INT NOT NULL AUTO_INCREMENT,
  effective_from DATE NOT NULL,
  edition VARCHAR(120) NOT NULL,
  source_url VARCHAR(255) NOT NULL DEFAULT '',
  pack_json MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rate_effective (effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_deduction_openings (
  id INT NOT NULL AUTO_INCREMENT,
  org_id INT NOT NULL,
  user_id INT NOT NULL,
  tax_year INT NOT NULL,
  pensionable DECIMAL(12,2) NOT NULL DEFAULT 0,
  cpp DECIMAL(12,2) NOT NULL DEFAULT 0,
  cpp2 DECIMAL(12,2) NOT NULL DEFAULT 0,
  insurable DECIMAL(12,2) NOT NULL DEFAULT 0,
  ei DECIMAL(12,2) NOT NULL DEFAULT 0,
  gross DECIMAL(12,2) NOT NULL DEFAULT 0,
  assessable DECIMAL(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_opening (org_id, user_id, tax_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The live table is payroll_period_stat (one row per employee, site, and period).
-- These columns are added only when that table already exists. Nothing is rewritten.

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_period_stat')=0, 'SELECT 1', IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_period_stat' AND COLUMN_NAME='cpp2')=0, 'ALTER TABLE payroll_period_stat ADD COLUMN cpp2 DECIMAL(12,2) NOT NULL DEFAULT 0', 'SELECT 1')));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_period_stat')=0, 'SELECT 1', IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_period_stat' AND COLUMN_NAME='cpp2_er')=0, 'ALTER TABLE payroll_period_stat ADD COLUMN cpp2_er DECIMAL(12,2) NOT NULL DEFAULT 0', 'SELECT 1')));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;

SET @sql := (SELECT IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_period_stat')=0, 'SELECT 1', IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll_period_stat' AND COLUMN_NAME='deduction_edition')=0, 'ALTER TABLE payroll_period_stat ADD COLUMN deduction_edition VARCHAR(80) NULL', 'SELECT 1')));
PREPARE pe_mig FROM @sql; EXECUTE pe_mig; DEALLOCATE PREPARE pe_mig;
