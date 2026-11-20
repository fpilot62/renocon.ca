#!/usr/bin/env python3
"""Looser in-place patches for the three live files v2 skipped.

Anchors are the attendance INSERT/UPDATE/DELETE (or att_soft_delete_sql)
and the Manual Pay label. A missing anchor is reported and the file is
left unchanged.
"""
import re
import sys


class Miss(Exception):
    pass


LIB_LINE = (
    "if (is_file(__DIR__ . '/engine/attendance_audit_lib.php')) { "
    "require_once __DIR__ . '/engine/attendance_audit_lib.php'; }\n"
)

NOTES = {
    ("manual_attendance.php", "create"): "Manual shift",
    ("delete_attendance.php", "delete"): "Soft delete",
    ("staff_audit.php", "create"): "Duplicated shift",
    ("staff_audit.php", "delete"): "Staff audit delete",
    ("staff_audit.php", "update"): "Staff audit update",
}


def _norm(text):
    return text.replace("\r\n", "\n"), ("\r\n" if "\r\n" in text else "\n")


def _calls(text):
    """End offsets of execute/query/exec/mysqli_query statements."""
    hits = []
    for match in re.finditer(r"(?i)(?:mysqli_query\s*\(|->\s*(?:execute|query|exec)\s*\()", text):
        paren = text.find("(", match.start())
        i = paren + 1
        depth = 1
        quote = None
        while i < len(text) and depth:
            ch = text[i]
            if quote:
                if ch == "\\":
                    i += 2
                    continue
                if ch == quote:
                    quote = None
            elif ch in ("'", '"'):
                quote = ch
            elif ch == "(":
                depth += 1
            elif ch == ")":
                depth -= 1
            i += 1
        if depth != 0:
            continue
        j = i
        while j < len(text) and text[j] in " \t\r\n":
            j += 1
        if j < len(text) and text[j] == ";":
            j += 1
            hits.append(j)
    return hits


def _kind(window):
    soft = list(re.finditer(r"(?i)att_soft_delete_sql\s*\(", window))
    writes = list(re.finditer(
        r"(?is)\b(insert\s+into|update|delete\s+from)\s+[`\"']?([A-Za-z_][A-Za-z0-9_]*)",
        window,
    ))
    if not writes and not soft:
        return None
    if writes:
        kind, table = writes[-1].group(1).lower(), writes[-1].group(2).lower()
        if table != "attendance":
            return None
        if kind.startswith("insert"):
            return "create"
        if kind.startswith("delete"):
            return "delete"
        tail = window[writes[-1].start():]
        if re.search(r"(?is)\bset\b[^;]{0,400}\bdeleted_at\b", tail):
            return "delete"
        return "update"
    return "delete"


def _window(text, end, prev_end):
    start = max(prev_end, end - 8000)
    return text[start:end]


def _already(text, end):
    return "aa_log" in text[end:end + 400]


def _id_name(window, kind):
    if kind == "create":
        return None
    for name in ("clean_ids", "ids", "shift_ids", "del_ids", "fixIds", "id", "shift_id", "att_id"):
        if re.search(r"\$%s\b" % name, window):
            return name
    return None


def _create_target(text, end):
    """Use an id variable the page already stored, so aa_log does not steal lastInsertId."""
    nxt = text[end:end + 350]
    match = re.match(
        r"\s*(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:\(int\))?\s*\$pdo->lastInsertId\s*\(\s*\)\s*;",
        nxt,
    )
    if not match:
        return end, None
    return end + match.end(), match.group(1)


def _log_line(rel, kind, window, indent, create_var=None):
    note = NOTES.get((rel, kind), kind)
    if kind == "create":
        target = ("(int)%s" % create_var) if create_var else "(int)$pdo->lastInsertId()"
        return (
            "%sif (function_exists('aa_log')) { aa_log($pdo, 'create', %s, null, null, '%s'); }\n"
            % (indent, target, note)
        )
    name = _id_name(window, kind)
    if not name:
        return None
    if name in ("id", "shift_id", "att_id"):
        return (
            "%sif (function_exists('aa_log')) { aa_log($pdo, '%s', (int)$%s, null, null, '%s'); }\n"
            % (indent, kind, name, note)
        )
    return (
        "%sif (function_exists('aa_log_ids') && isset($%s)) { aa_log_ids($pdo, '%s', $%s, [], '%s'); }\n"
        % (indent, name, kind, name, note)
    )


def _indent_at(text, end):
    line = text.rfind("\n", 0, end) + 1
    return re.match(r"[ \t]*", text[line:]).group(0)


def _ensure_lib(text):
    if "attendance_audit_lib.php" in text:
        return text
    found = re.search(r"<\?php\b", text, re.I)
    if not found:
        raise Miss("no opening php tag")
    nl = text.find("\n", found.end())
    if nl < 0:
        return text + "\n" + LIB_LINE
    return text[:nl + 1] + LIB_LINE + text[nl + 1:]


def _writes(text, rel):
    ends = _calls(text)
    found = []
    prev = 0
    for end in ends:
        window = _window(text, end, prev)
        kind = _kind(window)
        prev = end
        if not kind:
            continue
        insert_at = end
        create_var = None
        if kind == "create":
            insert_at, create_var = _create_target(text, end)
        if _already(text, insert_at):
            found.append((insert_at, kind, None))
            continue
        if "$pdo" not in text:
            raise Miss("attendance %s found but the file has no $pdo" % kind)
        line = _log_line(rel, kind, window, _indent_at(text, insert_at), create_var)
        if line is None:
            if kind == "update":
                continue
            raise Miss("attendance %s execute found but no id variable nearby" % kind)
        found.append((insert_at, kind, line))
    return found


def _apply_writes(text, rel, need):
    found = _writes(text, rel)
    kinds = {}
    for _end, kind, _line in found:
        kinds[kind] = kinds.get(kind, 0) + 1
    missing = [kind for kind in need if kinds.get(kind, 0) == 0]
    if missing:
        raise Miss("no attendance %s statement found" % " or ".join(missing))
    # Insert from the bottom so earlier offsets stay valid.
    for end, _kind, line in sorted(found, key=lambda item: item[0], reverse=True):
        if line:
            text = text[:end] + "\n" + line + text[end:]
    return text


def _in_php(text, pos):
    opens = [m.start() for m in re.finditer(r"<\?php\b", text[:pos], re.I)]
    closes = [m.end() for m in re.finditer(r"\?>", text[:pos])]
    return len(opens) > len(closes)


def _manual_pay_spans(text):
    spans = []
    for match in re.finditer(r"(?i)>\s*Manual\s+Pay\s*</a>", text):
        start = max(text.rfind("<a", 0, match.start()), text.rfind("<A", 0, match.start()))
        if start < 0:
            continue
        between = text[start:match.start()]
        if re.search(r"(?i)</a>", between):
            continue
        if _in_php(text, start):
            continue
        spans.append((start, match.end(), text[start:match.end()]))
    return spans


def _manual_pay(text):
    spans = _manual_pay_spans(text)
    html = spans

    def score(span):
        start, end, raw = span
        pre = text[max(0, start - 900):end]
        points = 0
        if re.search(r"(?i)manual_pay_entry", pre):
            points += 2
        if re.search(r"(?i)mpe-row-link", raw):
            points += 2
        return points

    if not html:
        raise Miss("Manual Pay link not found")
    if len(html) == 1:
        return html[0]
    ranked = sorted(html, key=score, reverse=True)
    if score(ranked[0]) > score(ranked[1]):
        return ranked[0]
    raise Miss("found %d Manual Pay links" % len(html))


def _row_var(text, span):
    start, end, _raw = span
    window = text[max(0, start - 1200):end + 800]
    found = re.findall(r"(\$[A-Za-z_][A-Za-z0-9_]*)\s*\[\s*['\"]id['\"]\s*\]", window)
    if found:
        return found[-1]
    raise Miss("Manual Pay link found but no row id variable nearby")


def _badge(row):
    return (
        "<?php\n"
        "$aaBadge = '';\n"
        "$aaRid = (int)(%s['id'] ?? 0);\n"
        "if ($aaRid > 0 && isset($pdo) && function_exists('aa_manual_badges')) {\n"
        "    $aaOne = aa_manual_badges($pdo, [$aaRid]);\n"
        "    $aaBadge = (string)($aaOne[$aaRid] ?? '');\n"
        "}\n"
        "if ($aaBadge === '') {\n"
        "    $aaRemark = trim((string)(%s['remarks'] ?? %s['Remarks'] ?? ''));\n"
        "    if ($aaRemark === 'Manually Injected Shift') {\n"
        "        $aaBadge = 'Manually added · by unknown (before logging)';\n"
        "    }\n"
        "}\n"
        "if ($aaBadge !== ''): ?>\n"
        "<span class=\"aa-manual-badge\" style=\"display:inline-block;margin-top:4px;font-size:10px;font-weight:700;line-height:1.3;color:#7c2d12;background:#ffedd5;border:1px solid #fdba74;border-radius:999px;padding:2px 7px;\"><?= htmlspecialchars($aaBadge, ENT_QUOTES, 'UTF-8') ?></span>\n"
        "<?php endif; ?>\n"
    ) % (row, row, row)


def _apply_badge(text):
    if "aa-manual-badge" in text and not _manual_pay_spans(text):
        return text
    start, end, _raw = _manual_pay(text)
    row = _row_var(text, (start, end, _raw))
    return text[:start] + _badge(row) + text[end:]


def patch(rel, text):
    body, nl = _norm(text)
    if rel == "delete_attendance.php":
        out = _ensure_lib(_apply_writes(body, rel, ["delete"]))
    elif rel == "manual_attendance.php":
        out = _ensure_lib(_apply_writes(body, rel, ["create"]))
    elif rel == "staff_audit.php":
        out = _apply_badge(_ensure_lib(_apply_writes(body, rel, ["create", "delete"])))
    else:
        raise Miss("no patch for " + rel)
    if "aa_log" not in out and "aa_log_ids" not in out:
        raise Miss("patch produced no audit call")
    if rel == "staff_audit.php":
        if _manual_pay_spans(out):
            raise Miss("Manual Pay link still present")
        if "Manually added · by unknown (before logging)" not in out and "aa_manual_badges" not in out:
            raise Miss("manual badge was not added")
    if nl != "\n":
        out = out.replace("\n", nl)
    return out


if __name__ == "__main__":
    if len(sys.argv) != 4:
        print("usage: audit_v3_patch.py REL SRC DEST", file=sys.stderr)
        sys.exit(2)
    rel, src, dest = sys.argv[1:]
    raw = open(src, encoding="utf-8", errors="surrogateescape").read()
    try:
        done = patch(rel, raw)
    except Miss as exc:
        print("MISS %s %s" % (rel, exc))
        sys.exit(1)
    open(dest, "w", encoding="utf-8", errors="surrogateescape").write(done)
    print("PATCHED %s" % rel)
