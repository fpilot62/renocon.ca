#!/bin/bash
# T4127 deploy v4. Root on the payroll server. Read-only until "go" or "aa-apply".
# Installs only the per-period tax rounding fix. Live files are the v3 install.
#   bash deploy_t4127_v4.sh check
#   bash deploy_t4127_v4.sh go
#   bash deploy_t4127_v4.sh rollback <ts>
#   bash deploy_t4127_v4.sh aa-dry
#   bash deploy_t4127_v4.sh aa-apply
#   bash deploy_t4127_v4.sh aa-rollback <ts>
set -euo pipefail
# TARGET=staging|live. Default is live. See ops/deploy_target.inc.sh.
_ops_dir=$(cd "$(dirname "$0")" && pwd)
if [[ -f $_ops_dir/deploy_target.inc.sh ]]; then
  # shellcheck disable=SC1091
  source "$_ops_dir/deploy_target.inc.sh"
fi
unset _ops_dir
M=${1:-check}
export PAYROLL_APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
APP=$PAYROLL_APP
BK=${PAYROLL_BK:-/root/payroll-daily}
DB=${PAYROLL_DB:-admin_payroll}
STAGE=${T4127_STAGE:-/root/t4127-stage}
OWN=${PAYROLL_OWN:-renocon:renocon}
REPO=${T4127_REPO:-}
PINNED=1ca4924fdbfce10ca8bf93d54d33757a638212f8
V3SHA=9cf017e5702bd03dacfe0be3954ad487ef8522f3
RAW=${T4127_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca/$PINNED}
V3MD5=3238b1680ec3b7687cec0c076f890f81
NEWMD5=109cd088063da20cc3fccb05f03ea494
REL=payroll_engine/source_deductions_lib.php
HERE=$(cd "$(dirname "$0")" && pwd)

if [[ ${T4127_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then echo "Run as root."; exit 1; fi
md() { md5sum "$1" | cut -c1-32; }
fetch() {
  local rel=$1 dest=$2
  mkdir -p "$(dirname "$dest")"
  if [[ -n $REPO && -f $REPO/$rel ]]; then cp -f "$REPO/$rel" "$dest"; return; fi
  curl -fsS "$RAW/$rel" -o "$dest"
}
dump_db() {
  mkdir -p "$(dirname "$1")"
  ${T4127_MYSQLDUMP:-mysqldump} --single-transaction "$DB" | gzip -c > "$1"
  [[ -s $1 ]]
}
keep_osg() {
  local live=$1 dest=$2
  local extra
  extra=$(grep -E 'orgscope_guard|osg_page\(|osg_cli_only\(' "$live" || true)
  if [[ -n $extra ]] && ! grep -q 'orgscope_guard' "$dest"; then
    printf '\n%s\n' "$extra" >> "$dest"
  fi
}
pdoc_check() {
  local lib=$1
  php -r '
    require $argv[1];
    $cases = [
      ["ON", 1000, 33.84, 32.16, 66.00, 51.49, 16.30],
      ["ON", 2000, 163.23, 91.60, 254.83, 110.99, 32.60],
      ["SK", 1500, 98.53, 64.10, 162.63, 81.24, 24.45],
      ["ON", 189.50, 0.00, 0.00, 0.00, 3.27, 3.09],
    ];
    $bad = 0;
    foreach ($cases as $c) {
      $r = sd_calculate(["pay_date"=>"2026-11-20","province"=>$c[0],"periods"=>26,"periodic_gross"=>$c[1]]);
      $got = [(float)$r["federal_period"], (float)$r["provincial_period"], (float)$r["income_tax"], (float)$r["cpp"], (float)$r["ei"]];
      $ok = true;
      for ($i = 0; $i < 5; $i++) if (abs($got[$i] - $c[$i+2]) > 0.001) $ok = false;
      $label = $c[0]." ".$c[1];
      if ($ok) echo "PDOC MATCH $label tax ".number_format($got[2],2)." cpp ".number_format($got[3],2)." ei ".number_format($got[4],2)."\n";
      else { echo "PDOC MISMATCH $label got fed {$got[0]} prov {$got[1]} tax {$got[2]} cpp {$got[3]} ei {$got[4]}\n"; $bad++; }
    }
    exit($bad ? 1 : 0);
  ' "$lib"
}
restore_lib() {
  local ts=$1
  local src="$BK/pre-t4127v4-$ts/$REL"
  [[ -f $src ]] || { echo "No library backup at $src"; return 1; }
  mkdir -p "$APP/payroll_engine"
  cp -f "$src" "$APP/$REL"
  chown "$OWN" "$APP/$REL" 2>/dev/null || true
  echo "Restored $REL from pre-t4127v4-$ts"
}

case "$M" in
  check)
    fetch "$REL" "$STAGE/$REL"
    got=$(md "$STAGE/$REL")
    echo "bundle $REL $got"
    [[ $got == "$NEWMD5" ]] || { echo "BUNDLE_MD5_BAD want $NEWMD5"; exit 1; }
    live="$APP/$REL"
    [[ -f $live ]] || { echo "MISSING $live"; exit 1; }
    liveMd=$(md "$live")
    if [[ $liveMd == "$V3MD5" || $liveMd == "$NEWMD5" ]]; then
      echo "CLEAN $REL live $liveMd"
      exit 0
    fi
    base="$STAGE/v3-source_deductions_lib.php"
    mkdir -p "$STAGE"
    if [[ -n $REPO && -e $REPO/.git ]]; then
      git -C "$REPO" show "$V3SHA:$REL" > "$base"
    else
      curl -fsS "https://raw.githubusercontent.com/fpilot62/renocon.ca/$V3SHA/$REL" -o "$base"
    fi
    [[ $(md "$base") == "$V3MD5" ]] || { echo "V3 base hash mismatch"; exit 1; }
    if python3 - "$base" "$live" << 'PY'
import sys
base, live = sys.argv[1], sys.argv[2]
b = open(base).read().splitlines()
l = open(live).read().splitlines()
import difflib
ok = True
for line in difflib.ndiff(b, l):
    if line.startswith('  ') or line.startswith('? '):
        continue
    if line.startswith('- '):
        ok = False
        break
    text = line[2:]
    if 'orgscope_guard' in text or 'osg_page(' in text or 'osg_cli_only(' in text or text.strip() == '':
        continue
    ok = False
    break
sys.exit(0 if ok else 1)
PY
    then
      echo "CLEAN $REL org-scope lines only"
      exit 0
    fi
    echo "DRIFT $REL live $liveMd is not v3 $V3MD5"
    diff -u "$base" "$live" | head -40 || true
    exit 1
    ;;
  go)
    bash "$0" check
    TS=$(date +%Y%m%d-%H%M%S)
    DEST="$BK/pre-t4127v4-$TS"
    mkdir -p "$DEST/payroll_engine"
    cp -f "$APP/$REL" "$DEST/$REL"
    dump_db "$DEST/db.sql.gz"
    echo "Backup $DEST"
    keep_osg "$APP/$REL" "$STAGE/$REL"
    cp -f "$STAGE/$REL" "$APP/$REL"
    chown "$OWN" "$APP/$REL" 2>/dev/null || true
    if ! php -l "$APP/$REL"; then
      echo "php -l failed, restoring"
      restore_lib "$TS"
      exit 1
    fi
    if ! pdoc_check "$APP/$REL"; then
      echo "PDOC mismatch, restoring"
      restore_lib "$TS"
      exit 1
    fi
    echo "Installed $REL from $PINNED. Rollback: bash $0 rollback $TS"
    ;;
  rollback)
    TS=${2:-}
    [[ -n $TS ]] || { echo "Usage: bash $0 rollback <ts>"; exit 1; }
    restore_lib "$TS"
    if [[ -f $BK/pre-t4127v4-$TS/db.sql.gz ]]; then
      gzip -dc "$BK/pre-t4127v4-$TS/db.sql.gz" | ${T4127_MYSQL:-mysql} "$DB"
      echo "Restored database from pre-t4127v4-$TS"
    fi
    ;;
  aa-dry|aa-apply)
    liveMd=$(md "$APP/$REL")
    if [[ $liveMd != "$NEWMD5" ]]; then
      echo "Live library is $liveMd, not the v4 rounding fix. Run: bash $0 go"
      exit 1
    fi
    fetch ops/t4127_aa.php "$STAGE/t4127_aa.php"
    if [[ $M == aa-dry ]]; then
      PAYROLL_APP="$APP" php "$STAGE/t4127_aa.php" dry
      exit 0
    fi
    TS=$(date +%Y%m%d-%H%M%S)
    dump_db "$BK/pre-aa-apply-$TS.sql.gz"
    echo "Backup $BK/pre-aa-apply-$TS.sql.gz"
    if ! PAYROLL_APP="$APP" php "$STAGE/t4127_aa.php" apply; then
      echo "aa-apply failed. Database transaction was rolled back. Full restore: bash $0 aa-rollback $TS"
      exit 1
    fi
    echo "aa-apply done. Restore: bash $0 aa-rollback $TS"
    ;;
  aa-rollback)
    TS=${2:-}
    [[ -n $TS && -f $BK/pre-aa-apply-$TS.sql.gz ]] || { echo "Usage: bash $0 aa-rollback <ts>"; exit 1; }
    gzip -dc "$BK/pre-aa-apply-$TS.sql.gz" | ${T4127_MYSQL:-mysql} "$DB"
    echo "Restored database from pre-aa-apply-$TS.sql.gz"
    ;;
  *)
    echo "Usage: bash $0 check|go|rollback <ts>|aa-dry|aa-apply|aa-rollback <ts>"
    exit 1
    ;;
esac
