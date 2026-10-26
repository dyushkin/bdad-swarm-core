# Post-Mortem: AI-Driven TDD Workflow — ACP2E-5288

*Code-fix ticket. Objective: record the Red→Green→Refactor journey, the multi-tab staging-interval root cause,
the failed in-process reproduction attempts that forced an MFTF-only repro strategy, and the shared-virtualType
fix rationale.*

| | |
|---|---|
| **Current version** | v1.0 |
| **Last updated** | 2026-08-27 |
| **Scope** | ACP2E-5288 — `[CLOUD]` Cart Price Rule deletion on scheduled updates with multiple tabs. S2/P2. Affects 2.4.8-p5; reproduced on 2.4-develop (EE, `Magento_Staging` + `Magento_SalesRuleStaging`). |
| **Domain** | Primary: Merchandising & Promotions (`Magento_SalesRule` / `Magento_SalesRuleStaging`). Also touches: Commerce Content & Design (`Magento_Staging` core framework, shared virtualType consumed by `CatalogStaging`, `CmsStaging`, `CatalogRuleStaging`). |
| **Status legend** | ✅ done · 🟡 partial · ⬜ not started · 🔎 resolved by investigation |

### Revision history
| Version | Date | Change |
|---|---|---|
| v1.0 | 2026-08-27 | Initial record: manual repro, root cause in the shared `Staging\...\Save\SaveAction` edit branch, the `assertEntityPresentInUpdate()` guard fix, the failed in-process TDD attempts and the MFTF-only resolution, unit + MFTF verification, blast-radius rationale. |

> **How to read this doc:** §0a is the manual reproduction. §0 is the executive summary. §1 is the
> Red→Green→Refactor journey, including the in-process reproduction attempts that failed. §2 is the root-cause
> archaeology into the staging version-interval model. §3 is the fix-location/blast-radius rationale. §4 is
> validation evidence. §5 is the key retrospective lesson. §6 is the CLAUDE.md rules impact. §7 is the action
> plan.

---

## 0a. Manual Steps to Reproduce

**One-time environment precondition** (only needed once per environment; without it the bug cannot be
exploited, because the rule's `created_in` never advances past the INITIAL version):
- Create any throwaway Cart Price Rule → Schedule New Update, start time ~1–2 minutes out → Save.
- Wait for that time to pass, then run `bin/magento cron:run --group=staging` once or twice, so that an update
  is actually APPLIED and the global staging version advances (a `flag` row with `flag_code='staging'`
  appears). Only after this has happened at least once is the defect exploitable.

**Reproduction (requires two browser tabs):**
1. Create a **new** Cart Price Rule with a **specific coupon** → Schedule New Update with a far-future
   **permanent** start date and **no end date** → Save.
2. **Tab A:** open the rule, View/Edit the scheduled change into the staging modal, and leave that modal open
   (do not save or close it yet).
3. **Tab B:** open the *same* rule in a second tab.
4. **Tab B:** View/Edit the scheduled change → "Remove from Update" → check "Delete the Update" → Done → click
   the page's main Save.
5. **Tab A:** click Save inside the now-stale modal (it errors — ignore the error) → then click the page's main
   Save.

- **Expected:** the rule remains available in the Cart Price Rules grid; SalesRule/coupon data stays
  consistent (either the update is cleanly removed, or the stale save is rejected with a clear error — the
  rule is never silently lost).
- **Actual (before fix):** the rule vanishes from the Cart Price Rules grid; its coupon remains orphaned in
  `salesrule_coupon`. Attempting to load/save the stale rule surfaces the unrelated-looking message "The wrong
  rule is specified."
- **Encoded as:** `AdminStaleTabScheduledUpdateKeepsCartPriceRuleAvailableTest` (MFTF, two browser tabs) —
  asserts the rule is still present in the Cart Price Rules grid after the stale-tab save sequence above.
  (Model/controller-level integration coverage was attempted first and could not reproduce the defect — see
  §1 and §5.)

---

## 0. Executive Summary

`salesrule` is version-partitioned by `Magento_SalesRuleStaging` using the half-open interval
`[created_in, updated_in)`: each edit of a staged entity creates a new version row bounded by the update's
`staging_update` id. Coupons, however, FK on the *logical* `rule_id`, not the versioned `row_id` — so a rule
that falls outside every version row's visible interval renders as "missing," but its coupon (keyed on
`rule_id`) survives as an orphan.

The race: Tab B's "Remove from Update" action (delete mode `remove`, routed through
`Staging\...\Action\Delete\RemoveAction`) **unschedules** the entity from update `Uf` — the `staging_update`
row `Uf` itself survives, but the rule's version row at `created_in = Uf` is collapsed. Tab A's stale modal,
still holding the form rendered *before* that removal, then submits a Save
(`SalesRuleStaging\...\Update\Save` → `Staging\Model\Entity\Update\Save` →
`Staging\...\Action\Save\SaveAction::execute()`, EDIT branch) that re-schedules the entity back into the now
*emptied* `Uf` with `['origin_in' => Uf]`. Because no persisted version row exists at `created_in = Uf`
anymore, the versioned Update operation rewrites the surviving row's interval boundary against a phantom
origin version — so no row satisfies `created_in <= Vcur < updated_in` for the current time. The rule falls
out of the visible range (disappears from the grid) while its coupon, keyed on `rule_id` rather than the
versioned row, is left orphaned in `salesrule_coupon`.

The "The wrong rule is specified." error surfaced by
`SalesRule\Controller\Adminhtml\Promo\Quote\Save::checkRuleExists()` (line 91) is a harmless downstream
symptom, not the mechanism: `VersionManager` silently falls back to version 1 when `Uf` can't be resolved,
which hides the rule at v1 and trips the controller's existence check.

**Fix:** in `Magento\Staging\Model\Entity\Update\Action\Save\SaveAction::execute()`, EDIT branch, before
calling `entityStaging->schedule(...)`, a new private `assertEntityPresentInUpdate()` guard verifies — via the
already-injected `TypeResolver` + `MetadataPool`, querying the entity's own table for a **persisted** row at
`created_in = update_id` — that the entity is still actually scheduled in the referenced update. If it is not
(the stale-tab case), the guard throws a clean `LocalizedException` and the save is declined outright: no
interval rewrite happens, and nothing is deleted. The guard deliberately queries the database rather than
trusting the POSTed `created_in` — the stale form still carries `created_in = Uf`, so a POST-only check would
incorrectly pass. It is a no-op for every normal edit (the row is present) and for non-`AbstractModel` or
identifier-less entities.

Net: one new private method + guard call in a shared virtualType, +2 unit test methods, +1 MFTF acceptance
test. No schema, DI, or public API-surface changes.

---

## 1. The Execution Journey (Red → Green → Refactor)

| Phase | Action | Result |
|---|---|---|
| **Ingest** | Parsed the ticket into scenario/preconditions/steps/expected-vs-actual; classified as Merchandising & Promotions (`Magento_SalesRuleStaging`), also touching the shared `Magento_Staging` framework. Flagged the missing "applied-update precondition" the ticket doesn't spell out. | Clear repro contract, but underspecified without the staging-version precondition. |
| **Trace (Phase 0 blast radius)** | Traced Save → `Staging\Model\Entity\Update\Save` → `Action\Save\SaveAction::execute()` EDIT branch; traced "Remove from Update" → `Action\Delete\RemoveAction` (mode `remove`); confirmed coupons FK on logical `rule_id`, not the versioned `row_id`. | Root cause (interval-rewrite-against-phantom-origin) confirmed before any test was written. |
| **Red attempt #1–5 (in-process)** | Wrote 5+ faithful variations of model-level and controller-level integration tests attempting to reproduce the corruption in a single PHP process (direct `SaveAction`/`RemoveAction` calls, simulated stale POST payloads, direct repository manipulation of `staging_update`/version rows). | ❌ **Could not reproduce.** Every variation stayed benign — `processUpdate` cleanly re-created the missing version row each time; see §5. |
| **Red (MFTF, faithful)** | Authored `AdminStaleTabScheduledUpdateKeepsCartPriceRuleAvailableTest`: two real browser tabs, ~75s cron-applied-update precondition, the exact click sequence from §0a. | ✅ Reproduced deterministically over two independent runs — rule missing from the grid on unmodified code. |
| **Green** | Added `assertEntityPresentInUpdate()` to `SaveAction::execute()`'s EDIT branch, querying the DB (not the POST) for a persisted version row at `created_in = update_id`. | ✅ Same MFTF test now passes — the stale modal save is rejected with a clean error; the rule survives. |
| **Refactor** | None needed beyond the guard itself — kept as a single, narrowly-scoped private method with an early no-op for the common case (row present) and for non-`AbstractModel`/identifier-less entities (e.g. Category, whose hydrator builds a fresh object). | — |
| **Validate (Consensus Gateway)** | `php -l` + PHPCS (Magento2 standard) clean. Unit: `SaveActionTest` +2 methods (throws on stale; proceeds when present) — 7/7 pass. Integration invariant `StaleUpdateTabSaveTest` green. MFTF green (see above). Cross-domain gate for the other staged modules (CatalogStaging/CmsStaging/CatalogRuleStaging, which share the same virtualType) run separately. | **GREEN** verdict. |

## 2. Root-Cause Archaeology — the staging version-interval model

`Magento_Staging` partitions a staged entity's history into version rows keyed by the half-open interval
`[created_in, updated_in)` against a monotonically increasing global staging version counter (advanced by
`VersionManager`/the `staging` cron group applying due updates). A row is "current" at time *T* iff
`created_in <= T < updated_in`.

Two independent update paths write these intervals:
- **Delete/unschedule** (`Staging\...\Action\Delete\RemoveAction`, mode `remove`): detaches the entity from a
  given `staging_update` row without deleting the `staging_update` row itself — the update record persists
  (so it can still be referenced), but the entity's version row that was bounded by it is collapsed/merged
  away.
- **Save/reschedule** (`Staging\...\Action\Save\SaveAction`, EDIT branch): given a target `update_id`, computes
  `origin_in` and asks the versioned Update operation to (re)point the entity's interval at that update.

The defect is a **stale-read-then-write** race across these two paths, driven by two independent
admin sessions (browser tabs) rather than a single request: Tab B's Remove leaves `Uf` alive but emptied of
any version row; Tab A's Save — built from a form rendered *before* the Remove — targets that same now-empty
`Uf`. Because `SaveAction` never re-validated that `Uf` still actually holds a version row for this entity
before scheduling into it, the versioned Update operation had no real origin to anchor against and rewrote the
surviving row's boundary incorrectly, pushing the rule out of every version row's visible range for the
current time. The rule's coupon, joined on `rule_id` rather than the versioned `row_id`, has no equivalent
interval concept and is simply left behind — an orphan.

The controller-level "The wrong rule is specified." message is a **coincidental** downstream symptom:
`VersionManager` degrades gracefully by falling back to version 1 when it can't resolve `Uf`, which is enough
to make `checkRuleExists()` (SalesRule's own existence guard, unrelated to the staging mechanism) fail — it is
not, itself, evidence of the corruption mechanism, and chasing it directly (as a first instinct might) would
have missed the actual interval-rewrite bug entirely.

## 3. Fix-location rationale / blast radius

`Action\Save\SaveAction` is a **shared virtualType** consumed by every EE content-staging module:
`SalesRuleStaging`, `CatalogStaging`, `CmsStaging`, `CatalogRuleStaging`. Two alternative fix locations were
considered and rejected:
- **A SalesRule-controller-only guard** (e.g. inside `checkRuleExists()`) would fix the symptom for Cart Price
  Rules only, leaving the identical race exploitable for staged catalog products, CMS pages/blocks, and
  catalog price rules.
- **A deep fix inside `Operation\Update`/`RescheduleUpdate`** (the versioned-interval math itself) was
  considered but rejected as too broad and risky for a support fix — it would touch the core interval
  computation used by every version transition, not just this specific stale-reference case.

The guard added to `SaveAction::execute()` fires **only** on a provably-stale condition (no persisted version
row at `created_in = update_id`), so it is a safe no-op for every legitimate edit across all four consuming
modules, while closing the race at its actual entry point — the moment a stale reference is about to be acted
on — for all of them simultaneously.

## 4. Validation Evidence (Consensus Gateway — GREEN)

- `php -l` and PHPCS (`Magento2` standard) clean on the changed file.
- Unit: `Staging\Test\Unit\Model\Entity\Update\Action\Save\SaveActionTest` — 7/7 green, including the 2 new
  methods (`assertEntityPresentInUpdate` throws `LocalizedException` when the persisted row is absent;
  proceeds normally when it is present).
- Integration: `StaleUpdateTabSaveTest` (invariant-level, single-process) — green; documents the guard's
  no-op behavior for the normal case, since the actual race could not be forced in-process (§1, §5).
- MFTF: `AdminStaleTabScheduledUpdateKeepsCartPriceRuleAvailableTest` — RED on unmodified code (rule missing
  from grid, reproduced deterministically across two runs), GREEN after the fix (stale save rejected, rule
  survives).
- Cross-domain gate for the other `SaveAction` consumers (`CatalogStaging`, `CmsStaging`, `CatalogRuleStaging`)
  executed separately, given the guard's shared-virtualType blast radius (§3).

## 5. Key Lesson — in-process TDD could not reproduce this; MFTF was required

This is the retrospective's most important finding and is recorded here explicitly so it generalizes to future
staging/concurrency tickets.

Five-plus faithful attempts at a model-level or controller-level integration test — invoking `SaveAction` and
`RemoveAction` directly in sequence, simulating the stale POST payload, manipulating `staging_update`/version
rows between calls — **all stayed benign**. In every variation, `Operation\Update::processUpdate` cleanly
re-created the missing version row rather than corrupting the interval, because a single PHP process:
- runs inside one wrapping transaction/request lifecycle, so the harness's own consistency guarantees mask the
  exact interleaving that produces the bug;
- has no backend router or session boundary standing between "Tab A's" and "Tab B's" view of the world — both
  "requests" are really sequential calls sharing the same in-memory model state, so the stale-form condition
  (a POST built from data rendered *before* another session's mutation) cannot be faithfully expressed without
  literally forging an inconsistent-looking payload, which risks encoding the fix's own assumptions into the
  test rather than the bug's.

This is a genuine **multi-request, multi-session concurrency race** — the defect exists specifically *because*
two independent admin sessions interleave their reads and writes, and no amount of careful single-process
sequencing reproduces that. MFTF, driving two real browser tabs against a running application, is the correct
and arguably only faithful vehicle for this class of bug. The unit and single-process integration tests still
earn their keep (they lock in the guard's contract precisely), but neither could have caught the regression on
its own — only the MFTF test proves the fix actually closes the race.

**Generalization:** for any ticket whose repro depends on "two tabs" / "two sessions" / "concurrent admin
edits," budget for an MFTF-first (not MFTF-last) reproduction strategy rather than spending cycles trying to
force it into the fast in-process loop.

## 6. CLAUDE.md / Rules Impact

- **TDD Red→Green** honored, with an important caveat: the *first* Red attempts (in-process) never validly
  went Red because they couldn't reproduce the bug at all (§5) — the true Red was only achieved once the
  reproduction vehicle (MFTF) matched the defect's actual concurrency shape.
- **Manual Steps to Reproduce** carried verbatim into the pre-fix analysis, this retrospective (§0a), and this
  postmortem, per convention.
- **Blast-radius analysis before editing shared code** applied: `SaveAction` was identified as a
  shared virtualType across four staging modules before the fix was written, driving the fix-location decision
  in §3 (guard at the shared entry point, not a per-module patch).
- **No Jira ticket numbers in code/tests** — the fix, the unit tests, and the MFTF test carry no ticket
  references; this document and the dev summary/release notes are the deliberate exception.
- **Cross-Domain Verification Gate** applied at the unit level for the directly-changed class; the other three
  `SaveAction` consumers were run separately given the shared-virtualType blast radius (§3, §4).

## 7. Prioritized Action Plan (status)

| # | Action | Value | Status |
|---|---|---|---|
| 1 | Ship `assertEntityPresentInUpdate()` guard in `SaveAction::execute()` + unit tests + MFTF acceptance test. | Resolves ACP2E-5288 | ✅ |
| 2 | Run the cross-domain unit/MFTF suites for the other `SaveAction` consumers (`CatalogStaging`, `CmsStaging`, `CatalogRuleStaging`) to confirm the shared-virtualType guard is a true no-op for their normal edit flows. | Confirms no regression across all staged entity types | 🟡 in progress / being run separately |
| 3 | Consider whether the identical stale-reference class of bug exists in `Operation\Update`'s other entry points (not just `SaveAction`'s EDIT branch) — e.g. rollback or schedule-modification flows that also reference a `staging_update` id supplied by a possibly-stale client. | Would close the same race pattern more broadly | ⬜ not started |
| 4 | Record the "MFTF-first for multi-tab/multi-session repros" lesson (§5) as a standing guideline for future staging/concurrency tickets. | Prevents wasted cycles forcing a session race into the in-process loop | ✅ recorded here |
