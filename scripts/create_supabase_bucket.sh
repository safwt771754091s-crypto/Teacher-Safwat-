#!/usr/bin/env bash
#
# Create the public Supabase Storage bucket that holds textbook files.
#
# Creating a bucket needs an elevated credential: the publishable/anon key is
# rejected by row-level security. Provide ONE of the following (first match wins):
#
#   SUPABASE_ACCESS_TOKEN      personal access token (sbp_...) from
#                              https://supabase.com/dashboard/account/tokens
#   SUPABASE_SERVICE_ROLE_KEY  the project's service_role key (Project Settings -> API)
#
# Usage:
#   SUPABASE_ACCESS_TOKEN=sbp_xxx bash scripts/create_supabase_bucket.sh
#   SUPABASE_SERVICE_ROLE_KEY=eyJ... bash scripts/create_supabase_bucket.sh
#
# Idempotent: re-running when the bucket already exists just reports success.
set -u

REF="${SUPABASE_PROJECT_REF:-jsrpfsybgeplciiqjzqr}"
BUCKET="${SUPABASE_S3_BUCKET:-textbooks}"
API="https://api.supabase.com/v1/projects/${REF}/storage/buckets"
STORAGE="https://${REF}.supabase.co/storage/v1/bucket"
BODY="{\"name\":\"${BUCKET}\",\"id\":\"${BUCKET}\",\"public\":true}"

http() { # method url auth_header data
    curl -s -m 20 -o /tmp/sb_bucket_body -w "%{http_code}" -X "$1" "$2" \
        -H "Authorization: Bearer $3" -H "Content-Type: application/json" \
        ${4:+--data "$4"}
}

report() { # code
    if [ "$1" = "200" ] || [ "$1" = "201" ]; then
        echo "✅ bucket '${BUCKET}' is ready (public) on project ${REF}."
        exit 0
    fi
    if [ "$1" = "409" ]; then
        echo "✅ bucket '${BUCKET}' already exists on project ${REF}."
        exit 0
    fi
    echo "⚠️  request returned HTTP $1:"; cat /tmp/sb_bucket_body; echo
}

# The bucket may already exist; a plain create would 409, which we treat as done.
if [ -n "${SUPABASE_ACCESS_TOKEN:-}" ]; then
    echo "→ Creating '${BUCKET}' via the Management API (access token)…"
    report "$(http POST "$API" "$SUPABASE_ACCESS_TOKEN" "$BODY")"
elif [ -n "${SUPABASE_SERVICE_ROLE_KEY:-}" ]; then
    echo "→ Creating '${BUCKET}' via the Storage API (service_role key)…"
    report "$(http POST "$STORAGE" "$SUPABASE_SERVICE_ROLE_KEY" "$BODY")"
else
    echo "✋ Cannot create the bucket without an elevated credential." >&2
    echo "   The publishable/anon key is blocked by row-level security (HTTP 403)." >&2
    echo "   Provide one of:" >&2
    echo "     SUPABASE_ACCESS_TOKEN=sbp_... bash scripts/create_supabase_bucket.sh" >&2
    echo "     SUPABASE_SERVICE_ROLE_KEY=... bash scripts/create_supabase_bucket.sh" >&2
    echo "   Or create it in the dashboard: Storage → New bucket → name '${BUCKET}', Public." >&2
    exit 1
fi

# The credential was accepted but the request failed for another reason.
exit 1
