# BDAD New-Ticket Runbook

| | |
|---|---|
| **Purpose** | How to run a live L4 ticket through the BDAD swarm + MIG Phase 0 tooling |
| **Last updated** | 2026-11-19 |
| **Companions** | `docs/bdad-changelog.md` (architecture), `docs/mig/mig-phase0-spec.md` (tool), `.github/agents/*.agent.md` (VIs), `.github/agents/reference/domains.md` (domains) |

---

## 0. Prerequisites (once per ticket)

1. **Branch:** create the ticket branch off `2.4-develop`.
2. **Config reload:** for a clean ticket run after profile changes, start a fresh GitHub Copilot CLI session
   in the repo root. Use the six `.github/agents/*.agent.md` profiles and the shared
   `.github/agents/reference/domains.md`; do not recreate a second set of Claude agent profiles.
   The Claude Code reload probes in changelog **PART XIV** are historical and do not establish Copilot's
   reload behavior. Start that session **as the Orchestrator**, writing per-agent usage outside the repo:
   `copilot --agent orchestrator --usage-output-file ~/.copilot/usage/<TICKET>-<YYYYMMDD>.json`
   (or `/agent orchestrator` in an existing session, which gives no usage file). It is the sole coordinator and
   must never be dispatched as a subagent (changelog v2.14, PART XVI).
3. Drop the ticket description into a file (e.g. `ticket.md`) or paste it inline.

## 1. Copy-paste template (Orchestrator session — recommended)

Paste this into a session started with `copilot --agent orchestrator`. The Orchestrator dispatches the five
specialists, receives their outputs directly, and halts at the four human pause points: after intake, after
Red, after Green, and after the gate plus summaries.

```
We are starting ticket <TICKET-ID> (branch <branch>). Ticket description: <path or inline>.
Run our BDAD swarm flow, delegating to the VIs in .github/agents/. Use plan mode before modifying >2 files.
Do not commit anything. Pause and show me the result after STEP 1 (intake brief), STEP 3 (Red), STEP 4
(Green), and STEP 6 (gate verdict + dev summary + release notes).

STEP 1 — scribe: normalize the ticket into Scenario / Preconditions / Steps / Expected+Actual + Manual Steps
  to Reproduce + a Given/When/Then behavior contract (1 Defect + Invariant scenarios, max 5), and classify it into its Adobe Commerce domain(s) using
  .github/agents/reference/domains.md (name the primary domain + any it spans).
  [PAUSE HERE — confirm the brief and approve/amend the behavior contract]

STEP 2 — dependency-tracer: produce the Phase 0 Blast-Radius Report for the symbols the fix will touch,
  BEFORE any code. Use the MIG tool for the grep-invisible edges:
    php dev/tools/impact-graph/bin/impact-graph analyze --plugins-subject '<FQCN>' --format=json
    php dev/tools/impact-graph/bin/impact-graph analyze --plugins-method '<method>' --format=json
    php dev/tools/impact-graph/bin/impact-graph analyze --dispatches '<changed-file>' --format=json
    php dev/tools/impact-graph/bin/impact-graph analyze --event '<dispatched-event>' --format=json
  Then name the dependent domains from the domains.md Cross-Domain Adjacency Map, and grep callers across
  app/code, ext/magento/inventory, and EE/B2B symlinks. (Phase 0 has no CALLS graph — cover one-hop-away
  edges via domain adjacency + grep.) Also grep the same file/class for OTHER call sites of the exact
  defect pattern being fixed, not just callers of the one method already named — a repeated code idiom is
  rarely broken in only one place (see dependency-tracer.agent.md's anti-pattern repetition check).
  Dependency Tracer is the SOLE owner of pre-code root-cause archaeology — do not also dispatch a separate
  general-purpose investigation agent alongside it; hand it the raw ticket/diff directly instead.

STEP 3 — test-author: write the FAILING test FIRST (scenario-scoped, invariant-first, Scenario:/Expected:
  docblocks, PHP-8 #[DataFixture]). Run it with --filter and SHOW ME it fails (Red). Integration tests need
  the "$PWD/" absolute path.  [PAUSE HERE — show me Red]

STEP 4 — patch-generator: implement the fix until the test is Green. Enforce PSR-12/strict-typing/DI/
  Cloud-compat from patch-generator.agent.md. Then rm -rf generated/code/* dev/tests/integration/tmp/sandbox-* if
  di.xml/plugins changed.  [PAUSE HERE — show me Green]

STEP 5 — consensus-gateway: run the Mandatory Validation Workflow (php -l, phpcs --standard=Magento2, static
  suite) AND the Cross-Domain Verification Gate. Derive the gate's blast radius + stale mocks from:
    php dev/tools/impact-graph/bin/impact-graph analyze --diff '2.4-develop...HEAD' --format=json
    php dev/tools/impact-graph/bin/impact-graph analyze --mock-impact '2.4-develop...HEAD' --format=json
  Resolve every mock_impact flag's missing_stubs. Run the dependent-domain MFTF suites — enumerate them with
    php dev/tools/impact-graph/bin/impact-graph analyze --mftf-group '<group>'   (e.g. msi)
    php dev/tools/impact-graph/bin/impact-graph analyze --mftf-module '<Module>'
  A non-empty unaddressed mock_impact, or any red suite, BLOCKS green — loop back to patch-generator /
  test-author until green (a blocking loop-back, not a stop point). On green, proceed to STEP 6.

STEP 6 — scribe: write the brief dev summary, release notes, and docs/retrospectives/<TICKET-ID>/retrospective.md (carrying the
  Manual Steps to Reproduce, plus a Usage section marked "pending session end" that names the usage JSON path).
  [PAUSE HERE — Consensus green + dev summary + release notes ready for review]
```

## 2. Unattended / dry-run harness (non-interactive Orchestrator session)

For Stage 1, prefer the packaged kit: `docs/bdad-workshop/stage1-dryrun/run-stage1.sh <TICKET>`. It renders
the prompt, runs this harness, shows a readable live console (`swarm_console.py`) and prints the post-run checks.
`--replay <dir>` replays a recorded run without spending credits.

Use this to observe a stage (for example, intake through STOP 1) without an interactive session. Anything not
allowed is denied, because `-p` runs cannot prompt. Set `A` to an artifact directory outside the repository and
put the prompt in `$A/prompt.txt`. The prompt must state the dry-run constraints and the stop point.

```bash
A=/path/to/artifacts; mkdir -p "$A/logs"
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
  --output-format json > "$A/stream.jsonl"
```

* `shell(git:*)` with mutating subcommands denied is required. A narrow rule such as `shell(git log)` does not
  match `git -C magento2ee log`, because the CLI parses `-C` as the subcommand. That blocked the Tracer's
  history check in the ACP2E-5597 run 2.
* `python`/`python3` are deliberately not allowed: arbitrary code would bypass the other denials.
* Per-agent model IDs and usage come from `usage.json` (`agentMetrics`). `task` results do not expose them.
* Never ask the default session to "delegate to the `orchestrator` VI". A subagent Orchestrator cannot receive
  specialist results, and the profile sets `disable-model-invocation: true`.

## 3. Non-negotiables (always in force)

* **TDD Red-Green-Refactor** — failing test first, verified Red, before implementation.
* **Four mandatory stop points** — halt for human review after (1) intake brief, (2) Red, (3) Green, (4) gate
  green + dev summary + release notes; never auto-continue past any of them.
* **Plan mode** before modifying >2 files or a complex refactor.
* **No Jira ticket numbers** in code/DocBlocks/inline comments/test names (docs under `docs/` are the exception).
* **Never run PHP/PHPUnit via Docker**; **integration paths need `$PWD/`**.
* **Not "complete"** until the Consensus Gateway is green; shared sales/inventory/pricing changes also require
  the Cross-Domain Verification Gate.
* **Local-only:** infra, `.claude/` directives, and `docs/retrospectives/*` / this runbook are never staged/committed.

## 4. What changed vs the first-draft template

* **STEP 2 is now tool-driven** — the MIG `impact-graph` commands mechanize the grep-invisible blast radius
  (plugins/observers/dispatches) instead of relying on manual grep discipline.
* **STEP 1 adds domain classification** (`domains.md`) as the routing signal.
* **STEP 5 adds `--diff` / `--mock-impact` / `--mftf-group|--mftf-module`** to make the cross-domain gate +
  mock-sync mechanical and deterministic.
* **Delegation is real**: the Orchestrator runs as the main session and dispatches the specialists directly.
  Since v2.14 it is never a subagent; §2 is a non-interactive Orchestrator session, not a hand-off.
* **Explicit Phase-0-vs-CALLS caveat** — pre-code Phase 0 uses targeted queries; one-hop-away edges still need
  domain adjacency + grep until Phase 1 CALLS lands.
