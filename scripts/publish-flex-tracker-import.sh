#!/usr/bin/env bash
set -euo pipefail
umask 0077
source_ref=${1:?Pass the reviewed 40-character Git commit SHA}
[[ "$source_ref" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid source commit'; exit 1; }
site=/home/mjrmstlj/ops.midwestmanagedit.com
candidate=$(mktemp -d /home/mjrmstlj/ops-flex-import.XXXXXX)
files=(admin/field_rideshare.php inc/field_rideshare.php inc/field_rideshare_mileage.php)
mkdir -p "$candidate/admin" "$candidate/inc" "$candidate/scripts"
for file in "${files[@]}" scripts/test_gig_tracker_import.php; do
  curl --fail --silent --show-error --location "https://raw.githubusercontent.com/MjrMalfunk/MMIT-OPS/$source_ref/$file" -o "$candidate/$file"
done
for file in "${files[@]}" scripts/test_gig_tracker_import.php; do php -l "$candidate/$file"; done
php -d date.timezone=UTC "$candidate/scripts/test_gig_tracker_import.php"
php -d date.timezone=America/Indiana/Indianapolis "$candidate/scripts/test_gig_tracker_import.php"
same=1
for file in "${files[@]}"; do cmp -s "$candidate/$file" "$site/$file" || same=0; done
if [[ "$same" == 1 ]]; then echo 'FLEX_IMPORT_ALREADY_INSTALLED'; exit 0; fi
# Refuse to overwrite a changed live baseline or a different deployment root.
(cd "$site" && sha256sum -c <<'BASELINE'
4786edba3489ed50bc18c013694e1861f6e14862f166b9b0557a03b3c6dff9cb  admin/field_rideshare.php
bcca90f4b29ddbb5fb35a7fea3681a03f73f5024be38a00039a06b8524e8f850  inc/field_rideshare.php
5c77c6adb617ecfa4824a994444fd3bf6c22eaed460d2d3f8d6fe2cea74b41f3  inc/field_rideshare_mileage.php
BASELINE
)
mkdir -p /home/mjrmstlj/deploy-backups
backup=$(mktemp -d /home/mjrmstlj/deploy-backups/ops-flex-import.XXXXXX)
mkdir -p "$backup/admin" "$backup/inc"
for file in "${files[@]}"; do cp -p "$site/$file" "$backup/$file"; done
rollback() {
  result=$?
  trap - ERR
  for file in "${files[@]}"; do cp -p "$backup/$file" "$site/$file"; done
  echo "FLEX_IMPORT_ROLLED_BACK; backup: $backup"
  exit "$result"
}
trap rollback ERR
for file in "${files[@]}"; do install -m 0644 "$candidate/$file" "$site/$file"; done
for file in "${files[@]}"; do cmp -s "$candidate/$file" "$site/$file"; done
trap - ERR
echo 'FLEX_IMPORT_DEPLOY_OK'
printf 'Source: %s\nBackup: %s\n' "$source_ref" "$backup"
echo 'Refresh OPS, open Edit on the existing manual outing, choose its JSON file, then Preview. Nothing is saved until you click Save.'
