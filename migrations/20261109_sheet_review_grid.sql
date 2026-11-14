-- Additive columns for the Sheet Review spreadsheet.
-- Does not change payroll_finalized, payroll_payments, users, or attendance.
-- Safe to run more than once only if the columns are not already present.
-- The application also adds missing columns on open.

ALTER TABLE payroll_sheet_rows ADD COLUMN deleted_at DATETIME NULL;
ALTER TABLE payroll_sheet_rows ADD COLUMN row_version INT NOT NULL DEFAULT 1;
ALTER TABLE payroll_sheet_plan_lines ADD COLUMN period_start DATE NULL;
ALTER TABLE payroll_sheet_plan_lines ADD COLUMN period_end DATE NULL;
ALTER TABLE payroll_sheet_plan_lines ADD COLUMN line_effect VARCHAR(20) NOT NULL DEFAULT 'payout';
ALTER TABLE payroll_sheet_plan_lines ADD COLUMN approved_at DATETIME NULL;
ALTER TABLE payroll_sheet_plan_lines ADD COLUMN approved_by INT NULL;
ALTER TABLE payroll_sheet_plan_lines ADD COLUMN row_version INT NOT NULL DEFAULT 1;
ALTER TABLE payroll_sheet_plan_lines ADD COLUMN pay_on DATE NULL;
ALTER TABLE payroll_sheet_plans ADD COLUMN proposal_key VARCHAR(64) NULL;
ALTER TABLE payroll_sheet_plans ADD COLUMN suggest_policy VARCHAR(40) NULL;
