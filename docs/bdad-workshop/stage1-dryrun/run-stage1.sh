#!/usr/bin/env bash
# BDAD Swarm Stage 1 dry run (intake -> STOP 1) with a readable live console.
#
#   run-stage1.sh <TICKET> [ARTIFACT_DIR]     live run (spends AI credits, ~6-8 min)
#   run-stage1.sh --replay <ARTIFACT_DIR> [SPEED]   replay a recorded run (no credits, no network)
#   run-stage1.sh --emulate <ARTIFACT_DIR>          sequential, condensed walkthrough of a recorded run (Enter = next step)
#
# Harness = docs/bdad-new-ticket-runbook.md §2. Anything not allowed below is denied (-p cannot prompt).
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(git -C "$HERE" rev-parse --show-toplevel)"
CONSOLE=(python3 "$HERE/swarm_console.py")

if [[ "${1:-}" == "--replay" ]]; then
  [[ -f "${2:-}/stream.jsonl" ]] || { echo "usage: $0 --replay <ARTIFACT_DIR> [SPEED]" >&2; exit 2; }
  A="$2" exec "${CONSOLE[@]}" --replay "$2/stream.jsonl" --speed "${3:-10}"
fi

if [[ "${1:-}" == "--emulate" ]]; then
  [[ -f "${2:-}/stream.jsonl" ]] || { echo "usage: $0 --emulate <ARTIFACT_DIR>" >&2; exit 2; }
  exec python3 "$HERE/swarm_emulate.py" "$2" "${@:3}"
fi

TICKET="${1:?usage: $0 <TICKET> [ARTIFACT_DIR]}"
A="${2:-$HOME/bdad-dryrun/$TICKET-$(date +%Y%m%d-%H%M%S)}"
case "$A" in "$REPO"/*) echo "ARTIFACT_DIR must be outside the repository" >&2; exit 2 ;; esac
mkdir -p "$A/logs"
A="$(cd "$A" && pwd)"
export A
sed -e "s#{{TICKET}}#$TICKET#g" -e "s#{{A}}#$A#g" "$HERE/prompt.template.txt" > "$A/prompt.txt"

cd "$REPO"
git status --porcelain | shasum > "$A/git-status-before.sha"
date -u +%FT%TZ > "$A/started.txt"
echo "Ticket: $TICKET   Repo: $REPO   Artifacts: $A"

set +e
copilot -p "$(cat "$A/prompt.txt")" --agent orchestrator --no-ask-user --trust-folder --add-dir "$A" \
  --allow-tool='read' --allow-tool="write($A/**)" \
  --allow-tool='glean(search)' --allow-tool='glean(read_document)' --allow-tool='glean(chat)' \
  --allow-tool='scout' --deny-tool='scout(memory_write)' --deny-tool='scout(memory_vote)' \
  --allow-tool='shell(git:*)' \
  --deny-tool='shell(git push)' --deny-tool='shell(git commit)' --deny-tool='shell(git checkout)' \
  --deny-tool='shell(git switch)' --deny-tool='shell(git reset)' --deny-tool='shell(git restore)' \
  --deny-tool='shell(git stash)' --deny-tool='shell(git add)' --deny-tool='shell(git rebase)' \
  --deny-tool='shell(git merge)' --deny-tool='shell(git cherry-pick)' --deny-tool='shell(git clean)' \
  --deny-tool='shell(git tag)' --deny-tool='shell(git branch)' \
  --allow-tool='shell(ls)' --allow-tool='shell(cat)' --allow-tool='shell(grep)' --allow-tool='shell(rg)' \
  --allow-tool='shell(head)' --allow-tool='shell(sed)' --allow-tool='shell(wc)' --allow-tool='shell(date)' \
  --deny-tool='shell(php)' --deny-tool='shell(bin/magento)' --deny-tool='shell(mysql)' \
  --deny-tool='shell(docker)' --deny-tool='shell(rm)' \
  --usage-output-file "$A/usage.json" --share "$A/transcript.md" --log-dir "$A/logs" \
  --output-format json 2> "$A/stderr.txt" | tee "$A/stream.jsonl" | "${CONSOLE[@]}"
rc=${PIPESTATUS[0]}
set -e

date -u +%FT%TZ > "$A/finished.txt"
if git status --porcelain | shasum | cmp -s - "$A/git-status-before.sha"; then repo="unchanged"; else repo="CHANGED"; fi
echo
echo "Exit code: $rc   Repository: $repo"
for f in execution-log.md '*scribe.md' '*dependency-tracer.md' stop1.md usage.json; do
  m=$(compgen -G "$A/$f" | head -1 || true)
  [[ -n "$m" && -s "$m" ]] && echo "  ✔ ${m##*/}" || echo "  ✖ $f (missing)"
done
grep -q '```gherkin' "$A/stop1.md" 2>/dev/null && echo "  ✔ behavior contract in stop1.md" || echo "  ✖ no gherkin contract in stop1.md"
echo "STOP 1: $A/stop1.md"
echo "Walkthrough: $0 --emulate $A"
exit "$rc"
