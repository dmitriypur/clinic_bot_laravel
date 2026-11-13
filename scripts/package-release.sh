#!/usr/bin/env bash
set -euo pipefail

sha=${1:?Commit SHA required}
output=${2:?Absolute output path required}
[[ "$sha" == "$(git rev-parse HEAD)" && "$output" == /* ]]

staging=$(mktemp -d)
trap 'rm -rf -- "$staging"' EXIT
git archive "$sha" | tar -x -C "$staging"
rm -rf "$staging/storage"
rm -f "$staging"/.env.example*
cp -R vendor "$staging/"
for assets in build css fonts js vendor; do
    cp -R "public/$assets" "$staging/public/"
done
test -s "$staging/public/build/manifest.json"
printf '%s\n' "$sha" > "$staging/public/release.txt"

tar --sort=name --mtime="@$(git show -s --format=%ct "$sha")" \
    --owner=0 --group=0 --numeric-owner -C "$staging" -cf - . |
    gzip -n > "$output"
