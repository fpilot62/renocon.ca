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
