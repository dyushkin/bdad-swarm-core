#!/usr/bin/env bash
# BDAD Swarm Stage 3 dry run (STOP 2 approved -> Patch Generator -> Green -> STOP 3) with a readable live console.
#
#   run-stage3.sh <TICKET> [ARTIFACT_DIR]          live run (spends AI credits; edits Newsletter.php in the repo, uncommitted)
#   run-stage3.sh --replay <ARTIFACT_DIR> [SPEED]  replay a recorded run (no credits, no network)
#
# Input: stop2-decisions.md (human STOP 2 decisions) and Stage 2 artifacts (S2, default: the Stage 2 reference run).
# Precondition: the approved Red test NewsletterTest.php is in the working tree and Newsletter.php is unmodified.
# Harness = runbook §2 plus php (lint + PHPUnit) and a single production-file write (Newsletter.php).
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(git -C "$HERE" rev-parse --show-toplevel)"
CONSOLE=(python3 "$HERE/../stage1-dryrun/swarm_console.py")

if [[ "${1:-}" == "--replay" ]]; then
  [[ -f "${2:-}/stream.jsonl" ]] || { echo "usage: $0 --replay <ARTIFACT_DIR> [SPEED]" >&2; exit 2; }
  A="$2" exec "${CONSOLE[@]}" --replay "$2/stream.jsonl" --speed "${3:-10}"
fi

TICKET="${1:?usage: $0 <TICKET> [ARTIFACT_DIR]}"
A="${2:-$HOME/bdad-dryrun/$TICKET-stage3-$(date +%Y%m%d-%H%M%S)}"
S2="${S2:-$HERE/../stage2-dryrun/reference}"
S2="$(cd "$S2" && pwd)"
case "$A" in "$REPO"/*) echo "ARTIFACT_DIR must be outside the repository" >&2; exit 2 ;; esac
mkdir -p "$A/logs"
A="$(cd "$A" && pwd)"
export A
cp "$HERE/stop2-decisions.md" "$A/stop2-decisions.md"
sed -e "s#{{TICKET}}#$TICKET#g" -e "s#{{A}}#$A#g" -e "s#{{S2}}#$S2#g" "$HERE/prompt.template.txt" > "$A/prompt.txt"

COND=Magento/CustomerSegment/Model/Segment/Condition/Customer
FIX_EE="$REPO/magento2ee/app/code/$COND/Newsletter.php"
FIX_LINK="$REPO/app/code/$COND/Newsletter.php"
TEST="$REPO/magento2ee/dev/tests/integration/testsuite/$COND/NewsletterTest.php"
[[ -f "$TEST" ]] || { echo "Precondition failed: approved Red test missing: $TEST" >&2; exit 2; }
git -C "$REPO/magento2ee" diff --quiet -- "app/code/$COND/Newsletter.php" || { echo "Precondition failed: Newsletter.php already modified" >&2; exit 2; }

cd "$REPO"
git status --porcelain > "$A/git-status-before.txt"
git -C magento2ee status --porcelain > "$A/git-status-ee-before.txt"
date -u +%FT%TZ > "$A/started.txt"
shasum "$TEST" > "$A/test-sha-before.txt"
echo "Ticket: $TICKET   Repo: $REPO   Stage 2: $S2   Artifacts: $A"

set +e
copilot -p "$(cat "$A/prompt.txt")" --agent orchestrator --no-ask-user --trust-folder --add-dir "$A" \
  --allow-tool='read' --allow-tool="write($A/**)" \
  --allow-tool="write($FIX_EE)" --allow-tool="write($FIX_LINK)" \
  --allow-tool='glean(search)' --allow-tool='glean(read_document)' \
  --allow-tool='scout' --deny-tool='scout(memory_write)' --deny-tool='scout(memory_vote)' \
  --allow-tool='shell(git:*)' \
  --deny-tool='shell(git push)' --deny-tool='shell(git commit)' --deny-tool='shell(git checkout)' \
  --deny-tool='shell(git switch)' --deny-tool='shell(git reset)' --deny-tool='shell(git restore)' \
  --deny-tool='shell(git stash)' --deny-tool='shell(git add)' --deny-tool='shell(git rebase)' \
  --deny-tool='shell(git merge)' --deny-tool='shell(git cherry-pick)' --deny-tool='shell(git clean)' \
  --deny-tool='shell(git tag)' --deny-tool='shell(git branch)' \
  --allow-tool='shell(ls)' --allow-tool='shell(cat)' --allow-tool='shell(grep)' --allow-tool='shell(rg)' \
  --allow-tool='shell(head)' --allow-tool='shell(tail)' --allow-tool='shell(sed)' --allow-tool='shell(wc)' \
  --allow-tool='shell(date)' --allow-tool='shell(cd)' \
  --allow-tool='shell(php)' --allow-tool='shell(vendor/bin/phpunit)' \
  --deny-tool='shell(php bin/magento)' --deny-tool='shell(php -r)' \
  --deny-tool='shell(bin/magento)' --deny-tool='shell(mysql)' --deny-tool='shell(docker)' --deny-tool='shell(rm)' \
  --usage-output-file "$A/usage.json" --share "$A/transcript.md" --log-dir "$A/logs" \
  --output-format json 2> "$A/stderr.txt" | tee "$A/stream.jsonl" | "${CONSOLE[@]}"
rc=${PIPESTATUS[0]}
set -e

date -u +%FT%TZ > "$A/finished.txt"
git status --porcelain > "$A/git-status-after.txt"
git -C magento2ee status --porcelain > "$A/git-status-ee-after.txt"
echo
echo "Exit code: $rc"
echo "Repository changes made by this run (magento2ee):"
diff "$A/git-status-ee-before.txt" "$A/git-status-ee-after.txt" | sed -n 's/^> /  /p' || true
if diff "$A/git-status-before.txt" "$A/git-status-after.txt" | grep -q '^>'; then
  echo "  WARNING: root repository status changed:"; diff "$A/git-status-before.txt" "$A/git-status-after.txt" | sed -n 's/^> /    /p'
fi
git -C magento2ee diff -- "app/code/$COND/Newsletter.php" > "$A/newsletter.diff"
shasum "$TEST" | diff -q - "$A/test-sha-before.txt" >/dev/null && echo "  ✔ NewsletterTest.php unchanged" || echo "  ✖ NewsletterTest.php was modified"
for f in execution-log.md 1-patch-generator.md green-verification.md stop3.md usage.json newsletter.diff; do
  [[ -s "$A/$f" ]] && echo "  ✔ $f" || echo "  ✖ $f (missing)"
done
echo "STOP 3: $A/stop3.md"
exit "$rc"
