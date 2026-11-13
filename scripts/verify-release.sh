#!/usr/bin/env bash
set -euo pipefail

target=${1:?Deployment target required}
sha=${2:?Commit SHA required}
[[ "$sha" =~ ^[a-f0-9]{40}$ ]]

case "$target" in
    test) host=test.adminzrenie.ru ;;
    production) host=adminzrenie.ru ;;
    *) exit 2 ;;
esac

fetch=(curl --fail --silent --show-error --max-time 15 --retry 2 --proto '=https')
if [[ ${3:-} == loopback ]]; then
    fetch+=(--resolve "$host:443:127.0.0.1")
fi
base="https://$host"
[[ "$("${fetch[@]}" "$base/release.txt?sha=$sha")" == "$sha" ]]
"${fetch[@]}" "$base/up" >/dev/null
payload=$("${fetch[@]}" -H 'Accept: application/json' "$base/api/v1/cities")
if [[ "$target" == test ]]; then
    jq -e 'any(.data[]; .name == "Тестовый город" and .status == 1)' <<< "$payload" >/dev/null
else
    jq -e '.data | type == "array" and length > 0' <<< "$payload" >/dev/null
fi
printf '%s verified at %s\n' "$sha" "$base"
