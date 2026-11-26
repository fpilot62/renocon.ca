#!/bin/bash
# Remove the "Admin Vernon" test amounts from the Sheet Review draft tables only.
# Abdul approved this in chat (t424u: "admin vernon is a test amounts, delete them").
#   bash admin_vernon_cleanup.sh lookup            read-only: lists every matching row with ids and amounts
#   bash admin_vernon_cleanup.sh backup            dumps the affected sheet tables to /root/payroll-daily
#   EXPECT_ROWS=n EXPECT_EVENTS=n EXPECT_LINES=n bash admin_vernon_cleanup.sh delete
#                                                  deletes only those rows in one transaction, after a backup
#   bash admin_vernon_cleanup.sh verify            counts what is left
# Touches only payroll_sheet_rows, payroll_sheet_events, payroll_sheet_plan_lines and the
# payroll_sheet_revisions lines that point at deleted rows (the edit audit log is kept).
# Never touches payroll_finalized, payroll_payments, pay runs, shifts, attendance or users.
# STOPS (deletes nothing) when a matching row has an active approval, because that row
# has posted payroll.
set -euo pipefail
DB=${PAYROLL_DB:-admin_payroll}
ORG=${SHEET_ORG:-2}
BK=${PAYROLL_BK:-/root/payroll-daily}
M=${1:-lookup}
q() { mysql "$DB" -t -e "$1"; }
qs() { mysql "$DB" -N -B -e "$1"; }
# A sheet name or linked employee name that contains both "admin" and "vernon", any order, any case.
ROWMATCH="r.org_id = $ORG AND ((LOWER(r.employee_name) LIKE '%admin%' AND LOWER(r.employee_name) LIKE '%vernon%')
  OR (LOWER(IFNULL(u.Name,'')) LIKE '%admin%' AND LOWER(IFNULL(u.Name,'')) LIKE '%vernon%'))"
EVMATCH="e.org_id = $ORG AND LOWER(e.employee_context) LIKE '%admin%' AND LOWER(e.employee_context) LIKE '%vernon%'"
LNMATCH="l.org_id = $ORG AND LOWER(l.employee_name) LIKE '%admin%' AND LOWER(l.employee_name) LIKE '%vernon%'"
ROWFROM="payroll_sheet_rows r LEFT JOIN users u ON u.id = r.user_id AND u.org_id = r.org_id"

lookup() {
  echo "== Sheet Review draft rows (org $ORG) =="
  q "SELECT r.id, r.import_id, r.source_sheet, r.source_row, r.employee_name, u.Name AS linked_user, r.store_name,
            r.period_start, r.period_end, r.gross, r.net, r.reported_status, r.deleted_at
     FROM $ROWFROM WHERE $ROWMATCH ORDER BY r.id"
  echo "== Sheet events (cash evidence) =="
  q "SELECT e.id, e.import_id, e.event_key, e.source_row, e.source_cell, e.employee_context, e.signed_amount, e.allocation_status
     FROM payroll_sheet_events e WHERE $EVMATCH ORDER BY e.id"
  echo "== Proposed plan lines =="
  q "SELECT l.id, l.plan_id, l.employee_name, l.user_id, l.proposed_amount, l.pay_route, l.approved_at
     FROM payroll_sheet_plan_lines l WHERE $LNMATCH ORDER BY l.id"
  echo "== Active approvals on matching rows (must be none) =="
  q "SELECT ef.id, ef.approval_id, ef.row_id, ef.effect_kind, ef.finalized_id, ef.payment_id
     FROM $ROWFROM JOIN payroll_sheet_approval_effects ef ON ef.row_id = r.id
     WHERE ef.undone_at IS NULL AND $ROWMATCH"
  echo "== Read-only: users with that name, and whether real payroll rows exist for them (NOT deleted by this script) =="
  q "SELECT u.id, u.Name, u.org_id, u.active,
            (SELECT COUNT(*) FROM payroll_finalized f WHERE f.user_id = u.id) AS finalized_rows,
            (SELECT ROUND(SUM(f.total_gross),2) FROM payroll_finalized f WHERE f.user_id = u.id) AS finalized_gross
     FROM users u WHERE u.org_id = $ORG AND LOWER(u.Name) LIKE '%admin%' AND LOWER(u.Name) LIKE '%vernon%'"
  echo "== Counts to pass to delete =="
  echo "EXPECT_ROWS=$(qs "SELECT COUNT(*) FROM $ROWFROM WHERE $ROWMATCH") EXPECT_EVENTS=$(qs "SELECT COUNT(*) FROM payroll_sheet_events e WHERE $EVMATCH") EXPECT_LINES=$(qs "SELECT COUNT(*) FROM payroll_sheet_plan_lines l WHERE $LNMATCH")"
  echo "If real payroll rows (finalized_rows above) are the Admin Vernon amounts, they are not in the sheet tables: do not delete them with this script. Report instead."
}
backup() {
  mkdir -p "$BK"
  TS=${BACKUP_TS:-$(date +%Y%m%d-%H%M%S)}
  F="$BK/pre-adminvernon-$TS-sheet-tables.sql.gz"
  mysqldump --single-transaction "$DB" payroll_sheet_rows payroll_sheet_events payroll_sheet_plan_lines payroll_sheet_plans \
    payroll_sheet_revisions payroll_sheet_approval_effects | gzip -c > "$F"
  [[ -s $F ]] || { echo "Backup is empty"; exit 1; }
  # payroll_sheet_edit_audit exists only after the sheet-edit deploy
  if [[ $(qs "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'payroll_sheet_edit_audit'") == 1 ]]; then
    mysqldump --single-transaction "$DB" payroll_sheet_edit_audit | gzip -c > "$BK/pre-adminvernon-$TS-edit-audit.sql.gz"
  fi
  echo "Backup: $F"
  echo "Restore one table with: gunzip -c $F | mysql $DB   (this restores all six tables in the dump, so only do it to undo)"
}
del() {
  : "${EXPECT_ROWS:?set EXPECT_ROWS from lookup}" "${EXPECT_EVENTS:?set EXPECT_EVENTS}" "${EXPECT_LINES:?set EXPECT_LINES}"
  local nr ne nl na
  nr=$(qs "SELECT COUNT(*) FROM $ROWFROM WHERE $ROWMATCH")
  ne=$(qs "SELECT COUNT(*) FROM payroll_sheet_events e WHERE $EVMATCH")
  nl=$(qs "SELECT COUNT(*) FROM payroll_sheet_plan_lines l WHERE $LNMATCH")
  na=$(qs "SELECT COUNT(*) FROM $ROWFROM JOIN payroll_sheet_approval_effects ef ON ef.row_id = r.id WHERE ef.undone_at IS NULL AND $ROWMATCH")
  if [[ $nr != "$EXPECT_ROWS" || $ne != "$EXPECT_EVENTS" || $nl != "$EXPECT_LINES" ]]; then
    echo "STOP. Now matching rows=$nr events=$ne lines=$nl, expected $EXPECT_ROWS/$EXPECT_EVENTS/$EXPECT_LINES. Run lookup again. Nothing deleted."; exit 1
  fi
  if [[ $na != 0 ]]; then echo "STOP. $na matching row(s) have an active approval. Nothing deleted."; exit 1; fi
  if [[ $nr == 0 && $ne == 0 && $nl == 0 ]]; then echo "Nothing matches in the sheet tables. Nothing to delete."; exit 0; fi
  BACKUP_TS=${BACKUP_TS:-$(date +%Y%m%d-%H%M%S)}
  export BACKUP_TS
  backup
  mysql "$DB" <<SQL
START TRANSACTION;
CREATE TEMPORARY TABLE av_rows (id INT PRIMARY KEY) AS SELECT r.id FROM $ROWFROM WHERE $ROWMATCH;
DELETE FROM payroll_sheet_revisions WHERE org_id = $ORG AND target_type = 'row' AND target_id IN (SELECT id FROM av_rows);
DELETE e FROM payroll_sheet_events e WHERE $EVMATCH;
DELETE l FROM payroll_sheet_plan_lines l WHERE $LNMATCH;
DELETE FROM payroll_sheet_rows WHERE org_id = $ORG AND id IN (SELECT id FROM av_rows);
COMMIT;
SQL
  echo "Deleted rows=$nr events=$ne plan_lines=$nl (org $ORG). Backup timestamp $BACKUP_TS."
  verify
}
verify() {
  echo "Remaining matches (all should be 0):"
  echo "rows=$(qs "SELECT COUNT(*) FROM $ROWFROM WHERE $ROWMATCH") events=$(qs "SELECT COUNT(*) FROM payroll_sheet_events e WHERE $EVMATCH") lines=$(qs "SELECT COUNT(*) FROM payroll_sheet_plan_lines l WHERE $LNMATCH")"
  echo "Real payroll rows are untouched by this script. Total sheet rows left in org $ORG: $(qs "SELECT COUNT(*) FROM payroll_sheet_rows WHERE org_id = $ORG AND deleted_at IS NULL")"
}
case "$M" in
  lookup) lookup ;;
  backup) backup ;;
  delete) del ;;
  verify) verify ;;
  *) echo "Usage: $0 lookup|backup|delete|verify"; exit 1 ;;
esac
