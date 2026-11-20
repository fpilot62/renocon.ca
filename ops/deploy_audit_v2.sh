#!/bin/bash
# Attendance audit deploy v2.
# v1 failed because production is not commit 9cf017e. The live checksums below
# match no blob in the repository, with the org-scope guard added or removed.
# Those files are patched in place. The two ledger pages are not touched.
#
#   bash deploy_audit_v2.sh check
#   bash deploy_audit_v2.sh go
#   bash deploy_audit_v2.sh rollback <ts>
#
# check is read-only. go refuses unless check prints CHECK OK.
set -euo pipefail

MODE=${1:-}
TSARG=${2:-}
APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
DB=${PAYROLL_DB:-admin_payroll}
BK=${PAYROLL_BK:-/root/payroll-daily}
MERGED=${AUDIT_MERGED:-/root/audit-v2-merged}
WORK=${AUDIT_WORK:-/root/audit-v2-work}
URL=${PAYROLL_URL:-https://payroll.renocon.ca}
RAW=${AUDIT_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca}
GIT=${AUDIT_GIT:-}

BASE=9cf017e5702bd03dacfe0be3954ad487ef8522f3
HEAD=5c798d62b6f546533730304ee2ef760cbdfaf6d3

# Live bytes matched the feature base, or differed only by the org-scope guard.
FILES=$(cat <<EOF
api/punch.php|$BASE|$HEAD|0
app_clock.php|$BASE|$HEAD|0
app_punch.php|$BASE|$HEAD|0
app_upload_attendance.php|$BASE|$HEAD|0
approve_shift.php|$BASE|$HEAD|0
attendance_audit.php|$BASE|$HEAD|1
bulk_assign_site.php|$BASE|$HEAD|0
bulk_finalize_autosplit.php|$BASE|$HEAD|0
bulk_finalize_handler.php|$BASE|$HEAD|0
delete_shift.php|$BASE|$HEAD|0
engine/aa_labor_hours_lib.php|$BASE|$HEAD|0
engine/attendance_audit_lib.php|$BASE|$HEAD|1
engine/attendance_integrity_lib.php|$BASE|$HEAD|0
importer_lib.php|$BASE|$HEAD|0
migrations/20261120_attendance_audit.sql|$BASE|$HEAD|1
payroll_processor.php|$BASE|$HEAD|0
payroll_save_payment.php|$BASE|$HEAD|0
process_audit.php|$BASE|$HEAD|0
process_finalize.php|$BASE|$HEAD|0
process_payroll.php|$BASE|$HEAD|0
save_payroll.php|$BASE|$HEAD|0
save_split_payroll.php|$BASE|$HEAD|0
transfer_engine.php|$BASE|$HEAD|0
EOF
)

# Checksums from the failed v1 run. No repository blob matches them.
KNOWN_MD5=$(cat <<'EOF'
delete_attendance.php 14b0f72564e3096b9b64ed72cf389a17
edit_shift.php 50a2600ce55affb20b334e469327aed5
staff_audit.php 1884d5f42e2651ef87bbbe2cf2b71e03
staff_audit1.php 10cf3da1a5249db25317eaf9c2fd6f0a
clearview_import.php 2b54f1dd517e9f54a04d191261e13571
engine/update_shift_time.php 739215f79dd69924c05df89ed4519ee4
import_clearview.php 76f2019a84458178c5d6353a4e9ae805
imports/import_commit.php a5786eb164f8a5aac33a3e998c1c47f2
payroll_ledger.php 4e2f70452285df682a74d4920b0c9fe2
process_bulk_payroll.php e80732db0404251fc230a960deb41229
process_kiosk.php 4e3458954011425870c355ee9d8ab674
process_punch.php 0066aab70a91e6a15e4986696bda9f5b
process_punch1.php e7a2abd9e38155732a47dad249ac5eb2
revert_payroll.php 9a6a551a3b8449e1d5354230ec9f6083
unfinalize_action.php a7aec2c455810620a44bd989f76b92cf
update_attendance.php 8a88472170ae308c649c18669ad386d6
update_pending_hrs.php a90a6f6fe703cd4a572ecc6668580812
bulk_finalize.php e6f0c4e17e61ee7a11e87f29dd4e2d87
aa_labor_hours_import.php cc79277aabf5e4098b4446b1c24e06c3
manual_attendance.php 7e5d87d817bf97a76b48dc9ac46ebd6f
EOF
)

ANCHOR=$(cat <<'EOF'
aa_labor_hours_import.php
bulk_allocation_desk.php
bulk_finalize.php
clearview_import.php
delete_attendance.php
edit_shift.php
engine/update_shift_time.php
import_clearview.php
imports/import_commit.php
manual_attendance.php
migration_identity_fix.php
payroll_ledger.php
process_bulk_payroll.php
process_kiosk.php
process_punch.php
process_punch1.php
revert_payroll.php
staff_audit.php
staff_audit1.php
unfinalize_action.php
update_attendance.php
update_pending_hrs.php
EOF
)

# Left for the ledger expand bundle and the T4127 ledger work.
SKIP=$(cat <<'EOF'
payroll_master_ledger.php
payroll_master_ledger1.php
EOF
)

require_root() {
  if [[ ${AUDIT_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then
    echo "Run as root."
    exit 1
  fi
}

md() { md5sum "$1" | cut -c1-32; }

fetch_sha() {
  local sha=$1 rel=$2 dest=$3
  mkdir -p "$(dirname "$dest")"
  if [[ -n $GIT ]]; then
    git -C "$GIT" show "$sha:$rel" > "$dest"
    [[ -s $dest ]]
    return
  fi
  local code
  code=$(curl -fsS -o "$dest" -w '%{http_code}' --retry 3 --max-time 60 \
    "$RAW/$sha/$rel" || true)
  if [[ ! -s $dest || $code != 200 ]]; then
    rm -f "$dest"
    return 1
  fi
  return 0
}

only_org_scope() {
  local base=$1 live=$2
  local adds dels line body
  adds=$(diff -u "$base" "$live" | grep '^+' | grep -v '^+++' || true)
  dels=$(diff -u "$base" "$live" | grep '^-' | grep -v '^---' || true)
  [[ -z $dels && -n $adds ]] || return 1
  while IFS= read -r line; do
    [[ -z $line ]] && continue
    body=${line#+}
    [[ -z ${body//[[:space:]]/} ]] && continue
    [[ $body == *orgscope_guard.php* && $body == *osg_page* ]] || return 1
  done <<< "$adds"
  return 0
}

reinsert_guard() {
  local live=$1 pr=$2 out=$3
  local guards
  guards=$(grep 'orgscope_guard.php' "$live" || true)
  [[ -n $guards ]] || return 1
  {
    IFS= read -r first
    printf '%s\n' "$first"
    printf '%s\n' "$guards"
    cat
  } < "$pr" > "$out"
}

merge_one() {
  local rel=$1 base_sha=$2 head_sha=$3 added=$4
  local live=$APP/$rel
  local base=$WORK/base/$rel
  local pr=$WORK/pr/$rel
  local out=$MERGED/$rel
  mkdir -p "$(dirname "$base")" "$(dirname "$pr")" "$(dirname "$out")"
  if ! fetch_sha "$head_sha" "$rel" "$pr"; then
    echo "FETCH_FAIL $rel $head_sha"
    return 1
  fi
  if [[ $added == 1 ]]; then
    if [[ ! -e $live ]]; then
      cp -f "$pr" "$out"
      echo "SAFE $rel (new file, not on the server)"
      echo "SAFE $rel" >> "$MERGED/plan.txt"
      return 0
    fi
    echo "CONFLICT $rel (new in the PR, but a different file is already live)"
    : > "$base"
  else
    if ! fetch_sha "$base_sha" "$rel" "$base"; then
      echo "FETCH_FAIL base $rel $base_sha"
      return 1
    fi
    if [[ ! -e $live ]]; then
      echo "CONFLICT $rel (missing on the server)"
      live=$WORK/empty/$rel
      mkdir -p "$(dirname "$live")"
      : > "$live"
    fi
    local live_md5 base_md5
    live_md5=$(md "$live")
    base_md5=$(md "$base")
    if [[ $live_md5 == "$base_md5" ]]; then
      cp -f "$pr" "$out"
      echo "SAFE $rel"
      echo "SAFE $rel" >> "$MERGED/plan.txt"
      return 0
    fi
    echo "DRIFT $rel live=$live_md5 base=$base_md5"
  fi

  local rc=0
  if command -v git >/dev/null 2>&1; then
    git merge-file -p -L live -L base -L pr "$live" "$base" "$pr" > "$out" || rc=$?
  elif command -v diff3 >/dev/null 2>&1; then
    diff3 -m -L live -L base -L pr "$live" "$base" "$pr" > "$out" || rc=$?
  else
    echo "CONFLICT $rel (need git merge-file or diff3)"
    echo "CONFLICT $rel" >> "$MERGED/plan.txt"
    return 1
  fi
  if [[ $rc -lt 0 ]]; then
    echo "CONFLICT $rel (merge tool failed)"
    echo "CONFLICT $rel" >> "$MERGED/plan.txt"
    return 1
  fi
  local live_guard=0 out_guard=0 markers=0
  if [[ -s $live ]] && grep -q 'orgscope_guard.php' "$live"; then live_guard=1; fi
  if grep -q 'orgscope_guard.php' "$out"; then out_guard=1; fi
  if grep -q '^<<<<<<<' "$out"; then markers=1; fi
  if [[ $markers -eq 0 && $rc -eq 0 && ( $live_guard -eq 0 || $out_guard -eq 1 ) ]]; then
    echo "CLEAN $rel"
    echo "CLEAN $rel" >> "$MERGED/plan.txt"
    return 0
  fi
  if [[ $added == 0 && -s $base && -s $live ]] && only_org_scope "$base" "$live"; then
    if reinsert_guard "$live" "$pr" "$out" && grep -q 'orgscope_guard.php' "$out"; then
      if [[ $rel != *.php ]] || php -l "$out" >/dev/null 2>&1; then
        echo "CLEAN $rel (org-scope guard kept)"
        echo "CLEAN $rel" >> "$MERGED/plan.txt"
        return 0
      fi
    fi
  fi
  echo "CONFLICT $rel"
  echo "CONFLICT $rel" >> "$MERGED/plan.txt"
  return 1
}

write_patcher() {
  local dest=$1
  cat > "$dest" << 'ENDPY'
#!/usr/bin/env python3
"""Anchor patches for live payroll files that match no git blob.

The production checksums supplied for deploy_audit_v1.sh do not match any
blob in this repository, with or without an org-scope guard line. These
patches insert the audit calls into the live file and leave every other
line as it is. A miss leaves the file unchanged.
"""
import re
import sys


class Miss(Exception):
    pass


LIB = {
    ".": "require_once __DIR__ . '/engine/attendance_audit_lib.php';",
    "engine": "require_once __DIR__ . '/attendance_audit_lib.php';",
    "imports": "require_once __DIR__ . '/../engine/attendance_audit_lib.php';",
}

REQUIRE_RES = [
    r"require_once __DIR__ \. '/engine/attendance_integrity_lib\.php';\r?\n",
    r"require_once __DIR__ \. '/db\.php';\r?\n",
    r"require_once 'db\.php';[ \t]*\r?\n",
    r'require_once "db\.php";[ \t]*\r?\n',
    r"require_once '\.\./db\.php';\r?\n",
    r"require_once 'engine\.php';\r?\n",
    r"include 'db\.php';\r?\n",
]


def _nl(text):
    return "\r\n" if "\r\n" in text else "\n"


def _once(text, anchor, label):
    n = text.count(anchor)
    if n != 1:
        raise Miss("%s found %d times" % (label, n))


def _after(text, anchor, extra, label):
    _once(text, anchor, label)
    if extra in text:
        return text
    return text.replace(anchor, anchor + extra, 1)


def _ensure_lib(text, folder):
    if "attendance_audit_lib.php" in text:
        return text
    line = LIB[folder] + "\n"
    for pat in REQUIRE_RES:
        hits = list(re.finditer(pat, text))
        if len(hits) == 1:
            end = hits[0].end()
            return text[:end] + line + text[end:]
    raise Miss("no single require line for the audit library")


def patch(rel, text):
    """Return patched source. Raise Miss if a required anchor is absent."""
    if rel == "payroll_master_ledger.php" or rel == "payroll_master_ledger1.php":
        raise Miss("ledger file is left unchanged")
    newline = _nl(text)
    body = text.replace("\r\n", "\n")
    logged = "attendance_audit_lib.php" in body and ("aa_log(" in body or "aa_log_ids(" in body)
    if logged and rel != "staff_audit.php":
        return text
    if rel == "staff_audit.php" and logged and "aa-manual-badge" in body and "Manual Pay</a>" not in body:
        return text
    out = _apply(rel, body)
    if newline != "\n":
        out = out.replace("\n", newline)
    return out


def _apply(rel, text):
    fn = PATCHERS.get(rel)
    if fn is None:
        raise Miss("no anchor patch for " + rel)
    return fn(text)


def _p_delete_attendance(text):
    text = _ensure_lib(text, ".")
    text = _after(
        text,
        "$st = $pdo->prepare($sql);\n$st->execute($params);\n",
        "$delBefore = aa_befores($pdo, $ids);\n",
        "soft delete prepare",
    )
    # before must precede prepare. The insert above put it after execute's predecessor wrongly.
    wrong = "$st = $pdo->prepare($sql);\n$st->execute($params);\n$delBefore = aa_befores($pdo, $ids);\n"
    right = "$delBefore = aa_befores($pdo, $ids);\n$st = $pdo->prepare($sql);\n$st->execute($params);\naa_log_ids($pdo, 'delete', $ids, $delBefore, 'Soft delete');\n"
    if wrong not in text:
        raise Miss("soft delete order")
    return text.replace(wrong, right, 1)


def _p_edit_shift(text):
    text = _ensure_lib(text, ".")
    anchor = '$stmt = $pdo->prepare("UPDATE attendance SET clock_in = ?, clock_out = ?, site_id = ? WHERE id = ?");\n        $stmt->execute([$_POST[\'c_in\'], $_POST[\'c_out\'] ?: null, $_POST[\'site_id\'], $shift_id]);\n'
    extra_before = "        $aaBefore = aa_snapshot($pdo, (int)$shift_id);\n        "
    repl = (
        "$aaBefore = aa_snapshot($pdo, (int)$shift_id);\n"
        "        $stmt = $pdo->prepare(\"UPDATE attendance SET clock_in = ?, clock_out = ?, site_id = ? WHERE id = ?\");\n"
        "        $stmt->execute([$_POST['c_in'], $_POST['c_out'] ?: null, $_POST['site_id'], $shift_id]);\n"
        "        aa_log($pdo, 'update', (int)$shift_id, $aaBefore, null, 'Edit shift times');\n"
    )
    _once(text, anchor, "edit shift")
    return text.replace(anchor, repl, 1)


def _p_update_shift_time(text):
    text = _ensure_lib(text, "engine")
    anchor = '$stmt = $pdo->prepare("UPDATE attendance SET clock_in = ?, clock_out = ? WHERE id = ?");\n        $stmt->execute([$in, $out, $id]);\n'
    repl = (
        "$aaId = (int)$id;\n"
        "        $aaBefore = aa_snapshot($pdo, $aaId);\n"
        "        $stmt = $pdo->prepare(\"UPDATE attendance SET clock_in = ?, clock_out = ? WHERE id = ?\");\n"
        "        $stmt->execute([$in, $out, $id]);\n"
        "        aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Update shift time');\n"
    )
    _once(text, anchor, "update shift time")
    return text.replace(anchor, repl, 1)


def _p_update_attendance(text):
    text = _ensure_lib(text, ".")
    anchor = '$sql = "UPDATE attendance SET $column = ? WHERE id = ?";\n    $stmt = $pdo->prepare($sql);\n    $stmt->execute([$val, $id]);\n'
    repl = (
        '$sql = "UPDATE attendance SET $column = ? WHERE id = ?";\n'
        "    $aaBefore = aa_snapshot($pdo, $id);\n"
        "    $stmt = $pdo->prepare($sql);\n"
        "    $stmt->execute([$val, $id]);\n"
        "    aa_log($pdo, 'update', $id, $aaBefore, null, 'Update ' . $column);\n"
    )
    _once(text, anchor, "update attendance")
    return text.replace(anchor, repl, 1)


def _p_update_pending(text):
    text = _ensure_lib(text, ".")
    anchor = '$stmt = $pdo->prepare("UPDATE attendance SET $column = ? WHERE id = ?");\n    $stmt->execute([$val, $id]);\n'
    repl = (
        "$aaBefore = aa_snapshot($pdo, $id);\n"
        "    $stmt = $pdo->prepare(\"UPDATE attendance SET $column = ? WHERE id = ?\");\n"
        "    $stmt->execute([$val, $id]);\n"
        "    aa_log($pdo, 'update', $id, $aaBefore, null, 'Pending hours');\n"
    )
    _once(text, anchor, "pending hours")
    return text.replace(anchor, repl, 1)


def _p_unfinalize(text):
    text = _ensure_lib(text, ".")
    anchor = 'WHERE id = ?")->execute([$att_id]);\n'
    repl = (
        "WHERE id = ?\")->execute([$att_id]);\n"
        "        aa_log($pdo, 'unfinalize', (int)$att_id, $aaBefore, null, 'Unfinalize');\n"
    )
    # snapshot before the statement. The statement is a multi-line prepare.
    start = '$pdo->prepare("UPDATE attendance SET'
    _once(text, start, "unfinalize update")
    if "$aaBefore = aa_snapshot($pdo, (int)$att_id);" not in text:
        text = text.replace(start, "$aaBefore = aa_snapshot($pdo, (int)$att_id);\n        " + start, 1)
    _once(text, anchor, "unfinalize execute")
    if "aa_log($pdo, 'unfinalize'" not in text:
        text = text.replace(anchor, repl, 1)
    return text


def _p_revert(text):
    text = _ensure_lib(text, ".")
    anchor = '$upd = $pdo->prepare("UPDATE attendance SET is_finalized = 0, payout_status = 0 WHERE id = ?");\n    $upd->execute([$attendance_id]);\n'
    repl = (
        "$aaBefore = aa_snapshot($pdo, (int)$attendance_id);\n"
        "    $upd = $pdo->prepare(\"UPDATE attendance SET is_finalized = 0, payout_status = 0 WHERE id = ?\");\n"
        "    $upd->execute([$attendance_id]);\n"
        "    aa_log($pdo, 'unfinalize', (int)$attendance_id, $aaBefore, null, 'Revert payroll');\n"
    )
    _once(text, anchor, "revert payroll")
    return text.replace(anchor, repl, 1)


def _p_bulk_finalize(text):
    text = _ensure_lib(text, ".")
    anchor = '$sql = "UPDATE attendance SET status = \'1\' WHERE id IN ($placeholders) AND status = \'4\'";\n        $stmt = $pdo->prepare($sql);\n        $stmt->execute($ids);\n'
    repl = (
        "$aaBefore = aa_befores($pdo, $ids);\n"
        "        $sql = \"UPDATE attendance SET status = '1' WHERE id IN ($placeholders) AND status = '4'\";\n"
        "        $stmt = $pdo->prepare($sql);\n"
        "        $stmt->execute($ids);\n"
        "        aa_log_ids($pdo, 'finalize', $ids, $aaBefore, 'Bulk finalize');\n"
    )
    _once(text, anchor, "bulk finalize")
    return text.replace(anchor, repl, 1)


def _p_payroll_ledger(text):
    text = _ensure_lib(text, ".")
    a = '$pdo->prepare("UPDATE attendance SET is_processed = 0 WHERE id = ?")->execute([$id]);\n'
    b = '$pdo->prepare("UPDATE attendance SET is_processed = 2 WHERE id = ?")->execute([$id]);\n'
    _once(text, a, "ledger undo")
    _once(text, b, "ledger finalize")
    text = text.replace(
        a,
        "$aaBefore = aa_snapshot($pdo, $id);\n        "
        + a
        + "        aa_log($pdo, 'unfinalize', $id, $aaBefore, null, 'Ledger undo');\n",
        1,
    )
    text = text.replace(
        b,
        "$aaBefore = aa_snapshot($pdo, $id);\n        "
        + b
        + "        aa_log($pdo, 'finalize', $id, $aaBefore, null, 'Ledger finalize');\n",
        1,
    )
    return text


def _p_clearview_import(text):
    text = _ensure_lib(text, ".")
    anchor = "$shift_id = $pdo->lastInsertId();\n"
    _once(text, anchor, "clearview insert id")
    return text.replace(
        anchor,
        "$shift_id = (int)$pdo->lastInsertId();\n"
        "                aa_log($pdo, 'create', $shift_id, null, null, 'Clearview import');\n",
        1,
    )


def _p_import_clearview(text):
    text = _ensure_lib(text, ".")
    anchor = "$pdo->query(\"DELETE FROM attendance WHERE clock_in BETWEEN '2026-02-01 00:00:00' AND '2026-02-28 23:59:59'\");\n"
    repl = (
        "$aaIds = aa_ids_where($pdo, \"clock_in BETWEEN '2026-02-01 00:00:00' AND '2026-02-28 23:59:59'\", []);\n"
        "    $aaBefore = aa_befores($pdo, $aaIds);\n"
        "    $pdo->query(\"DELETE FROM attendance WHERE clock_in BETWEEN '2026-02-01 00:00:00' AND '2026-02-28 23:59:59'\");\n"
        "    aa_log_ids($pdo, 'delete', $aaIds, $aaBefore, 'Clearview February replace');\n"
    )
    _once(text, anchor, "february delete")
    text = text.replace(anchor, repl, 1)
    marker = "$rowCount++;\n"
    _once(text, marker, "row count")
    return text.replace(
        marker,
        "aa_log($pdo, 'create', (int)$pdo->lastInsertId(), null, null, 'Clearview import');\n            $rowCount++;\n",
        1,
    )


def _p_import_commit(text):
    text = _ensure_lib(text, "imports")
    anchor = "$clear->execute([$target_org_id, $from, $to]);\n"
    repl = (
        "$aaIds = aa_ids_where($pdo, \"org_id = ? AND status = '4' AND DATE(clock_in) BETWEEN ? AND ?\", [$target_org_id, $from, $to]);\n"
        "        $aaBefore = aa_befores($pdo, $aaIds);\n"
        "        $clear->execute([$target_org_id, $from, $to]);\n"
        "        aa_log_ids($pdo, 'delete', $aaIds, $aaBefore, 'Import replaced this range');\n"
    )
    _once(text, anchor, "import clear")
    text = text.replace(anchor, repl, 1)
    ins = "$ins->execute([$u_id, $s_id, $target_org_id, $r['clock_in'], $r['clock_out'], $receipt]);\n"
    _once(text, ins, "import insert")
    return text.replace(
        ins,
        ins + "                aa_log($pdo, 'create', (int)$pdo->lastInsertId(), null, null, 'Import commit');\n",
        1,
    )


def _p_migration_identity(text):
    text = _ensure_lib(text, ".")
    anchor = 'if ($ins->execute([$uid, $sid, $final_cin, $final_cout])) {\n        echo "SUCCESS";\n'
    repl = (
        'if ($ins->execute([$uid, $sid, $final_cin, $final_cout])) {\n'
        "        aa_log($pdo, 'create', (int)$pdo->lastInsertId(), null, null, 'Identity migration');\n"
        '        echo "SUCCESS";\n'
    )
    _once(text, anchor, "identity import")
    return text.replace(anchor, repl, 1)


def _p_manual(text):
    text = _ensure_lib(text, ".")
    anchor = "$new_shift_id = $pdo->lastInsertId();\n"
    _once(text, anchor, "manual shift id")
    return text.replace(
        anchor,
        "$new_shift_id = (int)$pdo->lastInsertId();\n"
        "                aa_log($pdo, 'create', $new_shift_id, null, null, 'Manual shift');\n",
        1,
    )


def _p_kiosk(text):
    text = _ensure_lib(text, ".")
    a = "$stmt->execute([$uid, $oid, $sid, $lat, $lng, $selfie_path]);\n"
    _once(text, a, "kiosk clock in")
    text = text.replace(a, a + "            aa_log($pdo, 'create', (int)$pdo->lastInsertId(), null, null, 'Kiosk clock in');\n", 1)
    b = "$pdo->prepare(\"UPDATE attendance SET status = 'on_break' WHERE id = ?\")->execute([$att['id']]);\n"
    _once(text, b, "kiosk break start")
    text = text.replace(
        b,
        "$aaId = (int)$att['id'];\n"
        "                $aaBefore = aa_snapshot($pdo, $aaId);\n"
        "                $pdo->prepare(\"UPDATE attendance SET status = 'on_break' WHERE id = ?\")->execute([$att['id']]);\n"
        "                aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Kiosk break start');\n",
        1,
    )
    c = "$pdo->prepare(\"UPDATE attendance SET status = 'working' WHERE id = ?\")->execute([$att['id']]);\n"
    _once(text, c, "kiosk break end")
    text = text.replace(
        c,
        "$aaId = (int)$att['id'];\n"
        "                $aaBefore = aa_snapshot($pdo, $aaId);\n"
        "                $pdo->prepare(\"UPDATE attendance SET status = 'working' WHERE id = ?\")->execute([$att['id']]);\n"
        "                aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Kiosk break end');\n",
        1,
    )
    d = '$stmt->execute([$lat, $lng, $selfie_path, $uid]);\n'
    _once(text, d, "kiosk clock out")
    return text.replace(
        d,
        "$aaIds = aa_ids_where($pdo, 'user_id = ? AND clock_out IS NULL', [$uid]);\n"
        "            $aaBefore = aa_befores($pdo, $aaIds);\n"
        "            $stmt->execute([$lat, $lng, $selfie_path, $uid]);\n"
        "            aa_log_ids($pdo, 'update', $aaIds, $aaBefore, 'Kiosk clock out');\n",
        1,
    )


def _p_bulk_payroll(text):
    text = _ensure_lib(text, ".")
    a = (
        "$pdo->prepare(\"UPDATE attendance SET approved_hours = ?, hourly_rate = ?, payout_status = 1, status = 'Finalized', flag_reason = 'Core Split Line' WHERE id = ?\")\n"
        "                    ->execute([$reg_hours, $base_rate, $id]);\n"
    )
    b = (
        "$pdo->prepare(\"UPDATE attendance SET approved_hours = ?, hourly_rate = ?, payout_status = 1, status = 'Finalized' WHERE id = ?\")\n"
        "                    ->execute([$total_calculated_hours, $base_rate, $id]);\n"
    )
    _once(text, a, "bulk split")
    _once(text, b, "bulk under cap")
    text = text.replace(
        a,
        "$aaBefore = aa_snapshot($pdo, (int)$id);\n"
        "                $pdo->prepare(\"UPDATE attendance SET approved_hours = ?, hourly_rate = ?, payout_status = 1, status = 'Finalized', flag_reason = 'Core Split Line' WHERE id = ?\")\n"
        "                    ->execute([$reg_hours, $base_rate, $id]);\n"
        "                aa_log($pdo, 'finalize', (int)$id, $aaBefore, null, 'Bulk payroll split');\n",
        1,
    )
    return text.replace(
        b,
        "$aaBefore = aa_snapshot($pdo, (int)$id);\n"
        "                $pdo->prepare(\"UPDATE attendance SET approved_hours = ?, hourly_rate = ?, payout_status = 1, status = 'Finalized' WHERE id = ?\")\n"
        "                    ->execute([$total_calculated_hours, $base_rate, $id]);\n"
        "                aa_log($pdo, 'finalize', (int)$id, $aaBefore, null, 'Bulk payroll');\n",
        1,
    )


def _p_aa_labor(text):
    text = _ensure_lib(text, ".")
    anchor = "$upd->execute([$markText, $uid, $siteId, $aa_org, $effective]);\n"
    repl = (
        "$aaIds = aa_ids_where($pdo, \"user_id = ? AND site_id = ? AND org_id = ? AND DATE(clock_in) >= ? AND flag_reason LIKE 'Rate flag:%'\", [$uid, $siteId, $aa_org, $effective]);\n"
        "                $aaBefore = aa_befores($pdo, $aaIds);\n"
        "                $upd->execute([$markText, $uid, $siteId, $aa_org, $effective]);\n"
        "                aa_log_ids($pdo, 'update', $aaIds, $aaBefore, 'AA rate mark');\n"
    )
    _once(text, anchor, "aa rate mark")
    return text.replace(anchor, repl, 1)


def _p_bulk_alloc(text):
    text = _ensure_lib(text, ".")
    a = "$closeShift->execute([$sid]);\n                continue; // travel shift fully handled"
    b = "$closeShift->execute([$sid]);\n            $period_refresh["
    _once(text, a, "alloc travel")
    _once(text, b, "alloc close")
    snap = "$aaBefore = aa_snapshot($pdo, (int)$sid);\n                "
    log = "\n                aa_log($pdo, 'finalize', (int)$sid, $aaBefore, null, 'Bulk allocation desk');"
    text = text.replace(a, snap + "$closeShift->execute([$sid]);" + log + "\n                continue; // travel shift fully handled", 1)
    snap2 = "$aaBefore = aa_snapshot($pdo, (int)$sid);\n            "
    log2 = "\n            aa_log($pdo, 'finalize', (int)$sid, $aaBefore, null, 'Bulk allocation desk');"
    return text.replace(b, snap2 + "$closeShift->execute([$sid]);" + log2 + "\n            $period_refresh[", 1)


def _p_punch(text):
    text = _ensure_lib(text, ".")
    cin = "$ins->execute([$org_id, $uid, $site_id, $now, $lat, $lng, $image, $is_flagged_trigger, $reason, $current_hourly, $current_official]);\n"
    _once(text, cin, "punch clock in")
    text = text.replace(cin, cin + "        aa_log($pdo, 'create', (int)$pdo->lastInsertId(), null, null, 'Clock in');\n", 1)
    cout = (
        "$upd->execute([$now, $lat, $lng, $image, $current_hourly, $current_official, $live['id']]);\n\n"
        "        if (is_file(__DIR__ . '/engine/break_waiver_lib.php')) {"
    )
    _once(text, cout, "punch clock out")
    text = text.replace(
        "$upd = $pdo->prepare(\"UPDATE attendance SET clock_out = ?, status = '4', lat_out = ?, lng_out = ?, selfie_out = ?, hourly_rate = ?, official_rate = ? WHERE id = ?\");\n"
        "        $upd->execute([$now, $lat, $lng, $image, $current_hourly, $current_official, $live['id']]);\n\n"
        "        if (is_file(__DIR__ . '/engine/break_waiver_lib.php')) {",
        "$aaId = (int)$live['id'];\n"
        "        $aaBefore = aa_snapshot($pdo, $aaId);\n"
        "        $upd = $pdo->prepare(\"UPDATE attendance SET clock_out = ?, status = '4', lat_out = ?, lng_out = ?, selfie_out = ?, hourly_rate = ?, official_rate = ? WHERE id = ?\");\n"
        "        $upd->execute([$now, $lat, $lng, $image, $current_hourly, $current_official, $live['id']]);\n"
        "        aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Clock out');\n\n"
        "        if (is_file(__DIR__ . '/engine/break_waiver_lib.php')) {",
        1,
    )
    tout = (
        "$upd->execute([$now, $lat, $lng, $image, $current_hourly, $current_official, $live['id']]);\n\n"
        "        $insTravel"
    )
    _once(text, tout, "punch transfer out")
    text = text.replace(
        "$upd = $pdo->prepare(\"UPDATE attendance SET clock_out = ?, status = '4', lat_out = ?, lng_out = ?, selfie_out = ?, hourly_rate = ?, official_rate = ? WHERE id = ?\");\n"
        "        $upd->execute([$now, $lat, $lng, $image, $current_hourly, $current_official, $live['id']]);\n\n"
        "        $insTravel",
        "$aaId = (int)$live['id'];\n"
        "        $aaBefore = aa_snapshot($pdo, $aaId);\n"
        "        $upd = $pdo->prepare(\"UPDATE attendance SET clock_out = ?, status = '4', lat_out = ?, lng_out = ?, selfie_out = ?, hourly_rate = ?, official_rate = ? WHERE id = ?\");\n"
        "        $upd->execute([$now, $lat, $lng, $image, $current_hourly, $current_official, $live['id']]);\n"
        "        aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Transfer out');\n\n"
        "        $insTravel",
        1,
    )
    travel = "$insTravel->execute([$org_id, $uid, $old_site_id, $now, $lat, $lng, $image, $current_hourly, $current_official]);\n"
    _once(text, travel, "travel insert")
    text = text.replace(travel, travel + "        aa_log($pdo, 'create', (int)$pdo->lastInsertId(), null, null, 'Travel row');\n", 1)
    tin = "$updTravel->execute([$now, $full_route_string, $lat, $lng, $image, $current_hourly, $current_official, $live['id']]);\n"
    _once(text, tin, "transfer in")
    text = text.replace(
        "$updTravel = $pdo->prepare(\"UPDATE attendance SET clock_out = ?, status = '5', transfer_status = ?, lat_out = ?, lng_out = ?, selfie_out = ?, hourly_rate = ?, official_rate = ? WHERE id = ?\");\n"
        "        $updTravel->execute([$now, $full_route_string, $lat, $lng, $image, $current_hourly, $current_official, $live['id']]);\n",
        "$aaId = (int)$live['id'];\n"
        "        $aaBefore = aa_snapshot($pdo, $aaId);\n"
        "        $updTravel = $pdo->prepare(\"UPDATE attendance SET clock_out = ?, status = '5', transfer_status = ?, lat_out = ?, lng_out = ?, selfie_out = ?, hourly_rate = ?, official_rate = ? WHERE id = ?\");\n"
        "        $updTravel->execute([$now, $full_route_string, $lat, $lng, $image, $current_hourly, $current_official, $live['id']]);\n"
        "        aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Transfer in');\n",
        1,
    )
    newshift = "$insNewShift->execute([$org_id, $uid, $site_id, $now, $lat, $lng, $image, $current_hourly, $current_official]);\n"
    _once(text, newshift, "new shift")
    text = text.replace(newshift, newshift + "        aa_log($pdo, 'create', (int)$pdo->lastInsertId(), null, null, 'Transfer in new shift');\n", 1)
    brk = "$upd->execute([$live['id']]);\n\n        $insBreak"
    _once(text, brk, "break start")
    text = text.replace(
        "$upd = $pdo->prepare(\"UPDATE attendance SET status = '1' WHERE id = ?\");\n"
        "        $upd->execute([$live['id']]);\n",
        "$aaId = (int)$live['id'];\n"
        "        $aaBefore = aa_snapshot($pdo, $aaId);\n"
        "        $upd = $pdo->prepare(\"UPDATE attendance SET status = '1' WHERE id = ?\");\n"
        "        $upd->execute([$live['id']]);\n"
        "        aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Break start');\n",
        1,
    )
    end = "$upd->execute([$live['id']]);\n    }\n"
    _once(text, end, "break end")
    return text.replace(
        "$upd = $pdo->prepare(\"UPDATE attendance SET status = '0' WHERE id = ?\");\n"
        "        $upd->execute([$live['id']]);\n",
        "$aaId = (int)$live['id'];\n"
        "        $aaBefore = aa_snapshot($pdo, $aaId);\n"
        "        $upd = $pdo->prepare(\"UPDATE attendance SET status = '0' WHERE id = ?\");\n"
        "        $upd->execute([$live['id']]);\n"
        "        aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Break end');\n",
        1,
    )


def _p_staff_audit1(text):
    text = _ensure_lib(text, ".")
    text = _after(
        text,
        "$new_shift_id = $pdo->lastInsertId();\n",
        "            aa_log($pdo, 'create', (int)$new_shift_id, null, null, 'Duplicated shift');\n",
        "audit1 duplicate",
    )
    # cast the id
    text = text.replace("$new_shift_id = $pdo->lastInsertId();\n", "$new_shift_id = (int)$pdo->lastInsertId();\n", 1)
    anchor = "$delStmt->execute($clean_ids);\n"
    _once(text, anchor, "audit1 delete")
    text = text.replace(
        anchor,
        "$aaBefore = aa_befores($pdo, $clean_ids);\n"
        "            $delStmt->execute($clean_ids);\n"
        "            aa_log_ids($pdo, 'delete', $clean_ids, $aaBefore, 'Staff audit delete');\n",
        1,
    )
    # The snapshot has to be before execute. Fix order.
    bad = "$delStmt->execute($clean_ids);\n            $aaBefore = aa_befores($pdo, $clean_ids);\n"
    if bad in text:
        text = text.replace(bad, "$aaBefore = aa_befores($pdo, $clean_ids);\n            $delStmt->execute($clean_ids);\n", 1)
    fix = """    $fixStmt = $pdo->query(\"
        UPDATE attendance a
        INNER JOIN users u ON a.user_id = u.id
        SET a.site_id = u.primary_site_id
        WHERE (a.site_id = '0' OR a.site_id = 'all' OR a.site_id IS NULL)
          AND u.primary_site_id > 0 
          AND a.payout_status = 0
    \");
"""
    if text.count(fix) == 1:
        text = text.replace(
            fix,
            """    $fixIdStmt = $pdo->query(\"
        SELECT a.id FROM attendance a
        INNER JOIN users u ON a.user_id = u.id
        WHERE (a.site_id = '0' OR a.site_id = 'all' OR a.site_id IS NULL)
          AND u.primary_site_id > 0
          AND a.payout_status = 0
    \");
    $fixIds = array_map('intval', $fixIdStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $fixBefore = aa_befores($pdo, $fixIds);
    if ($fixIds) {
        $pdo->query(\"
            UPDATE attendance a
            INNER JOIN users u ON a.user_id = u.id
            SET a.site_id = u.primary_site_id
            WHERE (a.site_id = '0' OR a.site_id = 'all' OR a.site_id IS NULL)
              AND u.primary_site_id > 0
              AND a.payout_status = 0
        \");
        aa_log_ids($pdo, 'update', $fixIds, $fixBefore, 'Filled a missing site from the employee primary site');
    }
""",
            1,
        )
    elif "SET a.site_id = u.primary_site_id" in text and "Filled a missing site" not in text:
        raise Miss("site fill query does not match")
    return text


def _p_staff_audit(text):
    text = _ensure_lib(text, ".")
    dup = "$new_shift_id = $pdo->lastInsertId();\n"
    _once(text, dup, "staff duplicate id")
    text = text.replace(
        dup,
        "$new_shift_id = (int)$pdo->lastInsertId();\n"
        "            aa_log($pdo, 'create', $new_shift_id, null, null, 'Duplicated shift');\n",
        1,
    )
    dele = "$delStmt->execute($delParams);\n"
    _once(text, dele, "staff delete")
    text = text.replace(
        dele,
        "$delBefore = aa_befores($pdo, $clean_ids);\n"
        "            $delStmt->execute($delParams);\n"
        "            aa_log_ids($pdo, 'delete', $clean_ids, $delBefore, 'Staff audit delete');\n",
        1,
    )
    # order: snapshot before execute
    bad = "$delStmt->execute($delParams);\n            $delBefore = aa_befores($pdo, $clean_ids);\n"
    if bad in text:
        text = text.replace(
            bad,
            "$delBefore = aa_befores($pdo, $clean_ids);\n            $delStmt->execute($delParams);\n",
            1,
        )
    fix = "$pdo->prepare($fixSql)->execute([$privacy_scope_org, $privacy_scope_org]);\n"
    if text.count(fix) == 1 and "Filled a missing site" not in text:
        text = text.replace(
            fix,
            "$fixIdStmt = $pdo->prepare(\"SELECT a.id FROM attendance a INNER JOIN users u ON a.user_id = u.id WHERE (a.site_id = '0' OR a.site_id = 'all' OR a.site_id IS NULL) AND u.primary_site_id > 0 AND a.payout_status = 0 AND a.org_id = ? AND u.org_id = ?\" . (function_exists('att_has_deleted_at') && att_has_deleted_at($pdo) ? ' AND a.deleted_at IS NULL' : ''));\n"
            "        $fixIdStmt->execute([$privacy_scope_org, $privacy_scope_org]);\n"
            "        $fixIds = array_map('intval', $fixIdStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);\n"
            "        $fixBefore = aa_befores($pdo, $fixIds);\n"
            "        if ($fixIds) {\n"
            "            $pdo->prepare($fixSql)->execute([$privacy_scope_org, $privacy_scope_org]);\n"
            "            aa_log_ids($pdo, 'update', $fixIds, $fixBefore, 'Filled a missing site from the employee primary site');\n"
            "        }\n",
            1,
        )
    rows = "$rows = [];\n$total_rows = 0;\n"
    if text.count(rows) == 1 and "$aa_badges = [];" not in text:
        text = text.replace(rows, "$rows = [];\n$aa_badges = [];\n$total_rows = 0;\n", 1)
    fetched = "$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);\n"
    if text.count(fetched) == 1 and "aa_manual_badges" not in text:
        text = text.replace(
            fetched,
            fetched
            + "    $aa_badges = aa_manual_badges($pdo, array_map(static function ($row) {\n"
            + "        return (int)($row['id'] ?? 0);\n"
            + "    }, $rows));\n",
            1,
        )
    css = ".mpe-row-link:hover { text-decoration: underline; color: #166534; }\n"
    if text.count(css) == 1 and ".aa-manual-badge" not in text:
        text = text.replace(
            css,
            css
            + "        .aa-manual-badge { display: inline-block; margin-top: 4px; font-size: 10px; font-weight: 700; line-height: 1.3; color: #7c2d12; background: #ffedd5; border: 1px solid #fdba74; border-radius: 999px; padding: 2px 7px; }\n"
            + "        .aa-history-link { font-size: 10px; font-weight: 800; color: #1d4ed8; text-decoration: none; white-space: nowrap; }\n",
            1,
        )
    pat = re.compile(
        r"[ \t]*<\?php if \(\$sess_user_level < 30 && \(int\)\(\$r\['user_id'\].*?Manual Pay</a>\s*<\?php endif; \?>\n",
        re.S,
    )
    hits = list(pat.finditer(text))
    if len(hits) != 1:
        raise Miss("manual pay row link found %d times" % len(hits))
    badge = (
        "                            <?php\n"
        "                            $aaBadge = $aa_badges[(int)$r['id']] ?? '';\n"
        "                            $aaOrg = (int)($r['staff_org_id'] ?? 0);\n"
        "                            if ($aaOrg <= 0) { $aaOrg = (int)$privacy_scope_org; }\n"
        "                            $aaHref = 'attendance_audit.php?attendance_id=' . (int)$r['id'] . ($aaOrg > 0 ? '&org_id=' . $aaOrg : '');\n"
        "                            if ($aaBadge !== ''): ?>\n"
        "                            <div class=\"aa-manual-badge\"><?= htmlspecialchars($aaBadge) ?></div>\n"
        "                            <?php endif; ?>\n"
        "                            "
    )
    # The regex eats the indentation of the following pencil link's preceding whitespace if included.
    # It stops at endif newline, so the pencil link remains.
    text = text[:hits[0].start()] + badge + text[hits[0].end():]
    pencil = '<a href="edit_shift.php?id=<?= $r[\'id\'] ?>"'
    if text.count(pencil) != 1:
        raise Miss("edit shift link")
    if "aa-history-link" not in text:
        text = text.replace(
            pencil,
            '<a class="aa-history-link" href="<?= htmlspecialchars($aaHref) ?>" title="Who changed this punch">History</a>\n                                ' + pencil,
            1,
        )
    if "Manual Pay</a>" in text:
        raise Miss("manual pay link still present")
    if "aa_manual_badges" not in text:
        raise Miss("could not attach manual badges")
    return text


PATCHERS = {
    "delete_attendance.php": _p_delete_attendance,
    "edit_shift.php": _p_edit_shift,
    "engine/update_shift_time.php": _p_update_shift_time,
    "update_attendance.php": _p_update_attendance,
    "update_pending_hrs.php": _p_update_pending,
    "unfinalize_action.php": _p_unfinalize,
    "revert_payroll.php": _p_revert,
    "bulk_finalize.php": _p_bulk_finalize,
    "payroll_ledger.php": _p_payroll_ledger,
    "clearview_import.php": _p_clearview_import,
    "import_clearview.php": _p_import_clearview,
    "imports/import_commit.php": _p_import_commit,
    "migration_identity_fix.php": _p_migration_identity,
    "manual_attendance.php": _p_manual,
    "process_kiosk.php": _p_kiosk,
    "process_bulk_payroll.php": _p_bulk_payroll,
    "aa_labor_hours_import.php": _p_aa_labor,
    "bulk_allocation_desk.php": _p_bulk_alloc,
    "process_punch.php": _p_punch,
    "process_punch1.php": _p_punch,
    "staff_audit.php": _p_staff_audit,
    "staff_audit1.php": _p_staff_audit1,
}


if __name__ == "__main__":
    if len(sys.argv) != 4:
        print("usage: audit_v2_patch.py REL SRC DEST", file=sys.stderr)
        sys.exit(2)
    rel, src, dest = sys.argv[1:]
    text = open(src, encoding="utf-8", errors="surrogateescape").read()
    try:
        out = patch(rel, text)
    except Miss as exc:
        print("MISS %s %s" % (rel, exc))
        sys.exit(1)
    open(dest, "w", encoding="utf-8", errors="surrogateescape").write(out)
    print("PATCHED %s" % rel)
ENDPY
}

known_for() {
  local rel=$1 line name sum
  while read -r name sum; do
    [[ $name == "$rel" ]] && { echo "$sum"; return; }
  done <<< "$KNOWN_MD5"
}

anchor_one() {
  local rel=$1
  local live=$APP/$rel
  local out=$MERGED/$rel
  local patcher=$WORK/audit_v2_patch.py
  mkdir -p "$(dirname "$out")" "$WORK"
  write_patcher "$patcher"
  if [[ ! -f $live ]]; then
    echo "SKIP $rel not logged yet (missing on the server)"
    echo "SKIP $rel" >> "$MERGED/plan.txt"
    return 0
  fi
  local live_md5 known
  live_md5=$(md "$live")
  known=$(known_for "$rel" || true)
  if [[ -n $known && $live_md5 == "$known" ]]; then
    echo "LIVE_MD5_MATCH $rel $live_md5"
  elif [[ -n $known ]]; then
    echo "LIVE_MD5_OTHER $rel live=$live_md5 known=$known"
  fi
  local msg
  if msg=$(python3 "$patcher" "$rel" "$live" "$out" 2>&1); then
    if [[ $rel == *.php ]] && ! php -l "$out" >/dev/null 2>&1; then
      echo "SKIP $rel not logged yet (php -l failed)"
      echo "SKIP $rel" >> "$MERGED/plan.txt"
      rm -f "$out"
      return 0
    fi
    if cmp -s "$live" "$out"; then
      echo "SAFE $rel (already logged)"
      echo "SAFE $rel" >> "$MERGED/plan.txt"
    else
      echo "CLEAN $rel (anchor patch)"
      echo "CLEAN $rel" >> "$MERGED/plan.txt"
    fi
    return 0
  fi
  echo "SKIP $rel not logged yet (${msg#MISS })"
  echo "SKIP $rel" >> "$MERGED/plan.txt"
  rm -f "$out"
  return 0
}

check() {
  require_root
  rm -rf "$WORK" "$MERGED"
  mkdir -p "$WORK" "$MERGED"
  : > "$MERGED/plan.txt"
  local bad=0 rel base head added
  while IFS='|' read -r rel base head added; do
    [[ -z $rel ]] && continue
    merge_one "$rel" "$base" "$head" "$added" || bad=1
    if [[ -f $MERGED/$rel && $rel == *.php ]]; then
      if ! php -l "$MERGED/$rel" >/dev/null 2>&1; then
        echo "CONFLICT $rel (php -l failed on the merged file)"
        echo "CONFLICT $rel" >> "$MERGED/plan.txt"
        bad=1
      fi
    fi
  done <<< "$FILES"
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    anchor_one "$rel"
  done <<< "$ANCHOR"
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    echo "SKIP $rel not logged yet (left for the ledger bundle)"
    echo "SKIP $rel" >> "$MERGED/plan.txt"
  done <<< "$SKIP"
  if [[ $bad -eq 0 ]] && ! grep -q '^CONFLICT ' "$MERGED/plan.txt"; then
    echo "CHECK OK"
    return 0
  fi
  echo "CHECK FAILED"
  return 1
}

neighbour_spec() {
  local dest=$1
  local ref=$dest
  if [[ ! -e $ref ]]; then
    ref=$(find "$(dirname "$dest")" -maxdepth 1 -type f -print -quit || true)
  fi
  if [[ -z $ref || ! -e $ref ]]; then
    ref=$(dirname "$dest")
  fi
  stat -c '%u:%g:%a' "$ref"
}

apply_spec() {
  local dest=$1 spec=$2
  local u g mode
  IFS=: read -r u g mode <<< "$spec"
  chown "$u:$g" "$dest"
  chmod "$mode" "$dest"
}

install_list() {
  local rel
  : > "$MERGED/install.txt"
  while IFS='|' read -r rel _b _h _a; do
    [[ -n $rel && -f $MERGED/$rel ]] && echo "$rel" >> "$MERGED/install.txt"
  done <<< "$FILES"
  while IFS= read -r rel; do
    [[ -n $rel && -f $MERGED/$rel ]] && echo "$rel" >> "$MERGED/install.txt"
  done <<< "$ANCHOR"
}

restore_files() {
  local ts=$1
  local tgz=$BK/pre-audit-v2-$ts-files.tgz
  local added=$BK/pre-audit-v2-$ts-added.txt
  [[ -f $tgz ]] || { echo "Missing $tgz"; return 1; }
  tar -xzpf "$tgz" -C "$APP"
  if [[ -f $added ]]; then
    local rel
    while IFS= read -r rel; do
      [[ -n $rel ]] && rm -f "$APP/$rel"
    done < "$added"
  fi
}

db_undo_this_run() {
  local ts=$1
  local state=$BK/pre-audit-v2-$ts.state
  [[ -f $state ]] || return 0
  # shellcheck disable=SC1090
  source "$state"
  if [[ ${CREATED_ATTENDANCE_AUDIT:-0} == 1 ]]; then
    mysql "$DB" -e "DROP TABLE IF EXISTS attendance_audit"
  fi
  if [[ ${CREATED_CREATED_BY:-0} == 1 ]]; then
    mysql "$DB" -e "ALTER TABLE attendance DROP COLUMN created_by"
  fi
  if [[ ${CREATED_UPDATED_BY:-0} == 1 ]]; then
    mysql "$DB" -e "ALTER TABLE attendance DROP COLUMN updated_by"
  fi
}

rollback_cmd() {
  local ts=$1
  [[ -n $ts ]] || { echo "Usage: bash $0 rollback <ts>"; exit 2; }
  restore_files "$ts"
  db_undo_this_run "$ts"
  echo "ROLLBACK DONE"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-audit-v2-$ts.sql.gz"
  echo "$BK/pre-audit-v2-$ts-files.tgz"
}

smoke() {
  local fail=0 rel code have col
  local pages=(staff_audit.php attendance_audit.php manual_attendance.php manual_pay_entry.php edit_shift.php)
  for rel in "${pages[@]}"; do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$URL/$rel" || echo 000)
    echo "HTTP $code $rel"
    case $code in
      200|301|302|303|401|403) ;;
      *) echo "SMOKE FAIL $rel"; fail=1 ;;
    esac
  done
  if [[ -f $APP/staff_audit.php ]] && grep -q 'manual_pay_entry.php?lock=1' "$APP/staff_audit.php"; then
    echo "SMOKE FAIL staff_audit still has the per-row Manual Pay link"
    fail=1
  fi
  if [[ -f $APP/staff_audit.php ]] && ! grep -q 'aa-manual-badge' "$APP/staff_audit.php"; then
    echo "SMOKE FAIL staff_audit is missing the manual badge"
    fail=1
  fi
  if [[ -f $APP/payroll_master_ledger.php ]] && ! grep -q 'Manual Pay / Adjustments' "$APP/payroll_master_ledger.php"; then
    echo "SMOKE FAIL the ledger Manual Pay entry is missing"
    fail=1
  fi
  have=$(mysql -N -B "$DB" -e "SHOW TABLES LIKE 'attendance_audit'")
  echo "attendance_audit=$have"
  if [[ $have != attendance_audit ]]; then
    echo "SMOKE FAIL attendance_audit table"
    fail=1
  fi
  for col in created_by updated_by; do
    have=$(mysql -N -B "$DB" -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='attendance' AND COLUMN_NAME='$col'")
    echo "attendance.$col=$have"
    if [[ ${have:-0} != 1 ]]; then
      echo "SMOKE FAIL attendance.$col"
      fail=1
    fi
  done
  return $fail
}

go() {
  require_root
  check || { echo "go refused: check did not pass. Nothing was changed."; exit 1; }
  install_list
  local ts
  ts=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$BK"
  chmod 700 "$BK" || true
  mysqldump --single-transaction "$DB" | gzip > "$BK/pre-audit-v2-$ts.sql.gz"
  : > "$BK/pre-audit-v2-$ts-files.list"
  : > "$BK/pre-audit-v2-$ts-added.txt"
  local rel
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    if [[ -e $APP/$rel ]]; then
      echo "$rel" >> "$BK/pre-audit-v2-$ts-files.list"
    else
      echo "$rel" >> "$BK/pre-audit-v2-$ts-added.txt"
    fi
  done < "$MERGED/install.txt"
  if [[ -s $BK/pre-audit-v2-$ts-files.list ]]; then
    tar -czpf "$BK/pre-audit-v2-$ts-files.tgz" -C "$APP" -T "$BK/pre-audit-v2-$ts-files.list"
  else
    tar -czpf "$BK/pre-audit-v2-$ts-files.tgz" --files-from=/dev/null
  fi
  local created=0 created_by=0 updated_by=0
  if [[ $(mysql -N -B "$DB" -e "SHOW TABLES LIKE 'attendance_audit'") != attendance_audit ]]; then
    created=1
  fi
  created_by=$(mysql -N -B "$DB" -e "SELECT IF(COUNT(*)=0,1,0) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='attendance' AND COLUMN_NAME='created_by'")
  updated_by=$(mysql -N -B "$DB" -e "SELECT IF(COUNT(*)=0,1,0) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='attendance' AND COLUMN_NAME='updated_by'")
  printf 'CREATED_ATTENDANCE_AUDIT=%s\nCREATED_CREATED_BY=%s\nCREATED_UPDATED_BY=%s\n' "$created" "$created_by" "$updated_by" > "$BK/pre-audit-v2-$ts.state"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-audit-v2-$ts.sql.gz"
  echo "$BK/pre-audit-v2-$ts-files.tgz"
  local spec
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    mkdir -p "$APP/$(dirname "$rel")"
    spec=$(neighbour_spec "$APP/$rel")
    cp -f "$MERGED/$rel" "$APP/$rel"
    apply_spec "$APP/$rel" "$spec"
    if [[ $rel == *.php ]] && ! php -l "$APP/$rel"; then
      echo "php -l failed on $rel"
      restore_files "$ts"
      echo "ROLLBACK POINT=$ts"
      exit 1
    fi
  done < "$MERGED/install.txt"
  if ! mysql "$DB" < "$APP/migrations/20261120_attendance_audit.sql"; then
    echo "attendance audit migration failed"
    restore_files "$ts"
    db_undo_this_run "$ts"
    echo "ROLLBACK POINT=$ts"
    exit 1
  fi
  if ! smoke; then
    echo "smoke test failed"
    restore_files "$ts"
    db_undo_this_run "$ts"
    echo "ROLLBACK POINT=$ts"
    exit 1
  fi
  echo "DEPLOY OK"
  echo "NOT LOGGED YET"
  grep '^SKIP ' "$MERGED/plan.txt" || true
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-audit-v2-$ts.sql.gz"
  echo "$BK/pre-audit-v2-$ts-files.tgz"
}

selftest() {
  local repo fixture rel ledger_md5
  repo=${GIT:-$(pwd)}
  fixture=$(mktemp -d)
  local base=9cf017e5702bd03dacfe0be3954ad487ef8522f3
  while IFS='|' read -r rel _b _h added; do
    [[ -z $rel || $added == 1 ]] && continue
    mkdir -p "$fixture/$(dirname "$rel")"
    git -C "$repo" show "$base:$rel" > "$fixture/$rel"
  done <<< "$FILES"
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    mkdir -p "$fixture/$(dirname "$rel")"
    git -C "$repo" show "$base:$rel" > "$fixture/$rel"
    python3 - "$fixture/$rel" "$rel" << 'PY'
import pathlib, sys
path, rel = sys.argv[1], sys.argv[2]
text = pathlib.Path(path).read_text()
guard = "require_once __DIR__ . '/engine/orgscope_guard.php'; osg_page(['level'=>20,'fence'=>['id'=>'attendance']]); // org-scope audit Nov 2026\n"
if rel.startswith("engine/"):
    guard = "require_once __DIR__ . '/orgscope_guard.php'; osg_page(['level'=>20]); // org-scope audit Nov 2026\n"
if rel.startswith("imports/"):
    guard = "require_once __DIR__ . '/../engine/orgscope_guard.php'; osg_page(['level'=>20]); // org-scope audit Nov 2026\n"
lines = text.splitlines(keepends=True)
lines.insert(1, guard)
lines.insert(2, "/* server hotfix */\n")
pathlib.Path(path).write_text("".join(lines))
PY
  done <<< "$ANCHOR"
  printf 'ledger sentinel\n' > "$fixture/payroll_master_ledger.php"
  printf 'ledger1 sentinel\n' > "$fixture/payroll_master_ledger1.php"
  ledger_md5=$(md "$fixture/payroll_master_ledger.php")
  AUDIT_ALLOW_NONROOT=1 PAYROLL_APP="$fixture" AUDIT_GIT="$repo" \
    AUDIT_MERGED="$fixture-merged" AUDIT_WORK="$fixture-work" \
    bash "$0" check > "$fixture-check.out"
  cat "$fixture-check.out"
  grep -q '^CHECK OK$' "$fixture-check.out"
  while IFS= read -r rel; do
    grep -q "^CLEAN $rel (anchor patch)$" "$fixture-check.out" || { echo "SELFTEST FAIL $rel"; exit 1; }
    php -l "$fixture-merged/$rel" >/dev/null
  done <<< "$ANCHOR"
  [[ $(md "$fixture/payroll_master_ledger.php") == "$ledger_md5" ]]
  grep -q 'SKIP payroll_master_ledger.php not logged yet' "$fixture-check.out"
  grep -q 'SKIP payroll_master_ledger1.php not logged yet' "$fixture-check.out"
  while IFS= read -r rel; do
    cp -f "$fixture-merged/$rel" "$fixture/$rel"
  done <<< "$ANCHOR"
  AUDIT_ALLOW_NONROOT=1 PAYROLL_APP="$fixture" AUDIT_GIT="$repo" \
    AUDIT_MERGED="$fixture-merged2" AUDIT_WORK="$fixture-work2" \
    bash "$0" check > "$fixture-check2.out"
  while IFS= read -r rel; do
    grep -q "^SAFE $rel (already logged)$" "$fixture-check2.out" || { echo "SELFTEST IDEMPOTENT FAIL $rel"; exit 1; }
  done <<< "$ANCHOR"
  echo "SELFTEST OK"
}

case $MODE in
  check) check ;;
  go) go ;;
  rollback) rollback_cmd "$TSARG" ;;
  selftest) selftest ;;
  *)
    echo "Usage: bash $0 check|go|rollback <ts>"
    exit 2
    ;;
esac
