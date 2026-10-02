#!/usr/bin/env bash
# Verify GitHub's release-asset redirect behaviour the Blueline updater relies on.
#   BLUELINE_GITHUB_TOKEN=... ./scripts/verify-github-asset-redirect.sh <owner/repo> <asset_id>
# The token is read from the environment only (optional: absent = unauthenticated,
# fine for a public repo). It is never printed: no `set -x`, no header echo, and
# the signed-storage query string is dropped from the output.
#
# Expects: API answers 302 for `Accept: application/octet-stream`, and the
# signed storage URL then answers 200 WITHOUT any Authorization header.
set -euo pipefail

REPO="${1:?usage: $0 <owner/repo> <asset_id>}"
ASSET="${2:?usage: $0 <owner/repo> <asset_id>}"
API="https://api.github.com/repos/${REPO}/releases/assets/${ASSET}"

auth=()
if [ -n "${BLUELINE_GITHUB_TOKEN:-}" ]; then
	auth=( -H "Authorization: Bearer ${BLUELINE_GITHUB_TOKEN}" )
fi

headers="$(curl -s -o /dev/null -D - --max-redirs 0 \
	-H 'Accept: application/octet-stream' "${auth[@]}" "$API" | tr -d '\r')"

status="$(printf '%s\n' "$headers" | head -n1)"
location="$(printf '%s\n' "$headers" | awk 'tolower($1)=="location:" {print $2}')"
echo "step 1 (API, with auth if token set): ${status}"

if ! printf '%s' "$status" | grep -q ' 302'; then
	echo "FAIL: expected a 302 from the asset API" >&2
	exit 1
fi
if [ -z "$location" ]; then
	echo "FAIL: 302 without a Location header" >&2
	exit 1
fi

host="$(printf '%s' "$location" | sed -E 's#^(https?)://([^/?:]+).*#\1 \2#')"
echo "redirect scheme and host: ${host}"

# Deliberately NO Authorization header on the second request.
second="$(curl -s -o /dev/null -D - --max-time 30 -r 0-0 "$location" | tr -d '\r' | head -n1)"
echo "step 2 (storage, no auth): ${second}"

if ! printf '%s' "$second" | grep -Eq ' (200|206)'; then
	echo "FAIL: storage URL did not serve the asset without auth" >&2
	exit 1
fi
echo "OK"
