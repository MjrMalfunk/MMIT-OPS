#!/usr/bin/env bash
set -Eeuo pipefail

EXPECTED_SHA="${1:-}"
if [[ ! "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]]; then
  echo "Usage: $0 <40-character-commit-sha>" >&2
  exit 2
fi

LIVE="/home/mjrmstlj/ops.midwestmanagedit.com"
BACKUP_ROOT="/home/mjrmstlj/deploy-backups"
FILES=(
  accounting/onedrive_callback.php
  accounting/onedrive_connect.php
  admin/field_calendar.php
  admin/field_opportunities.php
  admin/field_ops.php
  admin/field_work_order.php
  inc/onedrive.php
  inc/outlook_calendar.php
)

if [[ ! -f "$LIVE/admin/field_ops.php" || ! -d "$LIVE/inc" ]]; then
  echo "Live OPS path check failed; nothing changed." >&2
  exit 1
fi

TMP="$(mktemp -d /home/mjrmstlj/ops-outlook-release.XXXXXX)"
BACKUP=""
DEPLOY_STARTED=0
cleanup() { rm -rf -- "$TMP"; }
rollback() {
  local rel
  for rel in "${FILES[@]}"; do
    if [[ -f "$BACKUP/$rel" ]]; then
      install -m 0644 "$BACKUP/$rel" "$LIVE/$rel"
    elif [[ -n "$BACKUP" ]]; then
      rm -f -- "$LIVE/$rel"
    fi
  done
}
on_error() {
  local rc=$?
  if [[ "$DEPLOY_STARTED" == 1 ]]; then
    rollback
    echo "OUTLOOK_CALENDAR_ROLLED_BACK (status $rc)" >&2
  else
    echo "PRECHECK_STOPPED (status $rc); live OPS unchanged" >&2
  fi
  cleanup
  exit "$rc"
}
trap on_error ERR
trap cleanup EXIT

curl -fsSL "https://api.github.com/repos/MjrMalfunk/MMIT-OPS/tarball/$EXPECTED_SHA" -o "$TMP/source.tar.gz"
mkdir "$TMP/source"
tar -xzf "$TMP/source.tar.gz" -C "$TMP/source" --strip-components=1

for rel in "${FILES[@]}" scripts/test_outlook_calendar.php; do
  [[ -f "$TMP/source/$rel" ]]
done
for rel in "${FILES[@]}" scripts/test_outlook_calendar.php; do
  php -l "$TMP/source/$rel"
done
php "$TMP/source/scripts/test_outlook_calendar.php"

mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/ops-outlook-calendar-sync.XXXXXX")"
for rel in "${FILES[@]}"; do
  if [[ -f "$LIVE/$rel" ]]; then
    mkdir -p "$BACKUP/$(dirname "$rel")"
    cp -p "$LIVE/$rel" "$BACKUP/$rel"
  fi
done

DEPLOY_STARTED=1
for rel in "${FILES[@]}"; do
  install -D -m 0644 "$TMP/source/$rel" "$LIVE/$rel"
done
for rel in "${FILES[@]}"; do
  cmp -s "$TMP/source/$rel" "$LIVE/$rel"
done
DEPLOY_STARTED=0
echo "OUTLOOK_CALENDAR_DEPLOY_OK"
echo "Source: $EXPECTED_SHA"
echo "Backup: $BACKUP"
echo "Next: connect Outlook Calendar in OPS, then link only jobs not already entered manually in Outlook."
