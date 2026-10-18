#!/usr/bin/env bash
# Snapshot the repository so any change can be rolled back.
#
# Every snapshot is an annotated git tag (snapshot-<UTC date>-<short sha>) with a
# dated comment, pushed to origin so it survives sandbox resets. A GitHub Actions
# workflow (.github/workflows/snapshot.yml) creates the same tag on every push to
# main; use this script for an on-demand snapshot from the workspace.
#
#   bash scripts/snapshot.sh                 # snapshot HEAD
#   bash scripts/snapshot.sh "before X"      # snapshot HEAD with a comment
#   bash scripts/snapshot.sh list            # list snapshots (newest last)
#   bash scripts/snapshot.sh show <tag>      # read a snapshot's comment
#   bash scripts/snapshot.sh restore <tag>   # create a branch at <tag> to inspect
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 1

action="${1:-save}"

case "$action" in
    list)
        git fetch --tags --quiet 2>/dev/null || true
        git tag -l 'snapshot-*' --sort=creatordate
        ;;
    show)
        tag="${2:?usage: snapshot.sh show <tag>}"
        git tag -l "$tag" | grep -q . || { echo "no such snapshot: $tag"; exit 1; }
        git tag -n99 "$tag"
        ;;
    restore)
        tag="${2:?usage: snapshot.sh restore <tag>}"
        git tag -l "$tag" | grep -q . || { echo "no such snapshot: $tag"; exit 1; }
        branch="restore/${tag}"
        git switch -c "$branch" "$tag" && \
            echo "created branch ${branch} at ${tag} (inspect, then merge or reset as needed)"
        ;;
    *)
        comment="$*"
        git config user.name  >/dev/null 2>&1 || git config user.name "openhands"
        git config user.email >/dev/null 2>&1 || git config user.email "openhands@all-hands.dev"
        sha="$(git rev-parse HEAD)"
        short="$(git rev-parse --short HEAD)"
        ts="$(date -u +%Y-%m-%d-%H%M%S)"
        tag="snapshot-${ts}-${short}"
        {
            echo "Snapshot taken $(date -u +'%Y-%m-%d %H:%M:%S UTC')"
            echo
            echo "commit:  ${sha}"
            echo "subject: $(git log -1 --pretty=%s)"
            if [ -n "$comment" ]; then
                echo
                echo "comment: ${comment}"
            fi
            echo
            echo "Restore with: git checkout ${tag}"
        } > "/tmp/snapshot-msg.$$"
        git tag -a "$tag" -F "/tmp/snapshot-msg.$$"
        rm -f "/tmp/snapshot-msg.$$"
        GIT_TERMINAL_PROMPT=0 git push origin "$tag" >/dev/null 2>&1 \
            && echo "snapshot created and pushed: ${tag}" \
            || echo "snapshot created locally: ${tag} (push failed; run: git push origin ${tag})"
        ;;
esac
