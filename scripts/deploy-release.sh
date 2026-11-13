#!/usr/bin/env bash
set -euo pipefail
umask 027

target=${1:-}
sha=${2:-}
digest=${3:-}
[[ $EUID == 0 && $# == 3 && "$sha" =~ ^[a-f0-9]{40}$ && "$digest" =~ ^[a-f0-9]{64}$ ]]
case "$target" in
    test)
        state=/var/www/dev-test
        live=$state/current
        app_user=adminzrenie-test
        database=adminzrenie_test
        shared=$state/shared
        ;;
    production)
        state=/var/www/medical-center-releases
        live=/var/www/medical-center
        app_user=www-data
        database=medical_center
        shared=$state/legacy
        ;;
    *) exit 2 ;;
esac

cd /
backups=/var/backups/adminzrenie
pending=/var/www/medical-center-releases/pending.tar.gz
production_state=/var/www/medical-center-releases
exec 9>"$production_state/deploy.lock"
flock -w 90 9

assert_main() {
    local main
    main=$(timeout 25 git ls-remote https://github.com/dmitriypur/clinic_bot_laravel.git refs/heads/main | cut -f1)
    [[ "$main" == "$sha" ]] || { echo "main changed to $main, skipping $sha" >&2; exit 1; }
}

require_disk() {
    local free
    free=$(df -B1 --output=avail "$state" | tail -1)
    [[ "$free" -ge "$1" ]] || { echo "Insufficient free disk space ($free bytes)" >&2; exit 1; }
}

artisan() {
    local directory=$1
    shift
    (cd "$directory" && runuser -u "$app_user" -- /usr/bin/php8.3 artisan "$@" --no-interaction --no-ansi)
}

exchange() {
    python3 - "$1" "$2" <<'PY'
import ctypes
import os
import sys

libc = ctypes.CDLL(None, use_errno=True)
if libc.renameat2(-100, os.fsencode(sys.argv[1]), -100, os.fsencode(sys.argv[2]), 2):
    error = ctypes.get_errno()
    raise OSError(error, os.strerror(error))
PY
}

point_live() {
    ln -s "$1" "$state/next"
    mv -Tf "$state/next" "$live"
}

verify() {
    /usr/local/lib/adminzrenie/verify-release.sh "$target" "$sha" "$@"
}

new_release=
previous=
first_production=false
switched=false
upload=
backup_temp=
maintenance=false
cleanup() {
    local status=$?
    trap - EXIT
    if (( status != 0 )); then
        set +e
        if [[ "$switched" == true ]]; then
            echo "Restoring the previous code path. Database migrations, if applied, are retained." >&2
            if [[ "$first_production" == true ]]; then
                exchange "$live" "$shared"
                rm -f "$shared"
            elif [[ -n "$previous" ]]; then
                point_live "$previous"
            else
                rm -f "$live"
            fi
            if [[ "$target" == production ]]; then
                artisan "$live" queue:restart
            fi
        fi
        if [[ "$maintenance" == true ]]; then
            artisan "$live" up
        fi
        if [[ -n "$new_release" && "$(readlink -f "$live")" != "$new_release" ]]; then
            rm -rf -- "$new_release"
        fi
        if [[ "$target" == test ]]; then
            rm -f "$pending"
        fi
    fi
    if [[ -n "$upload" ]]; then rm -f "$upload"; fi
    if [[ -n "$backup_temp" ]]; then rm -f "$backup_temp"; fi
    rm -f "$state/next"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

assert_main
require_disk $((1536 * 1024 * 1024))
if [[ "$target" == test ]]; then
    upload=$(mktemp "$production_state/upload.XXXXXXXX")
    # Cap the incoming compressed archive at 128 MiB.
    head -c 134217729 > "$upload"
    [[ $(stat -c %s "$upload") -le 134217728 ]]
    mv -Tf "$upload" "$pending"
    upload=
else
    /usr/local/lib/adminzrenie/verify-release.sh test "$sha"
fi

printf '%s  %s\n' "$digest" "$pending" | sha256sum -c
new_release=$(mktemp -d "$state/releases/$sha.XXXXXXXX")
# Admit regular files and directories only. Bound extracted data before writing it.
expanded=$(python3 - "$pending" <<'PY'
import sys
import tarfile
from pathlib import PurePosixPath

size = 0
with tarfile.open(sys.argv[1], "r:gz") as archive:
    for member in archive:
        name = PurePosixPath(member.name)
        if name.is_absolute() or ".." in name.parts or not (member.isfile() or member.isdir()):
            raise SystemExit(f"Unexpected archive member {member.name}")
        size += member.size
        if size > 512 * 1024 * 1024:
            raise SystemExit("Release exceeds 512 MiB")
print(size)
PY
)
require_disk "$((expanded + 1280 * 1024 * 1024))"
tar --no-same-owner --no-same-permissions -xzf "$pending" -C "$new_release"
[[ "$(< "$new_release/public/release.txt")" == "$sha" ]]
test -s "$new_release/vendor/autoload.php"
test -s "$new_release/public/build/manifest.json"
[[ ! -e "$new_release/.env" && ! -e "$new_release/storage" && ! -e "$new_release/public/storage" ]]
if [[ -L "$live" ]]; then
    previous=$(readlink -f "$live")
elif [[ "$target" == production ]]; then
    first_production=true
    [[ ! -e "$shared" && ! -L "$shared" ]]
    [[ "$(git -C "$live" rev-parse HEAD)" == bd4ec4677bcf0ca921b43dcae8ef41394258c997 ]]
fi

if [[ "$first_production" == true ]]; then
    # Resolve the existing shared files until the original directory moves to legacy.
    shared_source=$live
else
    shared_source=$shared
fi
ln -s "$shared_source/.env" "$new_release/.env"
ln -s "$shared_source/storage" "$new_release/storage"
ln -s ../storage/app/public "$new_release/public/storage"
chgrp "$app_user" "$new_release"
chmod 0750 "$new_release"
if [[ "$target" == test ]]; then
    # Nginx serves static files as www-data. The private env and storage retain separate permissions.
    chmod 0755 "$new_release"
fi
# Laravel must regenerate package/config caches for this release.
chown "$app_user:$app_user" "$new_release/bootstrap/cache"
chmod 0750 "$new_release/bootstrap/cache"

backup="$backups/$target-$(date -u +%Y%m%dT%H%M%S)-$sha.sql.gz"
backup_temp=$(mktemp "$backups/.dump.XXXXXXXX")
mysqldump --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF "$database" | gzip -n > "$backup_temp"
gzip -t "$backup_temp"
gzip -dc "$backup_temp" | awk '/^-- Dump completed/{complete=1} END {exit !complete}'
mv -T "$backup_temp" "$backup"
backup_temp=
(cd "$backups" && sha256sum "$(basename "$backup")" > "$backup.sha256")
printf 'Verified database dump %s\n' "$backup"

if [[ "$target" == production ]]; then
    artisan "$new_release" booking:verify-monitoring-config
fi
assert_main
if [[ -e "$live/artisan" ]] && ! artisan "$new_release" migrate:status --pending >/dev/null; then
    maintenance=true
    artisan "$live" down --retry=60
fi
artisan "$new_release" migrate --force
if [[ "$target" == test ]]; then
    artisan "$new_release" db:seed --class=StagingSeeder --force
fi
artisan "$new_release" config:cache
artisan "$new_release" route:cache

assert_main
if [[ "$target" == production ]]; then
    verify_test=/usr/local/lib/adminzrenie/verify-release.sh
    "$verify_test" test "$sha"
fi

if [[ "$first_production" == true ]]; then
    ln -sfn "$shared/.env" "$new_release/.env"
    ln -sfn "$shared/storage" "$new_release/storage"
    ln -s "$new_release" "$shared"
    if ! exchange "$live" "$shared"; then
        rm -f "$shared"
        exit 1
    fi
else
    point_live "$new_release"
fi
switched=true
if [[ "$maintenance" == true ]]; then
    artisan "$live" up
    maintenance=false
fi
if [[ "$target" == production ]]; then
    artisan "$live" queue:restart
    systemctl is-active --quiet cron
fi
verify loopback
verify
if [[ "$target" == production ]]; then
    rm -f "$pending"
fi

trap - EXIT HUP INT TERM
# Keep the active release and two older managed releases; legacy stays outside this directory.
mapfile -t releases < <(find "$state/releases" -mindepth 1 -maxdepth 1 -type d \
    -name '????????????????????????????????????????.????????' -printf '%T@ %p\n' | sort -nr | cut -d ' ' -f2-)
kept=0
for release in "${releases[@]}"; do
    if [[ "$release" == "$new_release" ]]; then continue; fi
    if (( kept < 2 )); then
        kept=$((kept + 1))
    else
        rm -rf -- "$release"
    fi
done
mapfile -t dumps < <(find "$backups" -maxdepth 1 -type f -name "$target-*.sql.gz" -printf '%f\n' | sort -r)
for dump in "${dumps[@]:5}"; do
    rm -f -- "$backups/$dump" "$backups/$dump.sha256"
done
