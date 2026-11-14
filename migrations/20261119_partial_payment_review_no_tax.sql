-- No-income-tax choice on the partial-payment review table only.
-- The page adds this column only when it is missing.
-- It does not change payroll payment or balance tables.

ALTER TABLE admin_partial_payment_reviews
    ADD COLUMN skip_income_tax TINYINT(1) NULL DEFAULT NULL AFTER son_sheet_gross;
