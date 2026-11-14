-- Son-sheet gross on the partial-payment review table only.
-- Safe to inspect. The page adds this column only when it is missing.
-- It does not change payroll_finalized, payroll_payments, or payroll_balance.

ALTER TABLE admin_partial_payment_reviews
    ADD COLUMN son_sheet_gross DECIMAL(12,2) NULL DEFAULT NULL AFTER period;
