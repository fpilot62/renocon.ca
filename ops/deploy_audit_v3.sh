#!/bin/bash
# Attendance audit deploy v3.
# v2 skipped delete_attendance.php, manual_attendance.php, and staff_audit.php
# because its exact text anchors were not in the live files. This script
# patches those three in place. It does not replace any other file and it
# does not change the database.
#
#   bash deploy_audit_v3.sh probe
#   bash deploy_audit_v3.sh check
#   bash deploy_audit_v3.sh go
#   bash deploy_audit_v3.sh rollback <ts>
#
# probe is read-only. check is read-only. go refuses unless check prints CHECK OK.
set -euo pipefail
# TARGET=staging|live. Default is live. See ops/deploy_target.inc.sh.
_ops_dir=$(cd "$(dirname "$0")" && pwd)
if [[ -f $_ops_dir/deploy_target.inc.sh ]]; then
  # shellcheck disable=SC1091
  source "$_ops_dir/deploy_target.inc.sh"
fi
unset _ops_dir

MODE=${1:-}
TSARG=${2:-}
APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
BK=${PAYROLL_BK:-/root/payroll-daily}
MERGED=${AUDIT_MERGED:-/root/audit-v3-merged}
WORK=${AUDIT_WORK:-/root/audit-v3-work}
URL=${PAYROLL_URL:-https://payroll.ecommi.com}
GIT=${AUDIT_GIT:-}

FILES=$(cat <<'EOF'
delete_attendance.php
manual_attendance.php
staff_audit.php
EOF
)

require_root() {
  if [[ ${AUDIT_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then
    echo "Run as root."
    exit 1
  fi
}

md() { md5sum "$1" | cut -c1-32; }

write_patcher() {
  local dest=$1
  cat > "$dest" << 'ENDPY'
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
ENDPY
}

probe_one() {
  local rel=$1
  local live=$APP/$rel
  echo "===== $rel ====="
  if [[ ! -f $live ]]; then
    echo "MISSING $rel"
    return 0
  fi
  echo "md5 $(md "$live")"
  echo "lines $(wc -l < "$live" | tr -d ' ')"
  grep -n -i -C 3 -E 'INSERT INTO|UPDATE |DELETE FROM|attendance|Manual Pay|manual_pay|Remarks|mysqli_query|->query|prepare\(|execute\(' "$live" || true
  echo "===== end $rel ====="
}

probe() {
  local rel
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    probe_one "$rel"
  done <<< "$FILES"
}

anchor_one() {
  local rel=$1
  local live=$APP/$rel
  local out=$MERGED/$rel
  local patcher=$WORK/audit_v3_patch.py
  mkdir -p "$(dirname "$out")" "$WORK"
  write_patcher "$patcher"
  if [[ ! -f $live ]]; then
    echo "SKIP $rel not logged yet (file is missing)"
    echo "SKIP $rel" >> "$MERGED/plan.txt"
    return 0
  fi
  echo "LIVE $rel md5=$(md "$live") lines=$(wc -l < "$live" | tr -d ' ')"
  local msg
  if msg=$(python3 "$patcher" "$rel" "$live" "$out" 2>&1); then
    if ! php -l "$out" >/dev/null 2>&1; then
      echo "SKIP $rel not logged yet (php -l failed on the patched file)"
      php -l "$out" || true
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
  local rel
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    anchor_one "$rel"
  done <<< "$FILES"
  if grep -q '^CONFLICT ' "$MERGED/plan.txt"; then
    echo "CHECK FAILED"
    return 1
  fi
  echo "CHECK OK"
  return 0
}

install_list() {
  local rel
  : > "$MERGED/install.txt"
  while IFS= read -r rel; do
    [[ -n $rel && -f $MERGED/$rel ]] && echo "$rel" >> "$MERGED/install.txt"
  done <<< "$FILES"
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

restore_files() {
  local ts=$1
  local tgz=$BK/pre-audit-v3-$ts-files.tgz
  [[ -f $tgz ]] || { echo "Missing $tgz"; return 1; }
  tar -xzpf "$tgz" -C "$APP"
}

http_ok() {
  case $1 in
    200|301|302|303|307|308|401|403) return 0 ;;
    *) return 1 ;;
  esac
}

http_code() {
  local rel=$1 code
  code=$(curl -skL --max-redirs 5 -o /dev/null -w '%{http_code}' --max-time 25 "$URL/$rel" || echo 000)
  if http_ok "$code"; then
    echo "$code"
    return 0
  fi
  code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 -H 'Host: payroll.ecommi.com' "https://127.0.0.1/$rel" || echo 000)
  if http_ok "$code"; then
    echo "$code"
    return 0
  fi
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -H 'Host: payroll.ecommi.com' "http://127.0.0.1/$rel" || echo 000)
  echo "$code"
}

smoke() {
  local fail=0 rel code
  local pages=(staff_audit.php manual_attendance.php delete_attendance.php attendance_audit.php manual_pay_entry.php)
  for rel in "${pages[@]}"; do
    code=$(http_code "$rel")
    echo "HTTP $code $rel"
    if ! http_ok "$code"; then
      echo "SMOKE FAIL $rel"
      fail=1
    fi
  done
  if grep -q '^CLEAN staff_audit.php' "$MERGED/plan.txt" || grep -q '^SAFE staff_audit.php' "$MERGED/plan.txt"; then
    if grep -q -i '>Manual Pay</a>' "$APP/staff_audit.php"; then
      echo "SMOKE FAIL staff_audit still has the per-row Manual Pay link"
      fail=1
    fi
    if ! grep -q 'aa-manual-badge' "$APP/staff_audit.php"; then
      echo "SMOKE FAIL staff_audit is missing the manual badge"
      fail=1
    fi
    if ! grep -q 'by unknown (before logging)' "$APP/staff_audit.php"; then
      echo "SMOKE FAIL staff_audit is missing the unknown-actor fallback"
      fail=1
    fi
  fi
  return $fail
}

go() {
  require_root
  check || { echo "go refused: check did not pass. Nothing was changed."; exit 1; }
  install_list
  local ts rel spec
  ts=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$BK"
  chmod 700 "$BK" || true
  : > "$BK/pre-audit-v3-$ts-files.list"
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    if [[ -e $APP/$rel ]]; then
      echo "$rel" >> "$BK/pre-audit-v3-$ts-files.list"
    fi
  done < "$MERGED/install.txt"
  if [[ -s $BK/pre-audit-v3-$ts-files.list ]]; then
    tar -czpf "$BK/pre-audit-v3-$ts-files.tgz" -C "$APP" -T "$BK/pre-audit-v3-$ts-files.list"
  else
    tar -czpf "$BK/pre-audit-v3-$ts-files.tgz" --files-from=/dev/null
  fi
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-audit-v3-$ts-files.tgz"
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    spec=$(neighbour_spec "$APP/$rel")
    cp -f "$MERGED/$rel" "$APP/$rel"
    apply_spec "$APP/$rel" "$spec"
    if ! php -l "$APP/$rel"; then
      echo "php -l failed on $rel"
      restore_files "$ts"
      echo "ROLLBACK POINT=$ts"
      echo "$BK/pre-audit-v3-$ts-files.tgz"
      exit 1
    fi
  done < "$MERGED/install.txt"
  if ! smoke; then
    echo "smoke test failed"
    restore_files "$ts"
    echo "ROLLBACK POINT=$ts"
    echo "$BK/pre-audit-v3-$ts-files.tgz"
    exit 1
  fi
  echo "DEPLOY OK"
  echo "NOT LOGGED YET"
  grep '^SKIP ' "$MERGED/plan.txt" || true
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-audit-v3-$ts-files.tgz"
}

rollback_cmd() {
  require_root
  local ts=$1
  [[ -n $ts ]] || { echo "Usage: bash $0 rollback <ts>"; exit 2; }
  restore_files "$ts"
  echo "ROLLBACK DONE"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-audit-v3-$ts-files.tgz"
}

selftest() {
  local repo fixture rel raw
  repo=${GIT:-$(pwd)}
  fixture=$(mktemp -d)
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    git -C "$repo" show "9cf017e5702bd03dacfe0be3954ad487ef8522f3:$rel" > "$fixture/$rel"
    python3 - "$fixture/$rel" << 'PY'
import pathlib, sys
path = pathlib.Path(sys.argv[1])
lines = path.read_text().splitlines(keepends=True)
lines.insert(1, "require_once __DIR__ . '/engine/orgscope_guard.php'; osg_page(['level'=>20]);\n")
lines.insert(2, "/* server hotfix */\n")
path.write_text("".join(lines))
PY
  done <<< "$FILES"
  AUDIT_ALLOW_NONROOT=1 PAYROLL_APP="$fixture" \
    AUDIT_MERGED="$fixture-merged" AUDIT_WORK="$fixture-work" \
    bash "$0" probe > "$fixture-probe.out"
  grep -q 'md5 ' "$fixture-probe.out"
  grep -q 'Manual Pay' "$fixture-probe.out"
  AUDIT_ALLOW_NONROOT=1 PAYROLL_APP="$fixture" \
    AUDIT_MERGED="$fixture-merged" AUDIT_WORK="$fixture-work" \
    bash "$0" check > "$fixture-check.out"
  cat "$fixture-check.out"
  grep -q '^CHECK OK$' "$fixture-check.out"
  while IFS= read -r rel; do
    grep -q "^CLEAN $rel (anchor patch)$" "$fixture-check.out"
    php -l "$fixture-merged/$rel" >/dev/null
  done <<< "$FILES"
  grep -q 'by unknown (before logging)' "$fixture-merged/staff_audit.php"
  if grep -q -i '>Manual Pay</a>' "$fixture-merged/staff_audit.php"; then
    echo "SELFTEST badge failed"
    exit 1
  fi
  while IFS= read -r rel; do
    cp -f "$fixture-merged/$rel" "$fixture/$rel"
  done <<< "$FILES"
  AUDIT_ALLOW_NONROOT=1 PAYROLL_APP="$fixture" \
    AUDIT_MERGED="$fixture-merged2" AUDIT_WORK="$fixture-work2" \
    bash "$0" check > "$fixture-check2.out"
  while IFS= read -r rel; do
    grep -q "^SAFE $rel (already logged)$" "$fixture-check2.out" || { echo "SELFTEST IDEMPOTENT FAIL $rel"; exit 1; }
  done <<< "$FILES"
  printf '%s\n' '<?php' 'echo "untouched";' > "$fixture/manual_attendance.php"
  AUDIT_ALLOW_NONROOT=1 PAYROLL_APP="$fixture" \
    AUDIT_MERGED="$fixture-merged3" AUDIT_WORK="$fixture-work3" \
    bash "$0" check > "$fixture-check3.out"
  grep -q 'SKIP manual_attendance.php not logged yet' "$fixture-check3.out"
  grep -q '^CHECK OK$' "$fixture-check3.out"
  echo "SELFTEST OK"
}

case $MODE in
  probe) probe ;;
  check) check ;;
  go) go ;;
  rollback) rollback_cmd "$TSARG" ;;
  selftest) selftest ;;
  *)
    echo "Usage: bash $0 probe|check|go|rollback <ts>"
    exit 2
    ;;
esac
