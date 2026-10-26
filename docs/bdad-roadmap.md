# BDAD Swarm — Roadmap & Architecture

| | |
|---|---|
| **Purpose** | The single "what the Swarm is + where it's going" doc: the orchestration model, the canonical execution chain, and the prioritized improvement backlog. |
| **Last updated** | 2026-10-26 |
| **Companions** | `docs/bdad-changelog.md` (history of every change), `docs/bdad-new-ticket-runbook.md` (how to run a live ticket), `.github/agents/*.agent.md` (the VIs — authoritative per domain), `.github/agents/reference/domains.md` (domain map), `docs/mig/mig-phase0-spec.md` (impact-graph tool) |
| **Naming** | BDAD means **Behavior Driven Agentic Development**. The doc family is `bdad-*`; behavior and acceptance criteria frame the work, with the existing Red-Green-Refactor cycle providing executable evidence. |

---

## 1. Orchestration model (how CLAUDE.md + agents fit together)

**Decentralized authority.** `.claude/CLAUDE.md` is a deliberately thin router — a Swarm table plus the
always-in-force non-negotiables. The detailed rules live in each `agents/*.md` profile, which is the
*authoritative, complete* source for its domain. Nothing is re-derived from the root.

**Each VI sees only its own profile + `CLAUDE.md`.** A dispatched subagent does **not** load its sibling
profiles. This is why some rules are intentionally duplicated across profiles (see backlog **R-DEDUP**).

**Cost-tiered models + mechanization.** Reasoning-heavy roles run on Opus; coordination/prose on Sonnet;
mechanical validation on Haiku. Deterministic work (blast radius, mock-impact, MFTF selection) is pushed out
of the LLM into the MIG `impact-graph` tool.

| VI | Model | Owns |
|---|---|---|
| **Orchestrator** | Sonnet | Router + workflow state; env/exec commands, `setup-swarm.sh`, Red-Green-Refactor sequencing, stop-point gating, usage logging, verdict spot-check |
| **Scribe** | Sonnet | Intake (structured brief + domain + concurrency flag) and egress (Manual Steps, dev summary, release notes, postmortem) |
| **Dependency Tracer** | Opus | Phase 0 blast-radius (MIG), root-cause archaeology, mock-surface sync, symlink-aware resolution, domain map owner |
| **Test Author** | Opus | Red-first tests, repro-vehicle selection, MFTF authoring + failure triage, invariant-first assertions |
| **Patch Generator** | Opus | Green implementation under PSR-12/strict-typing/DI/Cloud-compat/i18n |
| **Consensus Gateway** | Haiku | Deterministic validation (php -l, PHPCS, static suite, MFTF gen), cross-domain gate, binary green/not-green verdict |

---

## 2. Canonical execution chain

```
 STEP 0  Prereqs: branch off 2.4-develop · fresh session (or /clear after config edits) · ticket.md
            │
 STEP 1  SCRIBE ──▶ structured brief (Scenario/Preconditions/Steps/Expected vs Actual)
            │        + domain classification + repro-class flag        ✋ STOP 1 (confirm brief)
            │
 STEP 2  DEPENDENCY-TRACER ──▶ Phase 0 Blast-Radius Report (MIG --diff/--mock-impact/…)
            │        + domain-adjacency fan-out + anti-pattern repetition grep
            │        (may run parallel to Scribe if regressing symbol known at intake)
            │
 STEP 3  TEST-AUTHOR ──▶ FAILING test first, run with --filter                ✋ STOP 2 (Red)
            │        concurrency? → MFTF + unit guard, skip integration
            │        ⟲ Loop-back A (inner TDD): STEP 3 ⇄ STEP 4 until Green
 STEP 4  PATCH-GENERATOR ──▶ implement until Green                            ✋ STOP 3 (Green)
            │
 STEP 5  CONSENSUS-GATEWAY ──▶ php -l · PHPCS · static suite · MFTF gen · cross-domain · mock-sync
            │        NOT-GREEN ⟲ Loop-back B: back to Patch/Test Author until green (not a stop point)
            │        GREEN → proceed to egress
 STEP 6  SCRIBE ──▶ dev summary · release notes · postmortem      ✋ STOP 4 (green + summaries ready)
```

**Four mandatory human stop points** (halt, surface, wait for explicit go-ahead): (1) intake brief ready,
(2) Red, (3) Green, (4) Consensus green + dev summary + release notes.

**Two loop-backs:** **A** — the inner Red⇄Green TDD cycle (design loop, expected). **B** — the gate rejection
(rework loop; every iteration is a specialist miss — its frequency is a quality signal).

**Two operating modes:** *top-level-conducted* (recommended — the human conducts, both pause points under
control) and *hands-off* (delegate the whole ticket to the `orchestrator` VI). See runbook §1–§2.

---

## 3. Config & reload model (brief)

Agent `.md` files are read from disk by every session. New/edited agents hot-reload **without a restart** but
with a **~1 min propagation delay** (dispatch-too-soon hits the stale definition — a latency false-negative,
not a restart requirement). `CLAUDE.md` edits need `/clear`. Full detail: runbook §0.2 + changelog PART XIV.

---

## 4. Improvement backlog (prioritized — proposal, iterate here)

Priority: **P0** do-next · **P1** high-leverage · **P2** valuable · **P3** someday/edge.
Effort: **S** <½ day · **M** ~1–2 days · **L** epic.

**▶ Active batch (agreed 2026-09-08):** R-DEDUP, R-FLOWTRACE, R-DIFFREF, R-COST (marked ▶ below).

| ID | Item | Pri | Effort | Rationale / notes |
|---|---|---|---|---|
| ▶ **R-DEDUP** | De-duplicate cross-profile rules: extract multi-role rules (cross-domain gate, mock-sync, execution commands, `$PWD/` gotcha, i18n, no-ad-hoc-DB) into canonical `reference/*.md`; profiles reference them; add sync-anchor markers + a drift-check script | P0 | M | User-requested. Real drift hazard — edit one copy, siblings go stale, two VIs disagree, no detector. Keep in-context copies only where guaranteed load matters; mark + lint the rest. |
| ▶ **R-FLOWTRACE** | Add a "Flow trace / swarm telemetry" section to postmortems: per-VI order, retries, loop-back B count, per-step `<usage>` | P1 | S | Directly enables retrospectives (this whole effort). Current postmortems capture code-outcome only, not process. Orchestrator already logs usage — just needs a template + egress wiring. |
| ▶ **R-DIFFREF** | Postmortem "Change coordinates" block (branch, merge-base SHA, tip SHA, `git diff` cmd) + `.patch` sidecar for tickets whose isolated diff is unrecoverable | P1 | S | Traceability without diff-duplication. Already needed: ACP2E-5288's isolated diff is no longer recoverable from git. |
| **R-PMDIR** | Per-ticket retrospective layout adopted on 2026-10-07: `docs/retrospectives/<TICKET>/retrospective.md`, with existing `<TICKET>.diff` sidecars preserved beside it. Scribe, runbook, and compatibility-pointer paths are updated. **Remaining work:** optionally backfill legacy flat reports; add automatic sidecar refresh against the current merge-base during STEP 6 on every full egress cycle, including after CR/QA rework | P1 | S | Scribe-owned. Directory and filename migration is complete; automatic sidecar refresh remains pending. Keeps R-DIFFREF's isolated diff beside the retrospective. Pairs with R-DIFFREF/R-FLOWTRACE. |
| **R-SECSCRUB** | Scribe sanitizes security-sensitive wording in shipped code artifacts (docblocks, inline comments, test data-set/method names, commit & PR text) so they describe *what the code does*, never *what attack it defeats* — no exploitation instructions in customer-shipped source. Keep exploit detail in the local-only postmortem. Add a security-fix egress scrub to the Scribe profile + runbook STEP 6; flag terms like `bypass`/`smuggl*`/`payload`/`hide*`/`exploit`/`attack`/`masked by`. | P1 | S | Scribe-owned. Discovered on ACP2E-5351: the first-cut fix docblock ("…can no longer hide a payload from the filter", "smuggled tag-open sequences…") and test keys (`event-handler-smuggling-*`, `*-hidden-in-*`) spelled out the bypass in source that ships to possibly-unpatched customers; scrubbed manually post-hoc. Pairs with R-PMDIR — exploit detail lives in the postmortem, not the code. |
| ▶ **R-COST** | Reduce cache-read-dominated subagent overhead (97.4M vs 43.1M tokens in the A/B) — trim duplicated context / profile size / selective delegation | P1 | M | In the active batch (elevated from P2 on 2026-09-08). Swarm is already ~44% cheaper overall, but the gap is cache-read from context partitioning. Best measured AFTER R-DEDUP, since de-dup attacks the same root cause. |
| **R-MIG1** | MIG Phase 1 CALLS closure — cross-file caller/callee graph for diff-driven acceptance auto-selection | P2 | L | Removes the domain-adjacency+grep fallback for one-hop edges. Current fallback works; big epic. (changelog #7) |
| **R-DOMAINS** | Tighten domain module lists / adjacencies as real blast-radius data accrues | P3 | ongoing | First-pass judgments today. (changelog #8) |
| **R-MCP** | MCP / Agent-SDK orchestration for unattended ticket→PR automation | P3 | L | Decision: stay native for the interactive loop; graduate only for unattended automation. (changelog #6) |
| **R-STOPTUNE** | Evaluate auto-advancing low-risk stop points in hands-off mode (4-gate overhead vs autonomy) | P3 | S | Just introduced the 4 gates; gather data before relaxing. |
| **R-RELOAD-EDGE** | Verify GitHub Copilot profile reload behavior for new and edited `.github/agents/*.agent.md` files | P3 | S | Re-scoped after the 2026-10-07 Copilot migration. Historical Claude Code probes do not establish Copilot behavior. |

---

## 5. Recently closed (moved out of backlog)

- **R-BDAD — BDAD naming adopted** — completed 2026-10-26 across Swarm docs, profiles, filenames, and references. BDAD means Behavior Driven Agentic Development; the existing six roles, Red-Green-Refactor workflow, and four mandatory human stop points are unchanged.
- **4 mandatory stop points** — shipped v2.11 (was: 2 pause points). Changelog PART XIII.
- **Config-reload model corrected + live re-probed** — shipped v2.12–v2.13. Changelog PART XIV.
