#!/bin/bash
# Read-only. Downloads the repo copies at PINNED into /tmp and diffs them
# against the live payroll tree. Writes nothing outside /tmp.
#   bash compare_superadmin_access_v1.sh
set -euo pipefail
PINNED=c9cdd55c77714ce43638f4c8e4767e5e64c4aa70
LIVE=${PAYROLL_APP:-/home/renocon/public_html/payroll}
REPO=${COMPARE_REPO:-}
DEST=/tmp/compare-superadmin-access-$PINNED
FILES='engine/privacy_lib.php payroll_eht_report.php'
mkdir -p "$DEST/engine"
fetch_one() {
  local rel=$1 dest=$2
  if [[ -n $REPO && -d $REPO/.git ]]; then
    git -C "$REPO" show "$PINNED:$rel" > "$dest"
    return
  fi
  curl -fsS "https://raw.githubusercontent.com/fpilot62/renocon.ca/$PINNED/$rel" -o "$dest"
}
for rel in $FILES; do
  repo_file="$DEST/$rel"
  live_file="$LIVE/$rel"
  fetch_one "$rel" "$repo_file"
  echo "repo $rel $(md5sum "$repo_file" | cut -c1-32)"
  if [[ -f $live_file ]]; then
    echo "live $rel $(md5sum "$live_file" | cut -c1-32)"
    diff -u "$repo_file" "$live_file" || true
  else
    echo "live $rel MISSING"
  fi
  echo
done
if [[ -d $LIVE/.git ]]; then
  echo "git -C $LIVE show 41cc5e2 --stat"
  git -C "$LIVE" show 41cc5e2 --stat || echo "git show 41cc5e2 failed"
else
  echo "no git repo at $LIVE"
fi
echo "Repo copies are in $DEST. Nothing was written outside /tmp."
