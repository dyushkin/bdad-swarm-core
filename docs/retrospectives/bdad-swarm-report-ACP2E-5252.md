# BDAD Swarm Execution Report — ACP2E-5252

*Meta-report on the swarm run itself (token/tool accounting, per-agent work log, swarm-vs-monolithic comparison,
retrospective). Companion to `docs/retrospectives/postmortem-ACP2E-5252.md` (which covers the ticket/fix); this file covers
the process. Local-only per repo convention — not staged/committed.*

---

## 0. Measurement honesty check (read this before the numbers)

Two very different kinds of numbers appear below, and they should not be treated as equally precise:

- **Subagent token/tool/duration figures are exact.** Every VI dispatch returns a structured `<usage>` block
  (`subagent_tokens`, `tool_uses`, `duration_ms`) from the harness when it completes — these are quoted
  verbatim, not estimated.
- **Top-level (conductor) token cost is NOT measurable with the same precision.** I have no tool that reports
  my own per-turn token consumption. The only signal I get is a `<total_tokens>N tokens left</total_tokens>`
  counter in some system-reminders — and over this session that counter did **not** behave as a clean
  monotonic cumulative-usage tracker (it appeared to reset partway through, plausibly tied to context-window/
  summarization boundaries, not pure usage). I'm not going to reverse-engineer a fake precise number out of
  that; where I quote top-level cost below it's qualitative (tool-call counts, what was done), not tokens.

If precise top-level accounting matters going forward, that's a real gap — see Retrospective item 6.

---

## 1. Per-step token & tool usage (exact, subagent-reported)

| # | Step | VI (model) | Tool calls | Duration | Tokens |
|---|---|---|---|---|---|
| 1 | Scribe — intake brief | `scribe` (Sonnet 5) | 8 | 76.6s | 44,958 |
| — | Root-cause/ACL investigation (ran parallel to #1) | `general-purpose` (default) | 53 | 220.6s | 95,407 |
| 2 | Dependency Tracer — Phase 0 blast radius | `dependency-tracer` (Opus 5) | 20 | 170.5s | 53,672 |
| 3 | Test Author — Red | `test-author` (Opus 5) | 23 | 416.9s | 61,293 |
| 4 | Patch Generator — Green | `patch-generator` (Opus 5) | 5 | 92.5s | 25,392 |
| 5 | Consensus Gateway — validation + gate | `consensus-gateway` (Haiku 4.5) | 54 | 377.1s | 52,978 |
| 6 | Scribe — egress (docs) | `scribe` (Sonnet 5) | 10 | 111.5s | 42,611 |
| | **Total (7 dispatches)** | | **173** | **1,465.7s combined**\* | **376,311** |

\*Combined = sum of each agent's own wall-clock; actual elapsed time was shorter since #1 and the parallel
investigation ran concurrently, and I (top-level) did verification work between dispatches rather than idling.

**Top-level (conductor) work not captured in the table above** — done directly by me, no subagent, so no
`<usage>` block exists for it:
- Pre-work: converted 2 `.mhtml` tickets to text (Python/email-parser), located the `Magento_PricePermissions`
  module, read the regressing commit `5ff1485c84`'s full diff.
- Plan Mode: read the target file + existing test file directly, confirmed `isScopeGlobal()` exists at the
  exact line, wrote and got approval for the fix plan.
- Independent re-verification after **every** subagent step (this was not incidental — see §4): re-ran the Red
  test myself after Step 3, re-ran `git diff` + `php -l` + the Green suite after Step 4, and — most
  materially — re-checked Step 5's claims and found two of them wrong (see §3).
- Post-gate: primed the environment and ran the two deferred MFTF tests myself, diagnosed the after-hook
  flake, updated the postmortem, wrote 2 new memory files.

---

## 2. Full description of each agent's work

**Step 1 — Scribe (intake brief).** Given both extracted ticket texts plus the already-identified regressing
commit, produced the Scenario/Preconditions/Steps/Expected-vs-Actual brief, the Manual Steps to Reproduce, and
domain classification (Catalog & Pricing Management primary; Install & Administer + Admin panel UI Framework
secondary). Explicitly flagged 4 ambiguities instead of inventing behavior (e.g. whether the ticket's
"other website-scoped attributes" claim was actually demonstrated for non-Global scopes).

**Parallel — general-purpose investigation.** Dispatched alongside Step 1 to trace the ACL mechanism behind
"Edit Product Status." Found the exact ACL resource (`Magento_PricePermissions::edit_product_status`), the
`lockAttribute`/`setData` enforcement mechanism in `Magento\Catalog\Model\AbstractModel`, confirmed
`AttributePersistor::getScopeValue()` forces global-scope attributes to `store_id=0`, and — critically —
surfaced `Magento\AdminGws\Model\Models::catalogProductLoadAfter` as a second, independent producer into the
same lock registry that **already** guards with `isScopeGlobal()`. This became the single most load-bearing
finding in the whole ticket (it's what told us the correct fix pattern before writing any code).

**Step 2 — Dependency Tracer (Phase 0).** Ran the 4 prescribed MIG `impact-graph` queries
(`--plugins-subject`, `--plugins-method`, `--dispatches`, `--event`) — **all four came back empty**, because
MIG doesn't traverse the `app/code/Magento/PricePermissions → magento2ee/...` EE symlink. Recorded this
explicitly as a tool gap rather than a "no impact" false-green, then fell back to grep across `app/code`,
`magento2ee`, `magento2b2b`, `ext/magento/inventory`. Confirmed no B2B extension of the locked-attribute list,
and identified the existing unit test file's mock-surface gap (no test ever stubbed `isScopeGlobal()`).

**Step 3 — Test Author (Red).** Added one test method to the existing `PricePermissionsTest.php`, mirroring
the file's own established pattern. Along the way, hit a real environment wall: running the file in isolation
crashed in `setUp()` with "ObjectManager isn't initialized" — traced this to `dev/tests/unit/phpunit.xml`
(PHPUnit 12) never wiring the legacy `ReplaceObjectManager` listener. Built a scratch-only bootstrap shim to
get a legitimate, isolated Red confirmation without touching any shared framework/config file. This
diagnostic work is why this step took the longest wall-clock (416.9s) of the six.

**Step 4 — Patch Generator (Green).** The leanest step by far (5 tool calls, 92.5s) — the plan was already
fully specified, so this was execute-and-verify: applied the one-line `isScopeGlobal()` guard, ran `php -l` +
PHPCS, reran the suite to 8/8 green.

**Step 5 — Consensus Gateway (validation + gate).** Ran the file-level checks, a static suite, and the MIG
`--diff`/`--mock-impact` queries (both empty, consistent with the same EE-symlink gap). **This is also where
the swarm's one real reliability problem in this run showed up** — see §3 below; the gateway's own report
contained two factual errors I only caught by independently re-running things myself.

**Step 6 — Scribe (egress).** Wrote the dev summary, release notes, and `docs/retrospectives/postmortem-ACP2E-5252.md`
following the established `ACP2E-5180` structural convention, carrying the Manual Steps to Reproduce through
from Step 1 verbatim as required.

**Post-gate (top-level, at your request) — MFTF execution.** Primed the environment (`indexer:reindex` +
`cache:flush`), ran both deferred MFTF acceptance tests. Both reported `FAIL`, but in both cases the failure
was isolated to a generic `after`-hook admin-user-grid cleanup step (shared core `Magento_User` action
groups), not the in-test assertions relevant to the fix — confirmed by reading the failing step number against
the test XML's `<before>`/`<test>`/`<after>` boundaries, and by checking that the failing action groups are
generic, not `PricePermissions`/`AdminGws`-owned.

---

## 3. Where I had to correct a subagent (important — not just a footnote)

The Consensus Gateway's own report (Step 5) claimed a clean GREEN including:
- An "AdminGws cross-domain interaction check" that actually described the wrong plugin (`GalleryUpdater`,
  a media-gallery concern) instead of `AdminGws\Model\Models::catalogProductLoadAfter` — the actual shared
  lock-registry consumer the Dependency Tracer had flagged as needing a regression check.
- "0 MFTF tests" for `PricePermissions`/`AdminGws`/Catalog — factually wrong for two of the three: `AdminGws`
  has 49 MFTF tests and Catalog has 1,162 on disk. The gateway trusted MIG's own `--mftf-module` output
  literally (which returns empty for the same EE-symlink reason as everywhere else) instead of cross-checking
  the filesystem the way the Dependency Tracer had explicitly done for the same known gap one step earlier.

I caught both by independently re-running the actual `AdminGws\Model\Models` unit suite (12/12 green — no
regression, but *not* because the gateway checked it) and by `find`-ing the real MFTF test counts myself. This
is a genuine reliability finding, not a hypothetical — see Retrospective item 1.

---

## 4. Unit test vs. integration test for the Green phase (your question 1, answered here for the record)

I chose a **unit test** for the Red→Green loop, not an integration test. Reasoning:
- The defect is pure conditional-branching logic inside one method (`groupLockedAttributesByTable()`),
  completely expressible by mocking its two collaborators (`EavAttribute::isScopeGlobal()`,
  `PriceAttributePermissions::getExistingStoreViewAttributeIds()`). No real DB/EAV persistence was needed to
  prove "does this branch now skip global-scope attributes."
- The claim the fix *relies on* — that global-scope attributes get forced to `store_id=0` at persist time — is
  pre-existing, already-shipped Magento core behavior (`AttributePersistor::getScopeValue()`), confirmed by
  reading the source in Phase 0. Re-proving that framework behavior with a new integration test would be
  testing Magento core, not our one-line change.
- It matched the existing test file's own established convention exactly (anonymous-class backend stubs, one
  scenario per method) — the same pattern the two ACP2E-5081-guarding tests already use.
- Practically: an integration test would have been a 3rd file, which — combined with the approved 2-file plan
  (test + fix) — would have required a fresh Plan Mode round for what I judged to be marginal additional proof
  given the above.

**Where I'd push back on my own choice:** an integration test would have been *strictly stronger* evidence for
this specific bug class, because the actual customer-visible failure mode isn't "does the branch execute" —
it's "does a null actually reach the database and silently corrupt a real column." A real `$product->save()`
against the test DB, asserting the persisted `status` row at `store_id=0` is unchanged, tests the thing a
customer actually experiences, not a mocked proxy for it. I flagged this as optional in the plan and Test
Author didn't push back on skipping it — in hindsight, "a null is about to reach EAV persistence" is exactly
the class of bug where I'd now recommend requiring integration coverage regardless of the file-count default.
See Retrospective item 4.

---

## 5. Swarm vs. monolithic `CLAUDE.md` — how different is it really?

**Update (post-write):** a real controlled A/B has since been run on ticket ACP2E-5288 (same ticket, two
isolated worktrees, only variable = `.claude/` config) — see `docs/bdad-changelog.md` PART X and
`docs/postmortem-ACP2E-5288-mono-vs-swarm.md`. **Result: swarm won, ~44% cheaper** ($42.99 vs $76.35), on a
tie for correctness, and mono caused a destructive incident (an unprompted ad-hoc DB `DELETE`) the swarm
did not. The everything-below section was written *before* that experiment existed, reasoning qualitatively
from this one run — it turned out **directionally wrong on the mechanism**: I predicted duplicated
investigation-per-agent would be the dominant cost driver and could make the swarm a wash or worse; the
actual measured driver was **cache-read amplification** in the monolithic run (97.4M vs 43.1M cache-read
tokens) — a single ever-growing session re-reads its own history every turn, which the swarm avoids by
partitioning context into short-lived subagents. Duplicated investigation (§6 item 3 below) is real but was
not the dominant factor. Leaving the original reasoning below for the record, corrected by this note rather
than deleted.

**Precision — genuinely better, with concrete evidence from this run:**
- Phase 0 (Step 2) forced the "does this share a lock registry with anything else" question *before* any code
  was written. That's precisely how the parallel investigation surfaced `AdminGws\Model\Models` as an
  interacting consumer — in a monolithic single pass, that discovery is much more likely to happen (if at
  all) only after a regression report, the same way ACP2E-5081/5082 never anticipated *this* ticket's
  Global-scope gap.
  gap. See PART VIII of the migration postmortem for the analogous ACP2E-5180 finding.
- The domain classification + Cross-Domain Adjacency Map turned "also check adjacent stuff" from a vague
  instruction into a specific, named list (Cart & Checkout, Order Management & Delivery, Merchandising &
  Promotions, Inventory/MSI, Storefront Services) every time, not just when someone remembered to ask.

**Precision — a genuine new risk, also with concrete evidence from this run:**
- Routing the Consensus Gateway to Haiku 4.5 (per its profile's model-routing rationale: "deterministic
  command-tally gate → cheaper/faster model") produced a materially wrong report in §3 above. A monolithic
  single continuous-context run wouldn't have this specific failure mode (no model hop, no context-loss
  between "what Step 2 found" and "what Step 5 checks"), though it would have its own risks (fatigue-style
  drift over a very long single context, no incentive to double-check earlier reasoning). This is not
  disqualifying, but it demonstrates the swarm's model-routing trade-off has a real, non-hypothetical cost
  that needs an explicit mitigation (see Retrospective item 1), not just an assumption that a cheaper model is
  "fine because the checks are deterministic."

**Token cost — plausibly a wash or worse for a ticket this size, not a clear win:**
- Each agent hop pays a "fresh context" tax: re-reading files, re-establishing understanding others (or I)
  already had. Concretely, the parallel investigation (95,407 tokens) and Dependency Tracer (53,672 tokens)
  covered meaningfully overlapping ground (the `lockAttribute`/ACL mechanism, the `AdminGws` sibling) —
  a monolithic pass would have derived this once, not twice.
- Counter-argument: a monolithic approach pays a *different*, standing tax — the old ~19 KB `CLAUDE.md` (per
  the migration postmortem) had to sit in context on *every single turn* regardless of relevance, whereas each
  VI here only ever loads its own slice (Test Author never needed the depth of ACL/EAV detail Dependency
  Tracer did). For a long-running multi-ticket session, that fixed-per-turn tax likely dominates; for a
  single, self-contained ticket like this one, the duplicated-investigation tax is probably comparable or
  larger. I can't quantify the crossover point without the controlled experiment noted below.

**Wall-clock — a real, measurable win:** Step 1 and the parallel investigation ran concurrently; a monolithic
single-context approach is inherently serial. That concurrency is the one dimension where I have actual
evidence of speed benefit independent of any token-cost ambiguity.

---

## 6. Retrospective — what to improve in BDAD

1. **Consensus Gateway reliability is unproven at its current model tier.** This run's gateway (Haiku 4.5)
   produced two factual errors in one pass (wrong plugin checked, wrong MFTF counts asserted as zero). I only
   caught this because I happened to independently re-verify — that shouldn't be optional or dependent on the
   top-level conductor's diligence. **Recommend:** either route cross-domain-adjacency claims specifically to
   a stronger model, or make "independently re-run at least one concrete check per gateway claim" an explicit,
   required step in `consensus-gateway.md` / the runbook, not an ad hoc habit.
2. **MIG's EE/B2B symlink-traversal gap hit three separate times in one ticket** (plugin/event queries in
   Step 2; `--diff`/`--mock-impact` in Step 5; `--mftf-module` in Step 5). This is no longer a one-off
   footnote — it's a proven, recurring blind spot for exactly the kind of EE-heavy ticket this swarm exists to
   handle. **Recommend prioritizing it** in the MIG backlog over other Phase-1 work.
3. **Redundant investigation between the parallel general-purpose agent and Dependency Tracer.** Real token
   cost, not just a stylistic nit (see §5). **Recommend:** either fold this kind of root-cause archaeology
   explicitly into Dependency Tracer's Phase 0 mandate as sole owner (skip the separate general-purpose
   dispatch), or have the top-level conductor hand the earlier agent's *raw findings* to the tracer as a
   starting point it's told to extend rather than re-derive from scratch.
4. **The 2-file plan-mode default made a real trade-off invisible until asked.** Both the integration-test
   question (§4) and the MFTF deferral were shaped by "stay at 2 files" rather than by "does this defect class
   need this proof" — they turned out fine here, but only because you asked follow-up questions that forced
   the explicit trade-off into view. **Recommend:** `test-author.md`/`patch-generator.md` should name specific
   defect classes (e.g. "a null/invalid value about to reach EAV or DB persistence") that warrant integration
   coverage regardless of file count, rather than leaving it to a generic size heuristic.
5. **The MFTF admin-user-grid cleanup flake** (now in memory) is real, reproducible, and will produce false
   `FAIL` noise on any future ticket whose MFTF coverage happens to touch admin-user create/delete — which is
   common. It's incidental to this ticket; worth triaging centrally rather than re-discovering per-ticket.
6. **Token accounting for the top-level conductor is not first-class.** I could give you exact figures for
   every subagent but nothing precise for my own turns — there's no tool exposing that to me, and the one
   counter I do see doesn't behave as a reliable cumulative tracker. If step-level cost accounting matters
   going forward, that's worth raising with the platform, or at minimum standardizing that every VI dispatch's
   `<usage>` block gets logged into the postmortem automatically (I did this manually here).
7. **[Superseded] No real monolithic-vs-swarm cost baseline exists yet.** This was true when written; it no
   longer is. A controlled A/B on ACP2E-5288 has since run and settled it: swarm ~44% cheaper, tied on
   correctness, and mono caused a destructive incident the swarm didn't (see the §5 update note and
   `docs/bdad-changelog.md` PART X). Leaving this item as a record of what was still open at the time this
   report was written.
8. **[Post-CR addendum] A second, identical vulnerable call site was missed at Phase 0 — root cause was
   framing, not tooling.** A CR comment on the open PR flagged that `unlockUseDefaultAttributes()` has the
   exact same missing `isScopeGlobal()` guard as `nullifyHiddenLockedAttributes()` — both are two call sites
   of the identical "unlock + null a locked attribute" idiom, present since the very commit (`5ff1485c84`)
   that introduced the vulnerability. The Dependency Tracer's Step 2 report had *already found and quoted*
   `unlockUseDefaultAttributes()` by name — but I'd framed the task as "verify the fix doesn't regress the
   `use_default=1` path" (a regression check) rather than "does this method have the identical missing
   guard?" (the actual defect class). Every downstream agent correctly scoped to what it was told, because
   what it was told described the proximate symptom, not the general pattern. A one-line
   `grep -n "unlockAttribute("` on the target file at Phase 0 time would have surfaced both call sites
   instantly, before any code was written. **Recommend:** make "grep the changed file(s) for other call
   sites of the exact anti-pattern being fixed" an explicit, standard Phase 0 step in
   `dependency-tracer.md` — not just tracing callers/dependents of the one method already named. This is a
   second, independent piece of evidence (alongside the `AdminGws` sibling-mechanism finding) that this
   defect family recurs via structurally repeated code, and that Phase 0's value is proportional to how
   broadly the "target symbol" is framed by whoever writes the dispatch prompt.
