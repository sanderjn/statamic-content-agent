#!/usr/bin/env bash
# Reusable content-path allowlist check. Reads a path on stdin; exits 0 for
# editable content/asset paths, non-zero otherwise. Mirrors the CI allowlist
# and the settings.json deny rules. Used by the test suite; a developer can
# wire it into a Claude Code PreToolUse hook for live, friendly enforcement.
set -euo pipefail
read -r file || true
case "$file" in
  content/collections/*/*|content/globals/*|content/taxonomies/*|content/navigation/*|content/trees/*|content/assets/*|public/assets/*|content/AGENTS.md|content/agent-reference.md)
    exit 0 ;;
  content/collections/*.yaml)
    echo "Blocked: $file is collection config (routes/structure), not content." >&2
    exit 1 ;;
  *)
    echo "Blocked: $file is outside the content allowlist — that's a developer job." >&2
    exit 1 ;;
esac
