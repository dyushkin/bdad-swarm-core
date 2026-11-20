# BDAD Approach — Change Log & Architecture Record

*Central, living record of the BDAD swarm: how the monolithic `CLAUDE.md` was decentralized into a
Geth-inspired multi-agent swarm of specialized VIs, and every subsequent change to the BDAD approach.
This is the authoritative document for "why is the swarm shaped the way it is" and "what changed, when, why."*

| | |
|---|---|
| **Current version** | v2.25 |
| **Last updated** | 2026-11-20 |
| **Scope** | `.github/agents/` Swarm architecture (not a code ticket) — 6 sub-agent VIs + domain reference, with `.claude/CLAUDE.md` retained as a compatibility pointer |
| **Status legend** | ✅ done · 🟡 partial · ⬜ not started · 🔎 resolved by investigation |

### Current layout — GitHub Copilot migration (2026-10-07)

The six `.github/agents/*.agent.md` profiles are now the single source of truth. Their bodies matched the
duplicate Claude profiles before those copies were removed. The domain reference moved byte-for-byte to
`.github/agents/reference/domains.md`; Dependency Tracer, Test Author, Scribe, and Consensus Gateway now
use that path. The compatibility pointer, runbook, roadmap, and MIG integration links were updated too.
The four human stop points and domain adjacency rules are unchanged.

Earlier Claude-specific build logs, reload probes, and experiment snapshots are historical records, not
instructions to recreate the removed agent directory. Current profile locations are listed below.

### Retrospective layout migration (2026-10-07)

Retrospectives now live under `docs/retrospectives/`. The four existing per-ticket folders use `retrospective.md`,
and new Scribe output goes to `docs/retrospectives/<TICKET>/retrospective.md`. Existing flat reports and sidecar
filenames are unchanged. Swarm instructions and links to the moved reports use the new paths.
Historical experiment snapshots retain their own independent directory layouts.

### BDAD naming adoption (2026-10-26)

Swarm now uses **Behavior Driven Agentic Development (BDAD)** terminology across documentation,
agent profiles, and report filenames. The documentation family is `bdad-*`, and R-BDAD is closed
in the roadmap. This is a naming change, not a workflow redesign: the six VI roles,
Red-Green-Refactor cycle, and four mandatory human stop points are unchanged.
Historical narrative uses the current terminology; recorded outcomes and technical details are preserved.

### Revision history
| Version | Date | Change |
|---|---|---|
| v1.0 | 2026-08-24 | Initial record: mapping analysis, 4→6 VI counter-proposal (Consensus Gateway + Scribe), model routing, domain reference + wiring, CLAUDE.md slimmed to a pointer. Nothing committed. |
| v1.1 | 2026-08-24 | poppler re-extract of `domains.pdf` (fixed dropped PM Nishant Kapoor + 3 misattributed domains); confirmed local-only policy for infra/`.claude`/postmortems (#9 → 🔎); recorded MIG analytical-first/cache-not-graph decision in `impact-graph-proposal.md` §3a + tracer seam (#7 → 🟡). |
| v1.2 | 2026-08-24 | Added PART VIII — validation dry-run of the new-ticket prompt template against ACP2E-5180 on its branch; both historical blind spots caught pre-implementation (#10 → ✅). |
| v1.3 | 2026-08-24 | Drafted MIG Phase 0 concrete spec (`docs/mig/mig-phase0-spec.md`); action item #7 advanced to spec-ready. |
| v1.4 | 2026-08-24 | Built MIG Phase 0 P0-1/P0-2 (`dev/tools/impact-graph`); ran against ACP2E-5180 — di.xml/events.xml edges resolve mechanically, matching PART VIII; PSR-12 clean. #7 → in-progress. |
| v1.5 | 2026-08-24 | Built P0-4 (php-parser DispatchScanner + MockScanner). Dispatch→observer chaining works from `Order/Item.php`; MockScanner caught the `getQtyToShip` delta. Surfaced two boundaries: merged-diff needs Phase 1 CALLS (recall held via domain adjacency); `round` needs P0-5 per-method scoping. Spec §12b. |
| v1.6 | 2026-08-24 | Built P0-5 (GitDiff + CollaboratorScanner + per-method MockScanner + Resolver). `--mock-impact` meets the mock-impact half of the corpus DoD (flags `testRegisterDiscountCanceled` with `getQtyToShip` + `round`). Design finding: call-site delta beats reachable-name diff. Spec §12c. |
| v1.7 | 2026-08-24 | Built P0-7 (`ResolverCorpusTest` + `ACP2E-5180.json` + phpunit config). Suite green (`Tests: 2, Assertions: 4, Incomplete: 1`); mock-impact DoD now guarded automatically; acceptance-tier deferred via `markTestIncomplete`. Spec §12d. |
| v1.8 | 2026-08-24 | P0-8: wired the impact tool into the swarm — Dependency Tracer (Phase 0 runs `--diff`/`--mock-impact`), Consensus Gateway (gate consumes `observes` + `mock_impact`), Test Author (mock-sync). MIG seam flipped RESERVED → Phase 0 ACTIVE. Spec §12e. |
| v1.9 | 2026-08-24 | P0-6 (EdgeCache; MFTF warm ~40% faster, di/events FS-walk-bound) + P0-3 (MftfIndex; `--mftf-group msi` finds `must_include_test`). Corpus test upgraded (`testCorpusAcceptanceTestIsIndexed` green). **Phase 0 complete (#7 → 🟢).** Spec §12f. |
| v2.0 | 2026-08-24 | Verified sub-agent mechanics (PART IX, item #5 → 🔎). **Corrected** the hub-and-spoke assumption — recursive delegation is possible via `tools: Agent(...)`; empirically, mid-session agents need a reload to register. New action item #11 (enable Orchestrator routing after reload). |
| v2.1 | 2026-08-24 | Added `tools: Agent(...)` allowlist + Routing section to `orchestrator.md` (item #11 → 🟡). Awaiting user session reload, then a live Orchestrator→VI delegation test. |
| v2.2 | 2026-08-24 | Empirically confirmed `/agents` does NOT reload (invoking `scribe` → `Agent type 'scribe' not found`); a full CLI restart is required. Updated PART IX row 3 + item #11. |
| v2.3 | 2026-08-24 | Post-restart verification: all six VIs registered; Orchestrator toolset intact; `scribe` self-identified; `orchestrator`→`scribe` recursive delegation succeeded. Item #11 → ✅. Refined PART IX row 1 (default = all tools incl. Agent; `tools: Agent(...)` restricts). |
| v2.4 | 2026-08-24 | Added least-privilege `tools:` allowlists to the five leaf VIs (none get `Agent`; consensus-gateway read-only; dependency-tracer no Write). PART IX toolset matrix added. Effective next restart. |
| v2.5 | 2026-08-24 | Runtime-probed the leaves: allowlist silently dropped `Grep`/`Glob` (parser doesn't know those tokens). Switched the five leaves to `disallowedTools:` denylists (preserves search, still removes Agent/Write/Edit per role); Orchestrator keeps its allowlist for delegation-restriction. PART IX matrix + probe log updated. Re-probe after next restart. |
| v2.6 | 2026-08-24 | Re-probe attempt: self-report proved unreliable (contradictions on identical config) and Write-file probes are Bash-contaminated. Reliable Grep functional probe → `GREP UNAVAILABLE` ⇒ session still on the OLD allowlist; denylist edits on disk but **not loaded (no restart yet)** — fix unverified. Documented verification methodology (Grep functional + Agent-delegation attempt only). |
| v2.7 | 2026-08-24 | Authored `docs/bdad-new-ticket-runbook.md` — the live-ticket flow (updated template: tool-driven Phase 0, domain classification, `--diff`/`--mock-impact`/`--mftf-*` gate, hands-off Orchestrator mode). |
| v2.8 | 2026-09-01 | Ran the **monolithic-vs-swarm A/B** on ACP2E-5288 (closes item #12): swarm won ~44% on cost, same fix correctness, no destructive incident. Applied four resulting BDAD corrections (MFTF-first concurrency triage; broadened approval-gated no-ad-hoc-DB-mutation rule; MFTF+unit-only standard for concurrency bugs; stale "MIG on hold" label fixed to active). Renamed this doc to the central BDAD change-log. See **PART X**. |
| v2.9 | 2026-09-01 | Two mandatory rules added after the ACP2E-5288 fix (see **PART XII**): **(1) i18n coverage** — every new unique `__()` phrase must have an owning-module `i18n/en_US.csv` entry (`patch-generator.md` coding standard + File-Level Check #3 + a `CLAUDE.md` non-negotiable); **(2) MFTF generation check** — any `Test/Mftf/*.xml` change must pass `mftf generate:tests` with no unresolved-reference errors (`test-author.md` authoring self-check + `consensus-gateway.md` §C gate). |
| v2.10 | 2026-09-01 | Added authoring guidance to `test-author.md` (see **PART XII.3**): prefer core/platform action groups over a bespoke base + B2B-merge pair for edition-specific select→widget fields (e.g. Cart Price Rule customer groups). Surfaced by the ACP2E-5288 B2B MFTF fix — the ticket fix itself lives in `docs/retrospectives/postmortem-ACP2E-5288.md`, not here. |
| v2.11 | 2026-09-08 | **Expanded the human-in-the-loop gates from 2 → 4 mandatory stop points** (see **PART XIII**): halt after (1) Scribe intake brief, (2) Red, (3) Green, (4) Consensus green + dev summary + release notes. Updated `orchestrator.md` (new **Mandatory Stop Points** subsection), the runbook template (`[PAUSE HERE]` markers on STEP 1/3/4/6 + a non-negotiables bullet), and a new `CLAUDE.md` non-negotiable. The old "after the gate" pause moves from *before* egress to *after* egress, bundled with the summaries. Rationale: tighter operator control during Swarm development. |
| v2.12 | 2026-09-08 | **Corrected the config-reload model** (see **PART XIV**, supersedes PART IX row 3): per current Claude Code docs confirmed via the `claude-code-guide` agent, an edit to an *existing* `.claude/agents/*.md` (body + frontmatter) **hot-reloads within seconds, no restart**; `CLAUDE.md` reloads on `/clear`; a full restart is needed **only** for a NEW agent file when `.claude/agents/` did not exist at session start (or agents under `--add-dir`). `/agents`/`/memory`/`/config` are diagnostic-only. Relaxed runbook §0.2 accordingly; the v2.2 empirical "restart required" finding is retained as a conflicting observation pending live re-probe. Also updated memory `claude-code-subagent-mechanics`. |
| v2.13 | 2026-09-08 | **Live re-probe RESOLVED the PART IX conflict** (see **PART XIV.5**): dispatched a newly-created and an edited-existing agent through the in-session Agent tool. Both loaded **without a restart** — but only after a **propagation delay** (~tens of seconds to ~1 min); dispatches fired immediately after the change hit the STALE definition (`not found` / old behavior). So the v2.2 "restart required" finding was a **latency false-negative**. Net rule: edit → wait for propagation (or `/clear`/restart to force immediacy); no restart needed for new-in-existing-dir or edited agents. |
| v2.14 | 2026-11-19 | **Single-coordinator topology** (see **PART XVI.1**). The ACP2E-5597 Stage 1 dry run split coordination between the main session and a synchronous Orchestrator subagent. Returning specialist results to that subagent failed (`write_agent only supports background agents`). The Orchestrator now runs only as the main session (`copilot --agent orchestrator`). Its profile sets `disable-model-invocation: true`, uses `tools: ["*"]` (the old list had no `agent` alias), and adds an **Execution Model** section; specialists return output only to it. `copilot-instructions.md` gained **Coordination topology**, and "top-level conductor" became "main-session Orchestrator". |
| v2.15 | 2026-11-19 | **Pinned models** (see **PART XVI.2**): Orchestrator `claude-opus-5.5`, Scribe `claude-sonnet-5.5`, Dependency Tracer `mai-code-2`. The commented model-tier lines became `model:` keys. Test Author, Patch Generator, and Consensus Gateway are unchanged. |
| v2.16 | 2026-11-19 | **Run 2 dry-run follow-ups** (see **PART XVI.4**). Each change was confirmed individually. Changes: pre-intake Tracer budget (30 calls / 150 lines; formal Phase 0 uncapped); Tracer tools gained `search` and `scout/*` (memory writes forbidden), with the Orchestrator passing seeds only; mandatory Orchestrator `scout-ensure_fresh` (retry once, then label `freshness unverified`); exact labelled handoff citations (Orchestrator and Scribe); runbook §0/§1/§2/§4 rewritten for the main-session Orchestrator with a corrected dry-run harness (`shell(git:*)` with mutating subcommands denied, no python); usage taken from `--usage-output-file` stored outside the repository, with the retrospective's Usage section marked `pending session end`. |
| v2.17 | 2026-11-19 | **Behavior contract (Given/When/Then)** (see **PART XVI.5**). Scribe's intake brief adds a `gherkin` contract: 1 `Defect:` scenario plus `Invariant:` scenarios, max 5; observable Then clauses; `# OPEN:` for unresolved points. The Orchestrator shows it verbatim at STOP 1 for approval, Test Author maps each scenario to a test method, and Scribe carries it into the retrospective. |
| v2.18 | 2026-11-19 | **Test Author pin + verbatim relay.** `test-author.agent.md` pins `model: claude-sonnet-5.5`; in the ACP2E-5597 Stage 2 dry run it inherited `claude-opus-5.5` and used 118.8 of 294.9 credits (40%). The Orchestrator gains the rule *Relay specialist recommendations verbatim*. In the same run it added "a NEW dedicated test class is preferred" to the Tracer's placement advice (beside `Model/CustomerTest.php`), so Test Author created a new class. Disagreements now go to the next stop point instead of being settled in a brief. |
| v2.19 | 2026-11-19 | **MIG: MFTF index searches subfolders.** `MftfIndex::testFiles()` only looked at files directly in `Test/Mftf/Test/*.xml`, so tests in subfolders were missing from `--mftf-module` and `--mftf-group` results. The ACP2E-5597 Stage 2 dry run exposed this: `Magento_CustomerSegment` reported 29 tests instead of 37, and 2 of the 5 newsletter tests (in `CustomerSegmentsWithoutRealTimeCheck/`) were found only by the Tracer's own search. The index now walks each `Test/Mftf/Test` directory recursively. New regression test `test/MftfIndexTest.php` (Red before the fix, Green after). The Consensus Gateway's dependent-suite list was affected too. `ResolverCorpusTest::testCorpusMockImpactRecall` was already failing on this branch and is unrelated. |
| v2.20 | 2026-11-19 | **Patch Generator pin.** `patch-generator.agent.md` pins `model: claude-opus-5.5`. It writes production code, so the strongest tier is kept on purpose; before, it inherited whatever the session used. With this, every Swarm agent except Consensus Gateway has an explicit model. |
| v2.21 | 2026-11-19 | **MIG: `--diff` and `--mock-impact` see nested repositories.** `GitDiff` only ran `git -C <CE root>`, so a change in `magento2ee` or `magento2b2b` (symlinked into `app/code`) showed up as `changed: []`. The Consensus Gateway would have read that as no blast radius: a false green. Found while preparing the ACP2E-5597 Stage 4 dry run, when `--diff HEAD` showed nothing despite the modified EE `Newsletter.php`. `GitDiff` now also diffs top-level nested repositories that contain `app/code` or `ext`, reports their files by the root-relative (symlinked) path, and reads base content from the owning repository. New regression test `test/GitDiffTest.php` (Red before, Green after). On the real tree, `--diff HEAD` now reports `Newsletter.php`. The `ResolverCorpusTest` failure was already there and is unrelated. |
| v2.22 | 2026-11-19 | **Consensus Gateway pin.** `consensus-gateway.agent.md` pins `model: gpt-6.1-sol`, so the gate is reviewed by a different provider than the Claude agents that wrote the test and the fix. All six Swarm agents now have an explicit model. |
| v2.23 | 2026-11-19 | **Consensus Gateway §B: the static suite must actually check the changed files.** `LiveCodeTest` reads a `changed_files*` list. When none exists, it falls back to `git diff` in the CE root. For an EE-only change (ACP2E-5597 `Newsletter.php`) that finds nothing, and the suite reports `OK (6 tests, 1 skipped)` without checking anything. With the list in place, the same suite fails on two Magento2 docblock warnings that were already in the touched file (`isSatisfiedBy()`, lines 187–188). CI would report them too. The profile now requires the Gateway to confirm that the list covers every changed repository, to quote which files were checked, and to treat a run with no list or an empty check as blocked or not run, never green. The Stage 4 kit writes `changed_files_local.txt` from the multi-repo diff before the run and removes it afterwards. |
| v2.24 | 2026-11-19 | **Consensus Gateway: §B scope and human deferral.** Found in the ACP2E-5597 Stage 4 dry run (NOT-GREEN, 350.34 credits). (1) The v2.23 §B rule said "missing a changed PHP file from any repository", so the Gateway blocked on 17 untracked files unrelated to the ticket (MIG tool sources and an ACP2E-5537 retro file). §B coverage is now checked against the ticket's declared change set; other dirty files are listed as info and never block. (2) The Cross-Domain gate had no way to honour a human decision to run dependent-domain suites in CI rather than locally. New rule: a human stop-point decision may defer named suites, which are reported as `DEFERRED (human, <stop>)`. The verdict is then GREEN with deferrals, but only if every check that was run passed. A deferral never covers a failed suite, and the Gateway never defers on its own. |
| v2.25 | 2026-11-20 | **Scribe: standard commit message.** At egress, Scribe now proposes a one-line commit message in the form `<TICKET>: <problem statement>`. The statement describes the defect and its impact, not the fix. The human approves it at stop point 4, and Scribe never commits. Reference: ACP2E-5597 commit `271b73b45b` (magento2ee). The ticket diff is saved as `docs/retrospectives/ACP2E-5597/ACP2E-5597.diff`. |

> **How to read this doc:** this is the central architecture + change record for the BDAD swarm. It began as
> the swarm-migration postmortem and now also carries every subsequent change to the approach (PART X onward).
> It is *not* a per-ticket bug retrospective — there is no Red→Green code fix here. Structure mirrors the
> per-ticket convention (`docs/retrospectives/<TICKET>/retrospective.md`) so it reads consistently with them.

---

# PART I — Executive Summary

The monolithic `.claude/CLAUDE.md` (~19 KB, 8 sections) was decentralized into a **6-VI BDAD swarm** under
`.claude/agents/`, with `CLAUDE.md` reduced to a thin routing pointer plus a small always-on non-negotiables
block. A domain reference extracted from `domains.pdf` was added and wired into three VIs to make the
Cross-Domain Verification Gate deterministic.

Two design corrections emerged during the mapping review, both accepted by the user:

1. **The Consensus Gateway is not the Orchestrator.** Folding the Mandatory Validation Workflow + merge
   verdict into the router conflated two Geth primitives and gave the router a conflict of interest. Split
   into a standalone VI.
2. **Ticket intake + human-facing docs had no home.** Messy L4 ticket normalization, Manual Steps, dev
   summary, release notes, and the post-mortem doc are a perception/egress concern — added a **Scribe** VI and
   moved post-mortem/Manual-Steps ownership out of the Consensus Gateway into it.

One architectural assumption was made early — and later **corrected** by verification (see PART IX). Initially
recorded as "Claude Code sub-agents are strictly hub-and-spoke; sub-agents cannot call other sub-agents." That
is **wrong**: per the Claude Code docs a sub-agent *can* spawn other sub-agents when its frontmatter explicitly
allowlists them via `tools: Agent(<names>)`. Default (no `tools:` allowlist) is effectively hub-and-spoke — the
top-level session conducts — but a true peer-ish swarm (e.g. the Orchestrator routing to the other VIs) is
achievable with that allowlist. The Geth "peers cross-examine" ideal is thus *configurable*, not impossible.

---

# PART II — Execution Journey (Analyze → Map → Ratify → Generate)

| Phase | Action | Outcome |
|---|---|---|
| A1 — Analyze | Parsed CLAUDE.md §1–§8 into atomic rules; identified cross-cutting rules that must be duplicated. | ✅ Full bullet inventory |
| A2 — Map + counter-propose | Produced section→VI mapping; flagged Gap A (Consensus Gateway) + Gap B (intake/docs). Proposed 5th VI. | ✅ Ratified (5 VIs) |
| A3 — Review round | User raised the Tech-Writer idea + MIG-on-hold. Recommended 6th VI (Scribe) with model-mismatch + lifecycle justification; reserved MIG seam. | ✅ Ratified (6 VIs) |
| A4 — Generate profiles | Wrote 6 profiles with model routing; zero-data-loss with labeled duplication. | ✅ 6 files created |
| A5 — Slim CLAUDE.md | Reduced to routing table + non-negotiables; delegated the rest. | ✅ |
| A6 — Domain reference | Extracted `domains.pdf` (poppler absent → inflated FlateDecode streams directly); built catalog + adjacency map; wired into Dependency Tracer / Test Author / Scribe. | ✅ |

---

# PART III — The Swarm (final architecture)

| VI | File | Model | Owns |
|---|---|---|---|
| Orchestrator | `.github/agents/orchestrator.agent.md` | `claude-opus-5.5` (v2.15) | Hybrid Docker env, service connections, test-exec commands (`$PWD/`, `--filter`, no-Docker), `setup.sh`, operational commands, Red-Green-Refactor loop coordination, env gotchas |
| Test Author | `.github/agents/test-author.agent.md` | Opus 5 | Test authoring, MFTF execution + A/B/C failure triage, PHP-8 Attributes, invariant-first assertions, scenario-scoped placement/naming/docblocks |
| Dependency Tracer | `.github/agents/dependency-tracer.agent.md` | `mai-code-2` (v2.15) | Phase 0 blast-radius, cross-domain gate, mock-surface sync, symlink resolution, **domain-reference owner**, MIG reserved seam |
| Patch Generator | `.github/agents/patch-generator.agent.md` | Opus 5 | PSR-12/strict typing, DI, plugin constraints, service contracts, legacy-UI avoidance, Cloud-compat, file-level checks |
| Consensus Gateway | `.github/agents/consensus-gateway.agent.md` | Haiku 4.5 | Mandatory Validation Workflow (`php -l`, PHPCS, static suite), cross-domain gate, green/merge verdict |
| Scribe | `.github/agents/scribe.agent.md` | `claude-sonnet-5.5` (v2.15) | Ticket intake → structured brief + domain classification, Manual Steps, dev summary, release notes, `docs/retrospectives/<TICKET>/retrospective.md` |

**Model-routing rationale:** deep-reasoning quants (Test Author, Dependency Tracer, Patch Generator) → Opus 5;
router + prose writer (Orchestrator, Scribe) → Sonnet 5; deterministic command-tally gate → Haiku 4.5. The
brief's 3.5-era model names were corrected to the current Opus 5 / Sonnet 5 / Haiku 4.5 lineup. As of v2.15,
the Orchestrator, Scribe, and Dependency Tracer models are pinned in frontmatter. The other three keep their tier comments. See PART XVI.2.

---

# PART IV — Files Changed

### Created (current locations after the Copilot migration)
| Path | Purpose |
|---|---|
| `.github/agents/orchestrator.agent.md` | Router / environment & execution VI |
| `.github/agents/test-author.agent.md` | Testing specialist VI |
| `.github/agents/dependency-tracer.agent.md` | Blast-radius VI + domain-reference owner + MIG seam |
| `.github/agents/patch-generator.agent.md` | Core coding VI |
| `.github/agents/consensus-gateway.agent.md` | Validation & consensus VI |
| `.github/agents/scribe.agent.md` | Intake & documentation VI |
| `.github/agents/reference/domains.md` | Domain catalog (owner/modules) + Cross-Domain Adjacency Map |

### Modified (current profile locations)
| Path | Change |
|---|---|
| `.claude/CLAUDE.md` | Slimmed ~19 KB ruleset → routing table + always-on non-negotiables; everything else delegated to VI profiles |
| `.github/agents/dependency-tracer.agent.md` | Declared domain-reference owner; Phase 0 must classify domain; cross-domain gate derives dependent set from adjacency map |
| `.github/agents/test-author.agent.md` | Dependent-domain suite + scenario-flow selection driven by adjacency map |
| `.github/agents/scribe.agent.md` | Added domain-classification step to the intake brief |

---

# PART V — Migration Integrity

* **Zero data loss:** every CLAUDE.md §1–§8 bullet landed in ≥1 profile.
* **Labeled duplication:** cross-cutting rules (test-exec commands, symlink awareness, mock-surface sync,
  cross-domain gate, isolated fixture paths, file-level checks, the three §7 gotchas, line-length 120) are
  duplicated across their relevant VIs, each marked `(shared with … VI)` / `(duplicated …)`.
* **Ownership move ratified:** Manual Steps + post-mortem doc moved from Consensus Gateway → Scribe, keeping
  the Gateway a pure validation quant.
* **Commenting nuance resolved:** "no Jira ticket numbers" is scoped to code/DocBlocks/inline-comments/
  test-names only; the Scribe's docs (incl. `<TICKET>/retrospective.md`) are the deliberate exception.

---

# PART VI — Manual Steps to Reproduce (the migration procedure)

Since this is an infrastructure change, the "repro" is the procedure a teammate follows to re-derive or verify
the swarm:

1. Read the six profiles in `.github/agents/` and confirm each CLAUDE.md §1–§8 bullet appears in ≥1 profile.
2. Confirm the labeled duplications match PART V.
3. Open `.github/agents/reference/domains.md`; confirm the adjacency map covers the sales/inventory/checkout/
   pricing cluster and that Dependency Tracer / Test Author / Scribe reference it.
4. Confirm `.claude/CLAUDE.md` is a pointer (routing table + non-negotiables), not the full ruleset.
5. Dry-run the new-ticket prompt template against a sample ticket and verify the conductor pauses after Red
   and after the Consensus Gateway.

---

# PART VII — Known Gaps & Prioritized Action Plan

| # | Item | Status | Notes |
|---|---|---|---|
| 1 | Six VI profiles + domain reference generated | ✅ | Under `.github/agents/` |
| 2 | CLAUDE.md slimmed to pointer | ✅ | |
| 3 | Domain reference wired into 3 VIs | ✅ | Tracer (owner), Test Author, Scribe |
| 4 | Re-extract `domains.pdf` with `poppler` and diff | ✅ | `poppler` installed; `pdftotext -layout` clean pass diffed against the manual extract. Found + fixed: **Nishant Kapoor** was dropped and his 3 domains (Performance, Commerce Web APIs, IMS/Passport/SSO) were misattributed to Poornima. Payments-block PM split (Ritesh vs Badirinath) is ambiguous in source merged cells — caveat added. Module/adjacency data unaffected. |
| 5 | Verify Claude Code sub-agent mechanics in practice | 🔎 | **Done — see PART IX.** Corrected the hub-and-spoke assumption: recursive delegation IS possible via `tools: Agent(<names>)`. Empirically confirmed mid-session-created agents aren't invocable until reload. Follow-up (#11): after a reload, optionally add a `tools: Agent(...)` allowlist to the Orchestrator + test real routing. |
| 6 | MCP/Agent-SDK orchestration | ⬜ | Decision: stay native for interactive loop; add MCP servers as *tools* (Jira intake, CI/test runner); graduate to Agent SDK only for unattended ticket→PR automation |
| 7 | MIG integration | 🟢 | **Phase 0 COMPLETE — P0-1…P0-8 done.** `dev/tools/impact-graph`: readers + ModuleMap + php-parser scanners + GitDiff + CollaboratorScanner + Resolver + MftfIndex + EdgeCache; wired into Dependency Tracer / Consensus Gateway / Test Author. Corpus suite green (`Tests: 3, Assertions: 5, Incomplete: 1`): mock-impact DoD MET (`getQtyToShip`+`round`) and MFTF index finds `must_include_test`. Cache: MFTF warm ~40% faster; di/events FS-walk-bound. **Only remaining is the separate Phase 1 CALLS epic** (diff-driven acceptance auto-selection); recall held via domain adjacency + MFTF index. Spec §12a–§12f. |
| 8 | Refine domain module lists / adjacencies | 🟡 | First-pass engineering judgments; tighten as real blast-radius data accrues |
| 9 | Commit / stage changes | 🔎 | **Intentionally local-only.** Infra, `.claude/` directives, and `docs/retrospectives/*` are never staged/committed per standing policy. Leave unstaged. |
| 10 | Validate the swarm against a real ticket | ✅ | Dry-ran the new-ticket prompt template against ACP2E-5180 on its own branch (see PART VIII). Both historical blind spots would be caught pre-implementation. |
| 11 | Enable + test real Orchestrator→VI routing | ✅ | **Done & verified after restart.** All six VIs registered; Orchestrator loaded with the intended toolset (`Agent(5 VIs), Bash, Read, Edit, Write, Grep, Glob` — not broken). Live test: `scribe` self-identified from its profile; `orchestrator` recursively delegated to `scribe` (`delegation: success`). |
| 12 | Monolithic-vs-swarm cost baseline | ✅ | **Done — ACP2E-5288 A/B (v2.8).** Ran the identical ticket through a monolithic `CLAUDE.md` and the swarm in isolated worktrees/Docker stacks (only variable = `.claude/` config). Result: swarm **~44% cheaper** ($42.99 vs $76.35), same root cause + near-identical fix, more coverage, and no destructive incident (mono ran an unprompted ad-hoc DB `DELETE` that 404'd its instance). Gap is cache-read-dominated (97.4M vs 43.1M tokens) from context partitioning into subagents; MIG/Phase 0 was 2% of spend, not a cost driver. Full analysis: `experiments/acp2e-5288-mono-vs-swarm/postmortem-ACP2E-5288-mono-vs-swarm.md`; corrections in **PART X**. |
| 13 | ACP2E-5252 retrospective action items | ✅ | Addressed in this session, validated against the codebase rather than taken on the report's word: **(1)** Consensus Gateway — added quote-don't-paraphrase + known-gap cross-check rules (`consensus-gateway.md`) plus an Orchestrator spot-check requirement. **(2)** MIG symlink-traversal bug — root-caused and fixed (`FileScanner.php` missing `FOLLOW_SYMLINKS`; empirically 277/713 = 39% of `di.xml` files were invisible pre-fix); see `docs/mig/mig-phase0-spec.md` §12g. **(3)** Redundant root-cause investigation — Dependency Tracer is now the sole owner, dispatched in parallel with Scribe when a regressing commit/symbol is already known (`orchestrator.md`, `dependency-tracer.md`). **(4)** Named defect classes requiring integration coverage regardless of the 2-file plan-mode default (`test-author.md`). **(5)** Admin-user grid MFTF cleanup flake documented in the swarm's own triage heuristics, not just memory (`test-author.md` §D). **(6)** Orchestrator now logs each VI's `<usage>` block for Scribe's egress step — the platform-level gap (no top-level per-turn token tool) remains unfixed, noted honestly rather than worked around. |

---

# PART VIII — Validation: ACP2E-5180 Dry-Run

Ran the new-ticket prompt template step-by-step against **ACP2E-5180** on its own branch (fix already merged),
comparing each swarm step to what the ticket *actually* did per `docs/retrospectives/ACP2E-5180/retrospective.md`. Every claim
below was grounded in live repo traces (`di.xml`/`events.xml`/MFTF greps + the branch diff), not narration.

### Per-step verdict
| Step | End-state differs? | Process differs? | Evidence |
|---|---|---|---|
| 1 Scribe | new artifact | ✅ intake formalized | Domain classified up front → **Order Management & Delivery** (+ MSI, Tax, Promotions) |
| **2 Dependency Tracer** | — | ✅✅ **surfaces Blind Spot #1 pre-code** | Trace mechanically returns `OBSERVES sales_order_item_cancel` → `Magento_CatalogInventory` + `ext/magento/inventory/InventorySales`; `INTERCEPTS afterGetQtyToCancel` → `Magento_Bundle` plugin; MFTF `@group msi` incl. `AdminCancelOrderWithSimpleProductCustomStockAfterPartialShipmentTest` (the test history broke at R2) |
| 3 Test Author | no (already retrofitted) | 🟡 final shape first pass | `Order/AdminCancellationTest` already scenario-scoped, invariant-first, `Scenario:`/`Expected:` docblocks, `#[DataFixture]` — the conventions reproduce the shipped file |
| 4 Patch Generator | no (identical diff) | ✅ steered to header design | Shipped diff = `Order::registerCancellation` `notCancelable` bucket + `applyCanceledAmounts()` + `getItemCancelableGrandTotal()`, all setters via `priceCurrency->round()` — the header design Step 2 steers to first |
| **5 Consensus Gateway** | — | ✅✅ **Blind Spot #2 pre-declared, not CI-reactive** | Cross-domain gate forces the MSI suite; mock-impact predicts `registerCancellation` gained `getQtyToShip` + `priceCurrency::round` → flags `OrderTest::testRegisterDiscountCanceled`, matching the actual 6-line `OrderTest` fix line-for-line |
| 6 Scribe | same doc | ✅ routine egress | `docs/retrospectives/ACP2E-5180/retrospective.md` becomes a flow output, not a one-off retrospective |

### Conclusion
The swarm reproduces ACP2E-5180's correct final code and tests **byte-for-byte in intent**, and the only real
divergences are exactly where they should be: **Steps 2 and 5 front-load the two blast-radius discoveries that
history made reactively** — after breaking the MSI acceptance suite (Blind Spot #1) and after a CI unit-mock
failure (Blind Spot #2). Both regressions would have been caught before a line of the fix was written.

**Honest caveat:** the dry-run also proves the Dependency Tracer's Phase 0 is *manual grep* today. It produces
the correct blast radius **only if it actually runs the `di.xml`/`events.xml`/MFTF traces every time** — that
discipline is the remaining single point of failure until MIG Phase 0 (item #7) mechanizes it.

---

# PART IX — Verified Sub-Agent Mechanics (item #5)

Verified via the Claude Code guide (docs) + direct inspection of this repo/session. Corrects the early
hub-and-spoke assumption.

| # | Question | Verified answer | Confidence |
|---|---|---|---|
| 1 | Can a sub-agent spawn sub-agents? | **Yes — confirmed live.** `orchestrator` (tools: `Agent(5 VIs)`) delegated to `scribe` and relayed its result (`delegation: success`). **Nuance:** the default (no `tools:` line) grants **all tools incl. Agent**, so any agent can delegate to any agent by default; `tools: Agent(<names>)` **restricts** delegation to the named set. Our early "cannot call other sub-agents" claim was **wrong**; the guide's "without allowlist = hub-and-spoke" was also imprecise. | Empirical |
| 2 | `model:` values | `sonnet` / `opus` / `haiku` / `inherit`; full model IDs also shown in doc examples. Omitted → session default (not explicitly documented). Our profiles use short names → valid. | Docs |
| 3 | Discovery / hot-reload | Not documented. **Empirically:** the six VIs created this session are **absent** from the invocable agent list; invoking `scribe` returned `Agent type 'scribe' not found`. **`/agents` does NOT reload** (the wizard was removed; it only prints guidance) — a **full CLI restart** (quit + relaunch `claude`) is required to register mid-session agent files. | Empirical |
| 4 | Frontmatter schema | Required: `name`, `description`. Optional incl. `tools`, `disallowedTools`, `model`, `permissionMode`, `skills`, `memory`, `mcpServers`, `maxTurns`, `isolation`, `background`, `effort`. Default toolset when `tools:` omitted is not documented (inferred allow-all). | Docs |
| 5 | Invocation | By the `name:` field — natural-language mention, `@"<name> (agent)"`, or CLI `--agent <name>`. (The Agent-tool `subagent_type` param maps to this name.) | Docs |
| 6 | Project vs user precedence | Managed > `--agents` CLI > **project `.claude/agents/`** > user `~/.claude/agents/` > plugin. Project shadows user on name collision. Our profiles are project-scope; no user-scope collisions exist. | Docs |

**On-disk state (verified):** all six profiles present with well-formed frontmatter and correct models
(`haiku`/`opus`/`sonnet`); no user-scope agents; no agent config in `settings.local.json`.

**Consequences for the design:**
* The swarm is **not forced** into hub-and-spoke. A genuine Orchestrator→VI routing layer is achievable by
  adding `tools: Agent(dependency-tracer, test-author, patch-generator, consensus-gateway, scribe)` to
  `orchestrator.md` (action item #11) — deferred until a reload lets us confirm the exact `tools:` schema
  before risking a toolset restriction.
* Until then, and for any newly-added/edited VI, **a session reload is required** before the profiles are
  invocable. The new-ticket prompt template's "top-level session conducts" flow remains the safe default.

**Doc-derived caveats (guide flagged as gaps):** exact hot-reload timing, default `model:` when omitted,
default toolset when `tools:` omitted, and full-model-ID acceptance are not in the public docs — treat as
"verify live" before relying on them.

## Least-privilege toolsets (all six VIs)

**Empirical finding (runtime-probed each VI):** an `tools:` **allowlist** silently **drops `Grep`/`Glob`** — this
version's parser doesn't recognize those tokens, so it granted `Read/Write/Edit/Bash` but not native search,
crippling the analysis VIs. Role-scoping worked (Agent removed from all leaves; Write from analysis VIs; Edit
from the validator), but the search loss forced a redesign.

**Fix — denylist for the leaves.** The five leaf VIs now use `disallowedTools:` (allow-all **minus** the
dangerous capabilities) instead of an allowlist. This preserves `Grep`/`Glob` (and other default tools) while
removing exactly what each role must not have. The **Orchestrator keeps a `tools:` allowlist** because that is
what constrains its delegation to the five VIs (a denylist can't express "Agent, but only these targets"); it
loses native Grep/Glob and searches via Bash / delegates to the tracer.

| VI | Mechanism | Removed | Keeps (incl. Grep/Glob) |
|---|---|---|---|
| orchestrator | `tools:` allowlist | everything except `Agent(5 VIs), Bash, Read, Edit, Write` (no native Grep/Glob) | delegation to the 5 VIs + Bash/Read/Edit/Write |
| scribe | `disallowedTools: Agent` | Agent | all authoring + search |
| test-author | `disallowedTools: Agent` | Agent | all authoring + search |
| patch-generator | `disallowedTools: Agent` | Agent | all authoring + search |
| dependency-tracer | `disallowedTools: Agent, Write` | Agent, Write | Read/Grep/Glob/Bash + Edit (owns `domains.md`) |
| consensus-gateway | `disallowedTools: Agent, Write, Edit` | Agent, Write, Edit | Read/Grep/Glob/Bash (pure validator) |

**Trade-off:** the denylist leaves also retain other default tools (WebFetch/WebSearch/etc.) — broader than a
strict allowlist, but it removes the capabilities that actually matter (delegation; write from analysis/validation
VIs) without breaking search. **Activation:** frontmatter changes apply only after the **next CLI restart**;
`disallowedTools` behavior must be re-probed then (if unsupported it fails *open* — leaves would regain Agent).

### Runtime probe log (allowlist attempt, pre-fix)
`scribe/test-author/patch-generator` → HAVE Read,Write,Edit,Bash · MISSING Grep,Glob,Agent.
`dependency-tracer` → HAVE Read,Edit,Bash · MISSING Write,Grep,Glob,Agent.
`consensus-gateway` → HAVE Read,Bash · MISSING Write,Edit,Grep,Glob,Agent.
(Confirms Agent/Write/Edit scoping worked; Grep/Glob unexpectedly dropped → denylist redesign above.)

### Verification methodology — hard lessons (v2.6)
Attempting to verify the denylist produced **unreliable and contradictory** results, yielding two lessons:

* **Self-report probes are worthless.** Asking an agent to list its tools gave contradictions on *identical*
  config (scribe claimed Grep; test-author/patch-generator said no Grep; dependency-tracer claimed `Write`
  though its config denies it). The model *infers* its toolset rather than measuring it.
* **Functional file-write probes are contaminated by Bash-fallback.** The `dependency-tracer` Write test
  reported `WRITE UNAVAILABLE` **yet the target file was created** — the agent has `Bash` and silently used it
  to write the file despite a "NOT Bash" instruction. So "the file exists" ≠ "the Write tool works."
* **Reliable signal:** a read-only **Grep functional probe**. `test-author` actually attempted Grep and got
  `GREP UNAVAILABLE`.

**Verdict:** the current session is still running the **old allowlist** (Grep and Write genuinely absent — the
`GREP UNAVAILABLE` / `WRITE UNAVAILABLE` reports match that config). The `disallowedTools` denylist edits are
on disk but **not yet loaded — a restart has not happened since they were written**, so the denylist fix is
**unverified**. After a clean restart, verify with **Grep functional probes only** (works ⇒ denylist active)
plus an actual **Agent-delegation attempt** from a leaf (must be refused ⇒ `disallowedTools` didn't fail open).
Do NOT use Write-file probes (Bash-contaminated) or self-report.

---

# PART X — Monolithic-vs-Swarm A/B (ACP2E-5288) & Resulting BDAD Corrections

This part records the first controlled cost/quality comparison of the swarm against the monolithic baseline
(action item #12) and the four changes to the BDAD approach it produced. Full experiment write-up:
`experiments/acp2e-5288-mono-vs-swarm/postmortem-ACP2E-5288-mono-vs-swarm.md`; raw run artifacts (diffs, per-run postmortems, `/cost` reports)
and the archived `CLAUDE-mono.md` live under `docs/experiments/acp2e-5288-mono-vs-swarm/`.

## X.1 Experiment & result

Same ticket (ACP2E-5288, an EE-staging multi-tab Cart-Price-Rule deletion race), two isolated git
worktrees + Docker stacks, the **only** independent variable being `.claude/`: `mono` = the pre-decentralization
flat `CLAUDE.md` (139 lines); `swarm` = the six-VI setup. Each run was driven by a fresh CLI session with an
identical, neutral prompt; quality was judged by blind rubric review against fixed criteria.

**Outcome — swarm won on every axis that separated them:**

| | mono | swarm |
|---|---|---|
| Cost | $76.35 | **$42.99 (−44%)** |
| API time | 1h 35m | 1h 34m (~tied) |
| Fix correctness | ✅ | ✅ (same root cause + near-identical guard — a tie) |
| Cache-read tokens (cost driver) | 97.4M | **43.1M (−56%)** |
| Context profile | one session, 98% >150k context | 54% of usage in subagents; 75% >150k |
| Destructive incident | ✅ ad-hoc DB `DELETE` 404'd the instance | ❌ none |
| Test-reuse discipline | inlined MFTF steps | reused existing action groups |

The 44% gap is **architectural, not luck**: cost scales as *(context size × turns)* at the cache-read rate;
the monolithic session re-reads one ever-growing context every turn, while the swarm structurally shrinks that
factor by pushing work into subagents with their own smaller windows. **MIG/Phase 0 was not the driver** —
`dependency-tracer` was 2% of swarm spend; MIG's value was determinism, not cost.

## X.2 Corrections applied (v2.8)

All four land in the live swarm config; the mono file was archived untouched.

| # | Correction | Files | Rationale |
|---|---|---|---|
| X-1 | **MFTF-first triage for concurrency repros.** Multi-tab / multi-session / concurrent-request / stale-cross-request bugs go straight to MFTF; skip integration variants a single PHP process structurally cannot reproduce. | `test-author.md` (new "Reproduction Vehicle Selection" block), `orchestrator.md` (routing rule), `scribe.md` (`repro-class: multi-session` intake flag), `CLAUDE.md` (non-negotiable) | Both runs burned ~10 dead-end integration variants and the dominant token share before pivoting to MFTF — the single most expensive shared mistake. |
| X-2 | **MFTF + unit only** is the standard deliverable for that bug class — do NOT also ship an in-process integration test that can only reproduce a manufactured pre-corrupted state. | `test-author.md` (same block) | The swarm run's ~400-line integration test cannot exercise the actual race, so it is maintenance encoding the fix's assumptions rather than the bug. User-confirmed decision. |
| X-3 | **No autonomous ad-hoc mutation of shared DB/app state — approval-gated.** Broadened from "`ObjectManager` inside a test method" to any out-of-band `DELETE`/`UPDATE`/raw-SQL/`bin/magento` cleanup outside a fixture/`tearDown()`; requires explicit user approval. | `test-author.md` ("Test State Mutability"), `orchestrator.md` (Operational Commands), `CLAUDE.md` (non-negotiable) | The mono run's unprompted DB `DELETE` dangled `flag.staging` and 404'd the whole instance — the highest-severity difference between the runs. |
| X-4 | **Stale-fact fix:** `CLAUDE.md` labelled the MIG seam "reserved, on hold"; corrected to active (`dependency-tracer.md` documents MIG Phase 0 as active and the swarm run invoked `impact-graph`). | `CLAUDE.md` (swarm table) | Consistency with `dependency-tracer.md` and observed behavior. |

## X.3 Decisions ratified (both user-confirmed)

* **Swarm is the standard going forward.** It was already the live config; kept as-is. The monolithic
  `CLAUDE.md` is retired to `docs/experiments/acp2e-5288-mono-vs-swarm/CLAUDE-mono.md`.
* **Concurrency bugs → MFTF + unit only** (X-2), no approximation integration test.

## X.4 The ACP2E-5288 fix itself

Landed on the `magento2ee` `ACP2E-5288` branch as a single commit (the swarm's output, minus the excluded
integration test per X-2): a persisted-version guard in `Staging\...\Save\SaveAction::execute()` that rejects
a stale-tab reschedule into a concurrently-emptied update, plus its unit coverage and the two-tab MFTF
acceptance test. `php -l` + PHPCS clean; unit suite 7/7 green.

## X.5 Open follow-up

* The two experiment worktrees (`ACP2E-5288-mono`/`-swarm`) and their Docker stacks were **torn down** after the
  retrospective (worktrees removed, branches deleted, containers/volumes/networks pruned). The archived diffs
  and reports under `docs/experiments/` are the durable record.

---

# PART XI — ACP2E-5252 CR Finding & Resulting BDAD Correction

A GitHub CR comment on the `ACP2E-5252` PR caught something the swarm's own Phase 0 output had already
surfaced but not acted on — recorded here because it's a process gap, not a one-off code miss.

## XI.1 What happened

`Magento\PricePermissions\...\Plugin\PricePermissions` has two call sites of the identical "unlock a locked
attribute, then null it" idiom — `nullifyHiddenLockedAttributes()` (the one the ticket's fix targeted) and
`unlockUseDefaultAttributes()` (a sibling method in the same class). Both were missing the same
`isScopeGlobal()` guard since the commit that introduced the vulnerability (`5ff1485c84`). The swarm fixed
only the first.

The Dependency Tracer's Step 2 Blast-Radius Report had **already found and quoted**
`unlockUseDefaultAttributes()` by name — it just wasn't asked the right question. The dispatch prompt framed
the task as "verify the fix doesn't regress the `use_default=1` path" (a regression check against the
*named* method) rather than "does this method have the identical missing guard?" (a check for the *pattern*
elsewhere in the same file). A `grep -n "unlockAttribute("` on the target file at Phase 0 time — trivial,
mechanical — would have surfaced both call sites side by side before any code was written.

Separately, and independently of the CR: the same session's top-level conductor spawned a `general-purpose`
investigation agent in parallel with Scribe for root-cause archaeology. `dependency-tracer.md` already
states Dependency Tracer is the **sole owner** of that work and that a separate investigation agent should
not be dispatched alongside it — this was a conductor-discipline lapse against an existing rule, not a
missing one. Fixed here by making the runbook's copy-paste template carry the same reminder, since that's
the artifact a top-level conductor actually reads.

## XI.2 Correction applied

| # | Correction | Files | Rationale |
|---|---|---|---|
| XI-1 | **Anti-pattern repetition check** — added to Phase 0's "supplement the tool" list: when a fix targets a method for a specific defect pattern, grep the same file/class (and owning module) for other call sites of the identical pattern, not just callers/dependents of the one method already named. | `.claude/agents/dependency-tracer.md` | A defect from a repeated code idiom is rarely isolated to one call site; this is now a standard Phase 0 step instead of depending on how broadly the dispatch prompt happens to frame the target. |
| XI-2 | Runbook's STEP 2 line now carries both the anti-pattern-repetition-check reminder and an explicit restatement that Dependency Tracer is the sole owner of root-cause archaeology (no parallel general-purpose investigation). | `docs/bdad-new-ticket-runbook.md` | The rule already existed in `dependency-tracer.md` but wasn't visible in the artifact the top-level conductor actually copy-pastes from; this closes that visibility gap. |

## XI.3 Not changed

`test-author.md`, `patch-generator.md`, `consensus-gateway.md` — this correction is entirely a Phase 0
(pre-code) concern; the Red→Green→gate steps that followed in ACP2E-5252 were not implicated.

---

# PART XII — Post-ACP2E-5288 Hardening Rules (v2.9)

Two mandatory rules were added to the swarm after landing the ACP2E-5288 fix, both closing gaps that
`php -l`/PHPCS structurally cannot catch. Both are encoded in the VI profiles (local-only, not committed).

## XII.1 — i18n coverage for new `__()` phrases (MANDATORY)

**Trigger:** the fix added a new `LocalizedException` phrase in `Staging\...\Save\SaveAction.php`. A new
translatable string with no `i18n/en_US.csv` entry ships an untranslatable phrase — invisible to lint.

**Rule:** every new UNIQUE translatable string introduced via `__()` must have a matching
`"<phrase>","<phrase>"` source entry in the **owning module's** `i18n/en_US.csv` (owner resolved from the
class's own module, NOT the ticket's domain). Skip only if the exact phrase already exists. A phrase built by
concatenating string literals inside a single `__()` is one joined msgid (add the combined entry, not one per
literal); placeholder tokens (`%1`, `%2`) stay verbatim.

**Where:** `patch-generator.md` (Architecture & Coding Standards bullet + File-Level Check #3) and a `CLAUDE.md`
non-negotiable.

## XII.2 — MFTF generation check (MANDATORY)

**Trigger:** the fix's added action group referenced three `AdminCartPriceRulesFormSection` selectors
(`customerGroupsToggle`/`SelectAll`/`DoneButton`) that were never defined in any Section. MFTF resolves
references only at *generation* time, so it passed `php -l`/PHPCS and broke only the Jenkins EE MFTF build with
`Could not resolve entity reference`. (Fixed by using the real `customerGroups` multiselect.)

**Rule:** after creating or editing ANY `Test/Mftf/*.xml` (Test, ActionGroup, Section, Data, Page), run
`vendor/bin/mftf generate:tests <TestName>` and confirm it completes with no "Could not resolve entity
reference" / parse errors. Every referenced `{{Section.element}}` MUST exist in a committed Section — never
invent selectors; add the missing element to the owning Section instead. `generate:tests` builds the Cest only
(no browser), so it is safe and fast. The repo-wide `NOTICE: … name violations` lines are advisories, not
failures.

**Where:** `test-author.md` (MFTF section, authoring self-check) and `consensus-gateway.md` §C (enforced gate).
No new `CLAUDE.md` line was needed: the existing "code is not complete until the Consensus Gateway confirms
green" non-negotiable already binds whatever is in the gateway workflow.

**§C self-detects its trigger.** Rather than being handed the touched-file list by the Orchestrator (which
would just move the "remembering" risk one hop upstream), the gateway detects affected MFTF files itself with a
`git status`/`git diff` scan for `*/Test/Mftf/*.xml`. The scan iterates every repo root
(`. magento2ee magento2b2b ext/magento/inventory`) because MFTF files usually live in the symlinked EE/B2B/MSI
repos, not CE — a lone CE-root `git status` would have missed the ACP2E-5288 `magento2ee` files that caused the
break. No `orchestrator.md` hand-off was added.

## XII.3 — Prefer core action groups over bespoke base + B2B-merge pairs (v2.10)

Standing authoring guidance (not a gate), surfaced by the ACP2E-5288 B2B MFTF failure — the ticket-side fix
itself is recorded in `docs/retrospectives/postmortem-ACP2E-5288.md`, only this generalized agent-rule lives here.

**Rule:** when an edition swaps an admin field from a plain `<select>` to a JS widget (customer groups on a Cart
Price Rule being the canonical case — `select[name='customer_group_ids']` on CE/EE, a
`div.admin__action-multiselect` widget on B2B), reuse the **core** action group (e.g.
`AdminCreateCartPriceRuleActionGroup`) that already carries the platform-maintained B2B merge
(`<remove keyForRemoval>` of the plain select + widget clicks). Do NOT hand-roll a bespoke base action group
plus a parallel B2B merge to reproduce it — a bespoke duplicate rides the same non-deterministic cross-module
`<remove>` merge with far less coverage and intermittently leaves the plain `selectOption` running against the
widget div (`Strict locator ... malformed`). If the core group lacks something you need (e.g. a coupon), add it
with edition-safe steps AFTER calling it rather than forking it.

**Where:** `test-author.md` (MFTF section).

---

# PART XIII — Four Mandatory Stop Points (v2.11)

**Date:** 2026-09-08

## XIII.1 What changed
The human-in-the-loop model moved from **two** pause points (after Red, after the gate) to **four** mandatory
stop points, giving the operator a review gate at every phase boundary of the canonical chain:

1. **After intake** — Scribe's structured brief (Scenario / Preconditions / Steps / Expected vs Actual +
   domain classification + `repro-class` flag) is ready.
2. **After Red** — the failing test is written and confirmed failing.
3. **After Green** — the fix makes the test pass.
4. **After egress** — the Consensus Gateway verdict is green AND the dev summary + release notes are ready.

A NOT-GREEN gate verdict remains a blocking loop-back (return to Patch Generator / Test Author), not a stop
point; egress (STEP 6) only runs on green, so stop point 4 fires once its human-facing artifacts exist. This
moves the former "after the gate" pause from *before* egress to *after* egress, bundled with the summaries.

## XIII.2 Files touched
* `.claude/agents/orchestrator.md` — replaced the two-pause sentence in **Routing** with a new
  **Mandatory Stop Points (human-in-the-loop)** subsection enumerating the four gates.
* `docs/bdad-new-ticket-runbook.md` — §0 intro + §1 template pause line updated; `[PAUSE HERE]` markers added
  to STEP 1 (intake), STEP 4 (Green), and STEP 6 (egress); STEP 5 reworded as a blocking loop-back; §2
  hands-off note updated; a non-negotiables bullet added.
* `.claude/CLAUDE.md` — new non-negotiable bullet making the four stop points swarm-wide (loaded every session).

## XIII.3 Rationale
Operator requested tighter control during this stage of Swarm development — a review gate at each phase
boundary (intake, Red, Green, egress) rather than only at Red and the gate.

---

# PART XIV — Config-Reload Model Corrected (v2.12)

**Date:** 2026-09-08 · **Supersedes:** PART IX row 3 (Discovery / hot-reload)

## XIV.1 What changed
PART IX (v2.2) recorded, from one empirical test, that mid-session agent files require a **full CLI restart**
and that `/agents` does not reload. A current-docs check (via the `claude-code-guide` agent, cited below)
shows the reload model is finer-grained:

* **Edited EXISTING `.claude/agents/*.md` (body AND frontmatter — `model:`/`tools:`/etc.)** — Claude Code
  watches `.claude/agents/` and picks up the change within seconds; the next delegation uses the updated
  definition. **No restart.**
* **Edited `.claude/CLAUDE.md`** — reloaded by **`/clear`** (project memory re-read for the new context) or a
  restart.
* **NEW agent file** — auto-detected **only if `.claude/agents/` existed at session start**; creating that
  directory for the first time this session, or adding an agent under `--add-dir`, still needs a **restart**.
* **`/agents` / `/memory` / `/config`** — diagnostic only; none reload. Settings files hot-reload on save
  (except startup-read keys like `model`/`effort`, changed via `/model` / `/effort`).

## XIV.2 Reconciliation with the v2.2 empirical finding
The v2.2 test (a freshly-created `scribe` → `Agent type 'scribe' not found` after `/agents`) most likely hit
the **"first agent in a newly-created directory"** caveat, OR the behavior changed in the ~2 weeks since. The
observation is **retained, not deleted** — flagged as a conflicting data point pending a live re-probe. Until
then: for a high-stakes ticket run prefer a fresh session (guarantees everything); for routine mid-session
config edits, hot-watch (agents) + `/clear` (CLAUDE.md) is sufficient.

## XIV.3 Files touched
* `docs/bdad-new-ticket-runbook.md` §0.2 — relaxed "start a new session (loads only at session start)" to the
  per-case reload model above.
* Memory `claude-code-subagent-mechanics.md` (+ `MEMORY.md` index hook) — annotated with the docs verdict,
  date, and re-probe flag; empirical finding retained.

## XIV.4 Source
`claude-code-guide` agent, citing: `sub-agents.md` ("detects the change within a few seconds… no restart
needed"), `commands.md` (`/clear` reloads CLAUDE.md), `settings.md` (settings hot-reload on save).

## XIV.5 Live re-probe (2026-09-08) — RESOLVED
Ran a functional probe (create + edit) through the **in-session Agent tool** — the path the swarm actually
uses to dispatch VIs — with deterministic markers (not agent self-report):

* **New agent** (`probe-reload.md` added to the pre-existing `.claude/agents/`): the *first* dispatch, seconds
  after creation, returned `Agent type 'probe-reload' not found`. ~1 min later the harness surfaced it in the
  Agent-tool registry and it ran, writing its token `RELOAD-CREATE-7Q2X`.
* **Edited existing agent** (`scribe` given a trigger-gated token line): the *first* `PROBEPING` dispatch
  (~1–2s after the edit) ran the OLD body (normal intake). A retry ~1 min later returned the NEW token
  `RELOAD-EDIT-9F4K`, proving the body hot-reloaded.

**Verdict:** hot-reload works for the Agent-tool path — for BOTH new and edited agents — with **no restart**,
but propagation is **not instant** (~1 min here, longer than the docs' "few seconds"). The v2.2 "restart
required" was a **latency false-negative** (dispatched too soon). Operational rule: after editing/creating an
agent, **wait for propagation or dispatch-and-retry**; use a fresh session / `/clear` only to force immediacy.
All probe artifacts (`probe-reload.md`, the `scribe` marker, scratch files) were reverted; `git status` clean.

---

# PART XV — Temporary SVC Workaround: Freeze Private-Method Signatures (v2.13)

**Date:** 2026-09-23 · **Status:** TEMPORARY (remove when the SVC infra bug is fixed)

## XV.1 What changed
Added a temporary coding directive to the **Patch Generator VI**: do NOT change the signature of an existing
`private` method. Keep its parameter list byte-identical to mainline (params, order, types, defaults) even when a
refactor makes a parameter redundant; when a parameter becomes unused, leave it in place and annotate the method
`@SuppressWarnings(PHPMD.UnusedFormalParameter)` to keep the static suite green. Method *bodies* may still change
freely (SVC does not report body changes).

## XV.2 Why
The CI Semantic Version Checker (`magento-semver`) **crashes instead of returning a verdict** when its report
generator meets a private-method signature change: an uncaught `TypeError` in
`ClassMethodOperationUnary::getCode()` (a `null` return under PHP 8.5's strict typing) in the HTML reporter,
exit 255 — failing the whole SVC gate even though a private-method change is not a real backward-incompatibility.
Surfaced on ACP2E-5245, where dropping a redundant `$billingAddress` param from a private method turned an
otherwise-green branch red. The directive is a stopgap so the swarm stops tripping the crash; the real fix is on
the tooling side.

## XV.3 Files touched
* `.claude/agents/patch-generator.md` — new ⚠ TEMPORARY WORKAROUND bullet in **Architecture & Coding Standards**.

## XV.4 Removal condition
Delete the directive once `magento-semver` degrades gracefully on private-method signature changes (tracked as
action item #7 in `docs/retrospectives/ACP2E-5245/retrospective.md`).

---

# PART XVI — Single-Coordinator Topology & Model Pins (v2.14–v2.15)

**Date:** 2026-11-19 · **Source:** `docs/retrospectives/ACP2E-5597/swarmd-dryrun-stage1-report.md` (§7, §10)

## XVI.1 Single-coordinator topology (v2.14)

**Incident.** In the ACP2E-5597 Stage 1 dry run, the main session dispatched the Orchestrator as a
synchronous one-shot subagent. It then dispatched Scribe and Dependency Tracer itself and tried to send their
outputs back to that Orchestrator. The runtime rejected the follow-up because the agent was synchronous
(`write_agent only supports background agents`). The Orchestrator therefore produced no synthesis.

**Root causes (validated independently by a second model):**

| # | Cause |
|---|---|
| 1 | The Orchestrator description said "Route work here first", which invited the model to invoke it as a subagent. |
| 2 | `tools: [shell, read, write, edit]` had no `agent` alias, so the Orchestrator could not dispatch even as the session agent. Its Routing prose described a Claude-era `Agent(...)` allowlist that does not exist in Copilot. |
| 3 | The shared policy named an undefined "top-level conductor" separate from the Orchestrator. |
| 4 | No profile defined the specialist lifecycle: whether it runs synchronously or in the background, who receives its output, and whether follow-ups are allowed. |

**Changes:**
* `orchestrator.agent.md`:
  * Description: the profile runs as the session agent and is never a subagent.
  * Frontmatter: `disable-model-invocation: true` and `tools: ["*"]`. The `agent` alias, agent read/write, Glean/Scout
    MCP, and user interaction are all required. A restrictive list silently drops unrecognized tokens.
  * New **Execution Model (single coordinator)** section:
    * If the Orchestrator cannot dispatch, it is running as a subagent and returns `BLOCKED`.
    * It alone owns MCP evidence, dispatch, synthesis, workflow state, and stop points.
    * Specialists are one-off and synchronous by default. Use background agents only when a follow-up is planned.
    * Never send `write_agent` to a synchronous agent.
    * Default Stage 1: Orchestrator → Scribe → Orchestrator → STOP 1. The pre-intake Tracer runs only under the
      named-symbol exception.
  * Usage log records the model ID, or `unknown` when the runtime does not expose it. Never estimate it.
* `.github/copilot-instructions.md`: new **Coordination topology** section; "top-level conductor" → "main-session
  Orchestrator".
* `scribe.agent.md` and `dependency-tracer.agent.md`: "top-level conductor" → "main-session Orchestrator".
* All five specialist profiles: added a contract. Each specialist returns its output to the Orchestrator and must not
  dispatch other VIs, advance gates, or declare stop-point decisions.

**Unchanged:** the four mandatory human stop points, the VI roles, and Red-Green-Refactor sequencing.

## XVI.2 Model pins (v2.15)

| VI | Previous (comment) | Pinned `model:` | Rationale |
|---|---|---|---|
| Orchestrator | `# model tier: sonnet` | `claude-opus-5.5` | It owns gates and synthesis across a long session. Errors propagate downstream, so this role needs the strongest reasoning. |
| Scribe | `# model tier: sonnet` | `claude-sonnet-5.5` | Faithful evidence normalization and prose. A mid-tier model is enough. |
| Dependency Tracer | `# model tier: opus` | `mai-code-2` | A code-focused model with a large context window (922K) for code and MIG output. A different provider from the Orchestrator makes the spot-check independent. |

The IDs match the CLI subagent catalog exposed on 2026-11-19. Choices are judgment-based; no benchmark or
cost data was available.

## XVI.3 Verification pending
* Rerun Stage 1 in a fresh `copilot --agent orchestrator` session. Confirm that:
  * the Orchestrator can dispatch;
  * specialist outputs return directly to it;
  * no `write_agent` call targets a synchronous agent;
  * the reported child model IDs match the pins.
* Confirm whether `/subagents` per-agent overrides take precedence over frontmatter `model:`.
* Confirm whether `disable-model-invocation` is honored by the CLI. GitHub documents it for the cloud agent. The prose
  rule and `BLOCKED` preflight are the fallback.
* Optional A/B: rerun with the alternates (GPT-6.2 Sol / GPT-6.1 Terra / Claude Sonnet 5.5) and compare usage, citation
  quality, and agreement with the ACP2E-5597 triage.

## XVI.4 Run 2 follow-ups (v2.16)

Source: `docs/retrospectives/ACP2E-5597/swarmd-dryrun-stage1-report.md` §9 (native run 2: topology checks passed).
Each item was confirmed individually by the user.

| # | Finding | Decision | Files |
|---|---|---|---|
| 1 | The pre-intake Tracer used 93 tool calls with no budget | The pre-intake brief states a budget (default 30 tool calls / 150 output lines). At the limit, the Tracer returns `PARTIAL` with gaps. Formal MIG Phase 0 is uncapped. | `orchestrator.agent.md`, `dependency-tracer.agent.md` |
| 2 | The Tracer had no Scout or `search`, so it fell back to native `UNINDEXED` search | `tools: [shell, read, edit, search, "scout/*"]`. `memory_write` and `memory_vote` are forbidden. The Orchestrator passes seeds only (symbols, exact anchors, freshness). | `dependency-tracer.agent.md`, `orchestrator.agent.md` |
| 3 | `scout-ensure_fresh` was skipped | Mandatory before any Scout evidence enters a handoff. Record status, gaps, and `derived_views_verified`. On failure, retry once, then label the evidence `freshness unverified`. Re-run after `setup.sh` or a checkout. | `orchestrator.agent.md` |
| 4 | A wrong handoff range was propagated to Scribe | The Orchestrator cites exact Scout ranges labelled by construct, never approximate lines. Scribe carries ranges through unchanged and marks unread ones "per Orchestrator handoff". | `orchestrator.agent.md`, `scribe.agent.md` |
| 5 | Harness denials (`git -C … log` does not match `shell(git log)`); the runbook still described the subagent hand-off | Runbook §1 now uses a main-session Orchestrator. §2 is a non-interactive dry-run harness with `shell(git:*)` and mutating subcommands denied, `rg` and `date` allowed, and python not allowed. §0.2 and §4 were updated. | `docs/bdad-new-ticket-runbook.md` |
| 6 | Per-dispatch usage is `unknown` in-session | Launch with `--usage-output-file ~/.copilot/usage/<TICKET>-<date>.json`. At egress, the retrospective's Usage section is marked `pending session end` and filled in from that file afterwards. | `orchestrator.agent.md`, `docs/bdad-new-ticket-runbook.md` |

**Verification pending:** run 3 must show the Tracer calling Scout tools (to confirm that `scout/*` in the
frontmatter is honored), staying within budget, an `ensure_fresh` record in the execution log, and no harness
denials of read-only git.

## XVI.5 Behavior contract (v2.17)

Aligns the agent profiles with the workshop plan §10.3, where Scribe's intake output is the BDAD behavior
contract. Design decisions confirmed by the user:

| Decision | Choice |
|---|---|
| Relation to the brief | Added as a **Behavior contract** section. Scenario, Preconditions, Steps and Expected vs Actual stay, and Steps remain the manual repro. |
| Scenario set | One `Defect:` scenario (fails before the fix) plus `Invariant:` scenarios (hold before and after); max 5 |
| Then clauses | Observable and deterministic, in stakeholder language. No timings or class names; performance defects are stated structurally. Unresolved points are marked `# OPEN:`. |
| Consumers | The Orchestrator presents it verbatim at STOP 1 for approval or amendment. Test Author implements one method per scenario, with the docblock derived from it, and reports per-scenario status in Red. Scribe carries the approved contract into the retrospective. |

Files: `scribe.agent.md`, `orchestrator.agent.md`, `test-author.agent.md`, `docs/bdad-new-ticket-runbook.md`
(STEP 1). Verification is pending: the next Stage 1 dry run must show the `gherkin` block in `1-scribe.md` and,
verbatim, in `stop1.md`.

