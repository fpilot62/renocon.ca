-- Read-only. Run on admin_payroll as root and save the output as employees.csv:
--   mysql admin_payroll -B -e "$(cat employees_export.sql)" | sed 's/\t/,/g' > employees.csv   (names with commas need the python csv route; see README)
-- Better, tab separated, which match_names.py reads:
--   mysql admin_payroll -B < employees_export.sql > employees.tsv
-- One line per employee and store where they have finalized pay or attendance, plus employees with neither.
SELECT u.id AS user_id, u.Name AS name, u.active AS active, s.SId AS site_id, s.nick_name AS site_name,
       MIN(x.d) AS first_date, MAX(x.d) AS last_date, COUNT(x.d) AS n_days
FROM users u
LEFT JOIN (
    SELECT f.user_id, f.site_id, DATE(f.work_date) AS d FROM payroll_finalized f
    UNION ALL
    SELECT a.user_id, a.site_id, DATE(a.clock_in) AS d FROM attendance a
) x ON x.user_id = u.id
LEFT JOIN sites s ON s.SId = x.site_id AND s.org_id = u.org_id
WHERE u.org_id = 2
GROUP BY u.id, u.Name, u.active, s.SId, s.nick_name
ORDER BY u.Name, s.nick_name;
