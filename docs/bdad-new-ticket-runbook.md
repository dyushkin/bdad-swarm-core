# BDAD New-Ticket Runbook

| | |
|---|---|
| **Purpose** | How to run a live L4 ticket through the BDAD swarm + MIG Phase 0 tooling |
| **Last updated** | 2026-10-26 |
| **Companions** | `docs/bdad-changelog.md` (architecture), `docs/mig/mig-phase0-spec.md` (tool), `.github/agents/*.agent.md` (VIs), `.github/agents/reference/domains.md` (domains) |

---

## 0. Prerequisites (once per ticket)

1. **Branch:** create the ticket branch off `2.4-develop`.
2. **Config reload:** for a clean ticket run after profile changes, start a fresh GitHub Copilot CLI session
   in the repo root. Use the six `.github/agents/*.agent.md` profiles and the shared
   `.github/agents/reference/domains.md`; do not recreate a second set of Claude agent profiles.
   The Claude Code reload probes in changelog **PART XIV** are historical and do not establish Copilot's
   reload behavior.
3. Drop the ticket description into a file (e.g. `ticket.md`) or paste it inline.

## 1. Copy-paste template (top-level-conducted — recommended)

Top-level conducting keeps the four human pause points (after intake, after Red, after Green, after the
gate + summaries) under your control.

```
We are starting ticket <TICKET-ID> (branch <branch>). Ticket description: <path or inline>.
Run our BDAD swarm flow, delegating to the VIs in .github/agents/. Use plan mode before modifying >2 files.
Do not commit anything. Pause and show me the result after STEP 1 (intake brief), STEP 3 (Red), STEP 4
(Green), and STEP 6 (gate verdict + dev summary + release notes).

STEP 1 — scribe: normalize the ticket into Scenario / Preconditions / Steps / Expected+Actual + Manual Steps
  to Reproduce, and classify it into its Adobe Commerce domain(s) using
  .github/agents/reference/domains.md (name the primary domain + any it spans).  [PAUSE HERE — confirm the brief]

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
  Manual Steps to Reproduce).  [PAUSE HERE — Consensus green + dev summary + release notes ready for review]
```

## 2. Alternative — hands-off (delegate the whole flow to the Orchestrator)

For an unsupervised run, hand the entire ticket to the router (it may delegate to the five VIs via its
`tools: Agent(...)` allowlist):

```
Delegate this ticket to the `orchestrator` VI to run the full BDAD flow end-to-end: <ticket path/inline>.
It should route scribe → dependency-tracer → test-author → patch-generator → consensus-gateway → scribe,
pausing at all four mandatory stop points (intake brief, Red, Green, gate + summaries). Do not commit.
```

Note: the Orchestrator has no native Grep/Glob (allowlist limitation — see postmortem PART IX); it searches via
Bash and delegates deep analysis to the dependency-tracer.

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
* **Delegation is real** — §2 hands-off mode exists because recursive delegation is verified working.
* **Explicit Phase-0-vs-CALLS caveat** — pre-code Phase 0 uses targeted queries; one-hop-away edges still need
  domain adjacency + grep until Phase 1 CALLS lands.
