#!/usr/bin/env bash
# BDAD Swarm Stage 4b dry run (STOP 4 NOT-GREEN resolved by human decisions -> Consensus Gateway re-run -> Scribe egress
# -> STOP 4) with a readable live console.
#
#   run-stage4b.sh <TICKET> [ARTIFACT_DIR]          live run (spends AI credits; edits the two ticket files only on a Gateway loop-back)
#   run-stage4b.sh --replay <ARTIFACT_DIR> [SPEED]  replay a recorded run (no credits, no network)
#
# Input: stop4-decisions.md (human STOP 4 decisions), Stage 4/3/2 artifacts (S4, S3, S2), by default the reference runs.
# Precondition: the working tree matches the approved Stage 4 state (Newsletter.php diff and NewsletterTest.php).
# Harness = runbook §2 plus php (lint, phpcs, PHPUnit, MIG), the two in-scope repository files, and the static-suite setup
# (config + multi-repo changed-file list), which this script prepares before the run and removes afterwards.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(git -C "$HERE" rev-parse --show-toplevel)"
CONSOLE=(python3 "$HERE/../stage1-dryrun/swarm_console.py")

if [[ "${1:-}" == "--replay" ]]; then
  [[ -f "${2:-}/stream.jsonl" ]] || { echo "usage: $0 --replay <ARTIFACT_DIR> [SPEED]" >&2; exit 2; }
  A="$2" exec "${CONSOLE[@]}" --replay "$2/stream.jsonl" --speed "${3:-10}"
fi

TICKET="${1:?usage: $0 <TICKET> [ARTIFACT_DIR]}"
A="${2:-$HOME/bdad-dryrun/$TICKET-stage4b-$(date +%Y%m%d-%H%M%S)}"
S2="$(cd "${S2:-$HERE/../stage2-dryrun/reference}" && pwd)"
S3="$(cd "${S3:-$HERE/../stage3-dryrun/reference}" && pwd)"
S4="$(cd "${S4:-$HERE/reference}" && pwd)"
case "$A" in "$REPO"/*) echo "ARTIFACT_DIR must be outside the repository" >&2; exit 2 ;; esac
mkdir -p "$A/logs" "$A/egress"
A="$(cd "$A" && pwd)"
export A
cp "$HERE/stop4-decisions.md" "$A/stop4-decisions.md"
sed -e "s#{{TICKET}}#$TICKET#g" -e "s#{{A}}#$A#g" -e "s#{{S2}}#$S2#g" -e "s#{{S3}}#$S3#g" -e "s#{{S4}}#$S4#g" \
  "$HERE/prompt-4b.template.txt" > "$A/prompt.txt"

COND=Magento/CustomerSegment/Model/Segment/Condition/Customer
FIX_EE="$REPO/magento2ee/app/code/$COND/Newsletter.php"
FIX_LINK="$REPO/app/code/$COND/Newsletter.php"
TEST="$REPO/magento2ee/dev/tests/integration/testsuite/$COND/NewsletterTest.php"
TEST_LINK="$REPO/dev/tests/integration/testsuite/$COND/NewsletterTest.php"
[[ -f "$TEST" ]] || { echo "Precondition failed: approved Red test missing: $TEST" >&2; exit 2; }
git -C "$REPO/magento2ee" diff -- "app/code/$COND/Newsletter.php" | diff -q - "$S4/newsletter.diff" >/dev/null \
  || { echo "Precondition failed: Newsletter.php does not match $S4/newsletter.diff" >&2; exit 2; }
cmp -s "$TEST" "$S4/NewsletterTest-after.php.txt" || { echo "Precondition failed: NewsletterTest.php does not match $S4/NewsletterTest-after.php.txt" >&2; exit 2; }

cd "$REPO"
# Static-suite setup (Consensus Gateway §B): Allure-stripped config + changed-file list covering the nested repositories.
STATIC_CFG=dev/tests/static/phpunit.php.xml
CHANGED_LIST=dev/tests/static/testsuite/Magento/Test/_files/changed_files_local.txt
[[ -e "$CHANGED_LIST" ]] && { echo "Precondition failed: $CHANGED_LIST already exists" >&2; exit 2; }
sed '/<extensions>/,/<\/extensions>/d' magento2-infrastructure/build/core_dev/static/phpunit.php.xml > "$STATIC_CFG"
printf '%s\n' "app/code/$COND/Newsletter.php" "dev/tests/integration/testsuite/$COND/NewsletterTest.php" > "$CHANGED_LIST"
cp "$CHANGED_LIST" "$A/changed_files_local.txt"
trap 'rm -f "$REPO/$CHANGED_LIST"' EXIT

git status --porcelain > "$A/git-status-before.txt"
git -C magento2ee status --porcelain > "$A/git-status-ee-before.txt"
git -C magento2ee diff -- "app/code/$COND/Newsletter.php" > "$A/newsletter-before.diff"
cp "$TEST" "$A/NewsletterTest-before.php.txt"
RETRO_BEFORE="$(find "$REPO/docs/retrospectives/$TICKET" -type f -exec shasum {} + 2>/dev/null | sort)"
date -u +%FT%TZ > "$A/started.txt"
echo "Ticket: $TICKET   Repo: $REPO   Stage 4: $S4   Artifacts: $A"

set +e
copilot -p "$(cat "$A/prompt.txt")" --agent orchestrator --no-ask-user --trust-folder --add-dir "$A" \
  --allow-tool='read' --allow-tool="write($A/**)" \
  --allow-tool="write($FIX_EE)" --allow-tool="write($FIX_LINK)" \
  --allow-tool="write($TEST)" --allow-tool="write($TEST_LINK)" \
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
  --allow-tool='shell(date)' --allow-tool='shell(cd)' --allow-tool='shell(diff)' --allow-tool='shell(shasum)' \
  --allow-tool='shell(php)' --allow-tool='shell(vendor/bin/phpunit)' --allow-tool='shell(vendor/bin/phpcs)' \
  --deny-tool='shell(php bin/magento)' --deny-tool='shell(php -r)' --deny-tool='shell(vendor/bin/mftf)' \
  --deny-tool='shell(bin/magento)' --deny-tool='shell(mysql)' --deny-tool='shell(docker)' --deny-tool='shell(rm)' \
  --usage-output-file "$A/usage.json" --share "$A/transcript.md" --log-dir "$A/logs" \
  --output-format json 2> "$A/stderr.txt" | tee "$A/stream.jsonl" | "${CONSOLE[@]}"
rc=${PIPESTATUS[0]}
set -e

date -u +%FT%TZ > "$A/finished.txt"
git status --porcelain > "$A/git-status-after.txt"
git -C magento2ee status --porcelain > "$A/git-status-ee-after.txt"
git -C magento2ee diff -- "app/code/$COND/Newsletter.php" > "$A/newsletter.diff"
cp "$TEST" "$A/NewsletterTest-after.php.txt"
diff -u "$A/NewsletterTest-before.php.txt" "$A/NewsletterTest-after.php.txt" > "$A/newsletter-test.diff" || true
echo
echo "Exit code: $rc"
echo "Repository changes made by this run (magento2ee):"
diff "$A/git-status-ee-before.txt" "$A/git-status-ee-after.txt" | sed -n 's/^> /  /p' || true
if diff "$A/git-status-before.txt" "$A/git-status-after.txt" | grep -q '^>'; then
  echo "  WARNING: root repository status changed:"; diff "$A/git-status-before.txt" "$A/git-status-after.txt" | sed -n 's/^> /    /p'
fi
cmp -s "$A/newsletter-before.diff" "$A/newsletter.diff" && echo "  ✔ Newsletter.php unchanged since Stage 4" \
  || echo "  ⚠ Newsletter.php changed (loop-back edit): see newsletter.diff"
[[ -s "$A/newsletter-test.diff" ]] && echo "  ⚠ NewsletterTest.php changed (loop-back edit): see newsletter-test.diff" || echo "  ✔ NewsletterTest.php unchanged since Stage 4"
RETRO_AFTER="$(find "$REPO/docs/retrospectives/$TICKET" -type f -exec shasum {} + 2>/dev/null | sort)"
[[ "$RETRO_AFTER" == "$RETRO_BEFORE" ]] && echo "  ✔ docs/retrospectives/$TICKET untouched" || echo "  ✖ docs/retrospectives/$TICKET was modified"
for f in execution-log.md verification.md stop4.md usage.json egress/dev-summary.md egress/release-notes.md egress/retrospective.md; do
  [[ -s "$A/$f" ]] && echo "  ✔ $f" || echo "  ✖ $f (missing)"
done
ls "$A" | grep -E '^[0-9]+-.*\.md$' | sed 's/^/  ✔ /'
echo "STOP 4: $A/stop4.md"
exit "$rc"
