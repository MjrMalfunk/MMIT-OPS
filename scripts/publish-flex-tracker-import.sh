#!/usr/bin/env bash
set -euo pipefail
umask 0077
source_ref=${1:?Pass the reviewed 40-character Git commit SHA}
[[ "$source_ref" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid source commit'; exit 1; }
site=/home/mjrmstlj/ops.midwestmanagedit.com
candidate=$(mktemp -d /home/mjrmstlj/ops-gig-import.XXXXXX)
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
if [[ "$same" == 1 ]]; then echo 'GIG_TRACKER_IMPORT_ALREADY_INSTALLED'; exit 0; fi
# Refuse to overwrite a changed live baseline or a different deployment root.
(cd "$site" && sha256sum -c <<'BASELINE'
3e62121ff19f2c086d59fa03292937a63d745b4107c051c67d2b96c05dbcfac6  admin/field_rideshare.php
e857122b968bc71dfb6c0069468c7628306e2940aa19a7a7cd6d72bee04cc3e9  inc/field_rideshare.php
f25de54b2eb30f17ebdca0501f289740b802d413d70b45f30f556b5c159921c2  inc/field_rideshare_mileage.php
BASELINE
)
mkdir -p /home/mjrmstlj/deploy-backups
backup=$(mktemp -d /home/mjrmstlj/deploy-backups/ops-gig-import.XXXXXX)
mkdir -p "$backup/admin" "$backup/inc"
for file in "${files[@]}"; do cp -p "$site/$file" "$backup/$file"; done
rollback() {
  result=$?
  trap - ERR
  for file in "${files[@]}"; do cp -p "$backup/$file" "$site/$file"; done
  echo "GIG_TRACKER_IMPORT_ROLLED_BACK; backup: $backup"
  exit "$result"
}
trap rollback ERR
for file in "${files[@]}"; do install -m 0644 "$candidate/$file" "$site/$file"; done
for file in "${files[@]}"; do cmp -s "$candidate/$file" "$site/$file"; done
trap - ERR
echo 'GIG_TRACKER_IMPORT_DEPLOY_OK'
printf 'Source: %s\nBackup: %s\n' "$source_ref" "$backup"
echo 'Refresh OPS, open Edit on the existing manual outing, choose its JSON file, then Preview. Nothing is saved until you click Save.'
