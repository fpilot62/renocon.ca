#!/bin/bash
# T4127 deploy v2. Root on the payroll server. Read-only until "go".
#   bash deploy_t4127_v2.sh audit
#   bash deploy_t4127_v2.sh check
#   bash deploy_t4127_v2.sh org4
#   bash deploy_t4127_v2.sh go
#   bash deploy_t4127_v2.sh rollback <ts>
set -euo pipefail
# TARGET=staging|live. Default is live. See ops/deploy_target.inc.sh.
_ops_dir=$(cd "$(dirname "$0")" && pwd)
if [[ -f $_ops_dir/deploy_target.inc.sh ]]; then
  # shellcheck disable=SC1091
  source "$_ops_dir/deploy_target.inc.sh"
fi
unset _ops_dir
M=${1:-audit}
export PAYROLL_APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
APP=$PAYROLL_APP
BK=${PAYROLL_BK:-/root/payroll-daily}
DB=${PAYROLL_DB:-admin_payroll}
URL=${PAYROLL_URL:-https://payroll.renocon.ca}
AUDIT=${T4127_AUDIT:-/root/t4127-audit}
MERGED=${T4127_MERGED:-/root/t4127-merged}
STAGE=${T4127_STAGE:-/root/t4127-stage}
OWN=${PAYROLL_OWN:-renocon:renocon}
REPO=${T4127_REPO:-}
RAW=${T4127_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca/cursor/t4127-source-deductions-154c}
HERE=$(cd "$(dirname "$0")" && pwd)

# path | Nov 19 md5, or - when the file is new | bundle md5
MAN='payroll_engine/payroll_engine.php ab4136aad3ab78759569aa82d89ea998 e4b27a545b24bd1ffe639a7ce2d70e3d
manual_pay_entry.php 8cb18b215d208933bed9be61f851e3ff 6891a558cb73ca52ee8e31ce3808f58a
generate_stat_shifts.php 0ef627cdf7d73d33deaec23a9f39cf64 9730267ff0f7e4a4e5df90c762fffca5
payroll_master_ledger.php 563ff63b0c366b401c47a5ddd5f78ac9 602706a4729b27a050362f8cb2e20be6
bulk_finalize_handler.php 40d4974818de7369b0c6330b4c3d01cc 2f3a31e691ddd674fbb4846ba7aa76b7
bulk_finalize_autosplit.php e9180002f11725e56a96592fcb99e15e 8a7e409747103341a7991c36eba6dde7
process_audit.php 930a3969499f76969f1620c81396769f 5c0dd1232634d16a22c2800a70bc666a
process_payroll.php 300a7182019f5c0b895273655cea572f 4f791e50e76a570a3e7c4c21e19c3f28
generate_pay_documents.php d892d12c72e1e7e59b5574db6fa387f2 ba49bb0fcde80ccc6bbae07a25670275
finalize_shift.php 8953ce6206d11d3a35a0c434704b0c16 3342a25751b36ad936e03e140d3c6d07
save_split_payroll.php 4b1b0c9f827ab519211ed3a9ec261915 441d3e356320a3b7599eae56fd62486a
payroll_engine/stat_holidays.php 4c39289df0512e374e23eeab5b6a4e0a 1cb3906b1f306b6fc2cc3c6c83c9ed9a
payroll_processor.php 07915a69576897a98cbf4b3b434232b6 90bfc4bd4c06ece007cdbb7142890f0d
process_payroll_logic.php 3d9e26a3c6e658f2130c4e3e6e501318 967f0dbd0661596d35feb913d305725d
payroll_split_80.php a4a29976603d73b2f7f6944da66ca088 0768bee5ca63df7f14c0984ec34cfee7
payroll_command_center.php 8fd0a197b7a56742ad3bdfad909588c0 bf0c9b1730fbbb8d0086fbd8a515ed2f
payroll_compliance_desk.php bbccb8f00379ca31b6b8c68acc3fb259 62b0ec00ec75386a6e3cb4dbbc007721
payroll_engine/t4127_bridge.php - 5b155bf158f7d1875e6a2e3e25512d0f
payroll_engine/source_deductions_lib.php - 3238b1680ec3b7687cec0c076f890f81
payroll_engine/rates/2026-01-01.php - 11ff45b559fcc31b03ae7e69b81e440b
payroll_engine/rates/2026-07-01.php - c1a23dc795060eb905417efdc46e815c
payroll_engine/seed_rates.php - 008915f21d39d58d02ee452ec985ba6b
payroll_engine/page_gate.php - 7723a481fbe922bb776c06cec0dd0e16
payroll_eht_report.php - 681b66d0c836d3a8f734c093522e601f
migrations/20261120_source_deductions.sql - 275ff2af5939d7bc998caa1260d407b5
docs/SOURCE_DEDUCTIONS.md - b714bd8760c18f694ac8b15788d8f9d6
docs/STAT_PAY.md - e682ba2a97c56a0f6d306ae47fa0ebeb'

if [[ ${T4127_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then echo "Run as root."; exit 1; fi
md() { md5sum "$1" | cut -c1-32; }
q() { ${T4127_MYSQL:-mysql} -N -B "$DB" -e "$1"; }
fetch() {
  local rel=$1 dest=$2 kind=$3
  mkdir -p "$(dirname "$dest")"
  if [[ -n $REPO && -f $REPO/$rel && $kind == theirs ]]; then cp -f "$REPO/$rel" "$dest"; return; fi
  if [[ -n $REPO && -f $REPO/ops/t4127_base/$rel && $kind == base ]]; then cp -f "$REPO/ops/t4127_base/$rel" "$dest"; return; fi
  if [[ $kind == base && -f $HERE/t4127_base/$rel ]]; then cp -f "$HERE/t4127_base/$rel" "$dest"; return; fi
  if [[ $kind == theirs && -f $HERE/../$rel ]]; then cp -f "$HERE/../$rel" "$dest"; return; fi
  local url="$RAW/$rel"
  [[ $kind == base ]] && url="$RAW/ops/t4127_base/$rel"
  curl -fsS "$url" -o "$dest"
}
tainted_hunk() {
  local h=$1 add
  add=$(printf '%s\n' "$h" | grep '^+' | grep -v '^+++' || true)
  [[ -z $add ]] && return 1
  printf '%s\n' "$add" | grep -Eiq 'org_id[^0-9]{0,20}4|AA Group|org 4' || return 1
  printf '%s\n' "$add" | grep -Eiq 'cpp|ei|tax|wcb|wsib|deduct|net' || return 1
  return 0
}
prepare_one() {
  local p=$1 bmd5=$2 nmd5=$3
  local base=$STAGE/base/$p theirs=$STAGE/theirs/$p live=$APP/$p san=$STAGE/san/$p out=$MERGED/$p
  mkdir -p "$(dirname "$base")" "$(dirname "$theirs")" "$(dirname "$san")" "$(dirname "$out")"
  fetch "$p" "$theirs" theirs
  [[ $(md "$theirs") == "$nmd5" ]] || { echo "BUNDLE_MD5_BAD $p"; return 1; }
  if [[ $bmd5 == - ]]; then
    cp -f "$theirs" "$out"
    echo "NEW $p $(md "$out")"
    return 0
  fi
  fetch "$p" "$base" base
  [[ $(md "$base") == "$bmd5" ]] || { echo "BASE_MD5_BAD $p"; return 1; }
  if [[ ! -f $live ]]; then echo "MISSING_LIVE $p"; return 1; fi
  local live_md5; live_md5=$(md "$live")
  if [[ $live_md5 == "$nmd5" ]]; then cp -f "$theirs" "$out"; echo "ALREADY_DEPLOYED $p"; return 0; fi
  cp -f "$live" "$san"
  if [[ $live_md5 != "$bmd5" ]]; then
    python3 - "$base" "$san" "$p" "$MERGED/flags.txt" << 'PY'
import subprocess, sys, pathlib
base, san, rel, flagfile = sys.argv[1:5]
diff = subprocess.run(["diff", "-u", base, san], capture_output=True, text=True).stdout
lines = diff.splitlines(True)
if not lines:
    sys.exit(0)
header, hunks, cur = [], [], None
for ln in lines:
    if ln.startswith("@@"):
        if cur is not None:
            hunks.append(cur)
        cur = [ln]
    elif cur is None:
        header.append(ln)
    else:
        cur.append(ln)
if cur:
    hunks.append(cur)
def tainted(h):
    add = "".join(x[1:] for x in h if x.startswith("+") and not x.startswith("+++"))
    low = add.lower()
    org = ("org_id" in low and "4" in low) or "aa group" in low or "org 4" in low
    money = any(w in low for w in ("cpp", "ei", "tax", "wcb", "wsib", "deduct", "net"))
    return org and money
work = san
for i, h in enumerate(hunks):
    if not tainted(h):
        continue
    pathlib.Path(flagfile).parent.mkdir(parents=True, exist_ok=True)
    with open(flagfile, "a") as fh:
        fh.write(f"FLAG org-4 deduction tweak dropped before merge: {rel} hunk {i+1}\n")
    print(f"FLAG org-4 deduction tweak dropped before merge: {rel} hunk {i+1}")
    patch = "".join(header) + "".join(h)
    path = pathlib.Path(work)
    # Reverse this hunk against the current sanitized file. Later hunks are rebased by applying bottom-up.
    pass
# Apply tainted hunks in reverse order so line numbers stay valid.
for i in range(len(hunks) - 1, -1, -1):
    if not tainted(hunks[i]):
        continue
    patch = "".join(header) + "".join(hunks[i])
    proc = subprocess.run(["git", "apply", "-p0", "--reverse", "--unsafe-paths", "--whitespace=nowarn", "-"], input=patch, text=True, cwd="/")
    if proc.returncode != 0:
        print(f"FLAG reverse failed {rel} hunk {i+1}")
PY
  fi
  local rc=0
  if git merge-file -p -L live -L nov19 -L t4127 "$san" "$base" "$theirs" > "$out"; then rc=0; else rc=$?; fi
  if [[ $rc -gt 0 ]]; then echo "CONFLICT $p"; return 1; fi
  if [[ $rc -lt 0 ]]; then echo "MERGE_ERROR $p"; return 1; fi
  echo "CLEAN $p $(md "$out")"
  if [[ $p == *.php ]] && ! php -l "$out" >/dev/null 2>&1; then echo "LINT_FAIL $p"; return 1; fi
  return 0
}
build_merge() {
  rm -rf "$STAGE" "$MERGED"
  mkdir -p "$STAGE" "$MERGED"
  : > "$MERGED/flags.txt"
  local bad=0 p b n
  while read -r p b n; do
    prepare_one "$p" "$b" "$n" || bad=1
    if [[ -f $APP/$p && -f $MERGED/$p ]]; then
      echo "---- diff live merged (first 80) $p"
      diff -u "$APP/$p" "$MERGED/$p" | head -80 || true
    fi
  done <<< "$MAN"
  return $bad
}
audit() {
  mkdir -p "$AUDIT"
  local p b n
  while read -r p b n; do
    [[ $b == - ]] && continue
    [[ -f $APP/$p ]] || { echo "MISSING_LIVE $p" | tee -a "$AUDIT/summary.txt"; continue; }
    fetch "$p" "$AUDIT/base/$p" base
    local name=${p//\//__}
    diff -u "$AUDIT/base/$p" "$APP/$p" > "$AUDIT/$name.diff" || true
    local add del
    add=$(grep -c '^+' "$AUDIT/$name.diff" || true)
    del=$(grep -c '^-' "$AUDIT/$name.diff" || true)
    echo "DIFF $p +$add -$del" | tee -a "$AUDIT/summary.txt"
    if tainted_hunk "$(cat "$AUDIT/$name.diff")"; then echo "FLAG org-4 deduction tweak in $p" | tee -a "$AUDIT/summary.txt"; fi
  done <<< "$MAN"
  : > "$AUDIT/grep.txt"
  find "$APP" -type d \( -iname '*backup*' -o -iname 'bak' -o -iname 'old' -o -iname '.git' \) -prune -o -type f -name '*.php' -print0 |
  while IFS= read -r -d '' f; do
    local rel=${f#"$APP"/}
    if grep -nE 'org_id[[:space:]]*===?[[:space:]]*4|org_id[[:space:]]*=[[:space:]]*4([^0-9]|$)' "$f" >/dev/null 2>&1; then
      grep -nE 'org_id[[:space:]]*===?[[:space:]]*4|org_id[[:space:]]*=[[:space:]]*4([^0-9]|$)' "$f" | sed "s|^|$rel:|" >> "$AUDIT/grep.txt" || true
    fi
    if grep -nE '0\.0595|0\.0163|0\.0166|0\.1505|0\.0506|0\.0495' "$f" >/dev/null 2>&1; then
      grep -nE '0\.0595|0\.0163|0\.0166|0\.1505|0\.0506|0\.0495' "$f" | sed "s|^|RATE $rel:|" >> "$AUDIT/grep.txt" || true
    fi
    if grep -niE 'override|adjust|match|target' "$f" | grep -niE 'net|tax|cpp|ei' >/dev/null 2>&1; then
      grep -niE 'override|adjust|match|target' "$f" | grep -iE 'net|tax|cpp|ei' | sed "s|^|WORD $rel:|" >> "$AUDIT/grep.txt" || true
    fi
    local engine=0 flat=0 write=0
    grep -qE 'pe_line\(|pe_line_for\(|pe_refresh_period_stat\(|sd_calculate\(|sh_after_finalize\(|sh_post_stat_line\(' "$f" && engine=1 || true
    grep -qE '0\.0595|0\.0163|0\.0166|0\.1505|0\.0506|\* *0\.15|\* *0\.12' "$f" && flat=1 || true
    grep -qE 'INSERT[[:space:]]+INTO[[:space:]]+`?(payroll_finalized|payroll_records|payroll_period_stat)|UPDATE[[:space:]]+`?(payroll_finalized|payroll_records|payroll_period_stat)' "$f" && write=1 || true
    local mark=""
    if [[ $engine -eq 1 && $flat -eq 1 ]]; then mark=both
    elif [[ $engine -eq 1 ]]; then mark=engine
    elif [[ $flat -eq 1 || $write -eq 1 ]]; then mark=flat
    fi
    if [[ -n $mark ]]; then echo "$mark write=$write $rel"; fi
  done | tee "$AUDIT/engine_or_flat.txt" | tee -a "$AUDIT/summary.txt"
  echo "---- grep hits (first 80) ----"
  head -80 "$AUDIT/grep.txt" || true
  {
    echo "---- payroll_settings ----"
    q "SHOW COLUMNS FROM payroll_settings" || true
    q "SELECT * FROM payroll_settings" || true
    echo "---- deduction-like columns ----"
    q "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND (COLUMN_NAME LIKE '%cpp%' OR COLUMN_NAME LIKE '%ei%' OR COLUMN_NAME LIKE '%tax%' OR COLUMN_NAME LIKE '%wcb%' OR COLUMN_NAME LIKE '%wsib%' OR COLUMN_NAME LIKE '%deduct%' OR COLUMN_NAME='province') ORDER BY TABLE_NAME, COLUMN_NAME" || true
    echo "---- org 4 manual-edit counts ----"
    local col
    while read -r col; do
      [[ $col =~ ^[A-Za-z0-9_]+$ ]] || continue
      echo -n "$col "
      q "SELECT COUNT(*) FROM payroll_finalized f INNER JOIN users u ON u.id=f.user_id AND u.org_id=4 WHERE f.\`$col\` IS NOT NULL AND f.\`$col\` NOT IN ('', '0')" || true
    done < <(q "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='payroll_finalized' AND (COLUMN_NAME LIKE '%manual%' OR COLUMN_NAME LIKE '%edit%' OR COLUMN_NAME LIKE '%adjust%' OR COLUMN_NAME LIKE '%override%')" || true)
  } > "$AUDIT/sql.txt" 2>&1 || true
  echo "---- sql (first 60) ----"
  head -60 "$AUDIT/sql.txt" || true
  echo "Audit files are in $AUDIT"
}
verify() {
  local f=0
  php -r '
    require getenv("PAYROLL_APP")."/payroll_engine/payroll_engine.php";
    require getenv("PAYROLL_APP")."/db.php";
    $r=sd_calculate(["pay_date"=>"2026-01-15","periods"=>24,"province"=>"BC","periodic_gross"=>5000]);
    exit((abs($r["cpp"]-288.82)<.01 && abs($r["ei"]-81.50)<.01 && abs($r["income_tax"]-1040.58)<.01)?0:3);
  ' || { echo "CALC_MISMATCH_OR_ERROR"; f=1; }
  local p c
  for p in payroll_master_ledger.php finalize_shift.php payroll_eht_report.php index.php; do
    c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$URL/$p" || echo 000)
    echo "HTTP $c $p"
    [[ $c == 5* || $c == 000 ]] && f=1
  done
  return $f
}
rollback() {
  local ts=$1
  tar -xzpf "$BK/pre-t4127-files_$ts.tar.gz" -C "$APP"
  if [[ -s $BK/pre-t4127-created_$ts.txt ]]; then
    while read -r p; do rm -f "$APP/$p"; done < "$BK/pre-t4127-created_$ts.txt"
  fi
  if [[ -s $BK/pre-t4127-province_$ts.sql ]]; then ${T4127_MYSQL:-mysql} "$DB" < "$BK/pre-t4127-province_$ts.sql"; fi
  echo "ROLLBACK DONE ts=$ts"
}
case $M in
  audit) audit ;;
  check)
    build_merge && echo "CHECK OK. Nothing installed." || { echo "CHECK FAILED. Nothing installed."; exit 1; }
    ;;
  org4)
    export PAYROLL_APP="$APP" T4127_LIB="$RAW/payroll_engine/source_deductions_lib.php"
    if [[ -f $HERE/../payroll_engine/source_deductions_lib.php ]]; then T4127_LIB=$HERE/../payroll_engine/source_deductions_lib.php; fi
    if [[ -f $HERE/t4127_org4_dryrun.php ]]; then php "$HERE/t4127_org4_dryrun.php"; else
      curl -fsS "$RAW/ops/t4127_org4_dryrun.php" -o "$STAGE/org4.php"
      T4127_LIB=$STAGE/lib.php
      curl -fsS "$RAW/payroll_engine/source_deductions_lib.php" -o "$T4127_LIB"
      php "$STAGE/org4.php"
    fi
    ;;
  go)
    build_merge || { echo "CHECK FAILED - aborting, nothing changed"; exit 1; }
    if grep -q . "$MERGED/flags.txt"; then echo "Flags (tweaks were not carried forward):"; cat "$MERGED/flags.txt"; fi
    ts=$(date +%Y%m%d-%H%M%S)
    mkdir -p "$BK"; chmod 700 "$BK" || true
    ${T4127_MYSQLDUMP:-mysqldump} --single-transaction --no-tablespaces "$DB" | gzip > "$BK/pre-t4127-db_$ts.sql.gz"
    : > "$BK/pre-t4127-files_$ts.list"; : > "$BK/pre-t4127-created_$ts.txt"
    while read -r p b n; do
      if [[ -e $APP/$p ]]; then echo "$p" >> "$BK/pre-t4127-files_$ts.list"; else echo "$p" >> "$BK/pre-t4127-created_$ts.txt"; fi
    done <<< "$MAN"
    tar -czpf "$BK/pre-t4127-files_$ts.tar.gz" -C "$APP" -T "$BK/pre-t4127-files_$ts.list"
    if ! ${T4127_MYSQL:-mysql} "$DB" < "$MERGED/migrations/20261120_source_deductions.sql"; then
      echo "MIGRATION FAILED - no files installed"; exit 1
    fi
    while read -r p b n; do
      mkdir -p "$APP/$(dirname "$p")"
      cp -f "$MERGED/$p" "$APP/$p"
      if id "${OWN%%:*}" >/dev/null 2>&1; then chown "$OWN" "$APP/$p"; fi
      chmod 644 "$APP/$p"
    done <<< "$MAN"
    if ! verify; then echo "VERIFY FAILED - rolling back"; rollback "$ts"; exit 1; fi
    q "SELECT MD5(GROUP_CONCAT(CONCAT(SId,'=',IFNULL(province,'~')) ORDER BY SId)) FROM sites WHERE org_id<>2" > "$STAGE/other_prov.txt" || true
    q "UPDATE sites SET province='BC' WHERE org_id=2 AND (province IS NULL OR TRIM(province)='')"
    echo "DEPLOY OK ts=$ts. Org 4 provinces were not updated."
    ;;
  rollback) rollback "${2:?ts}" ;;
  *) echo "Usage: bash $0 audit|check|org4|go|rollback <ts>"; exit 2 ;;
esac
