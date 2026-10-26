# Post-mortem — ACP2E-5288

**[CLOUD] Cart price rule deletion on scheduled updates with multiple tabs**
Type: Customer Request / Bug — Severity S2 — Priority P2 — Affects 2.4.8-p5 (reproduced on 2.4-develop).
Domain: **EE Staging** (`Magento_Staging` / `Magento_SalesRuleStaging`). Not reproducible on CE.

---

## 1. Summary

With the same Cart Price Rule open in two Admin browser tabs, removing its scheduled update in one tab and
then saving the *stale* scheduled-update modal in the other tab makes the rule become **unavailable** (it drops
out of the Cart Price Rules grid and can no longer be loaded, with the subsequent save reporting
`The wrong rule is specified.`), while its coupon **remains** orphaned in `salesrule_coupon`.

## 2. Manual Steps to Reproduce

**Preconditions (one-time per environment):** a scheduled update must have already *taken effect* on the install,
which advances the global staging version and creates the `flag WHERE flag_code='staging'` row. Without this the
rule's `created_in` stays at the initial version and the defect is not reachable.
1. Create any Cart Price Rule → *Schedule New Update* with a start time 1–2 min out → Save.
2. Wait for that time to pass, then run `bin/magento cron:run --group=staging` once or twice.
3. Confirm `SELECT * FROM flag WHERE flag_code='staging';` returns a row.

**Trigger:**
1. Create a **new** Cart Price Rule *with a specific coupon*; *Schedule New Update* with a **future start date and
   no end date** (a *permanent* update — a temporary start+end update does **not** trigger the defect) → Save.
2. Open the rule's edit page in **Tab A**; click *View/Edit* on the scheduled change to load it into the modal —
   leave it open.
3. Open the same rule in **Tab B**.
4. In Tab B: *View/Edit* the scheduled change → *Remove from Update* → *Delete the Update* → *Done* → click the
   page's main **Save**.
5. Switch back to Tab A (its modal still shows the now-removed schedule).
6. In Tab A: click **Save** inside the modal (ignore the error it shows) → then click the page's main **Save**.

**Actual:** the rule is gone from the grid; the main save reports `The wrong rule is specified.`; the coupon
persists in `salesrule_coupon`.
**Expected:** the stale save is rejected cleanly; the rule stays available and SalesRule/coupon data stays
consistent.

## 3. Root cause

Scheduling a permanent future update `U` produces a 2-row version chain: base `[created_in=1, updated_in=U]` and
future `[created_in=U, updated_in=MAX]`. Tab B's *Remove from Update* unschedules the entity from `U` — it deletes
the future row and restores the base to `[1, MAX]`; the `staging_update` row for `U` remains but no longer holds a
version of this rule.

Tab A's stale modal save posts to `salesrulestaging/update/save`, routed to
`Magento\Staging\Model\Entity\Update\Action\Save\SaveAction::execute()` (edit branch, `update_id = U`). The stale
form still carries `created_in = U` in its POST, so the hydrated entity's `created_in` looks valid even though no
persisted version row exists at `created_in = U` anymore. `Operation\Update::processUpdate()` then treats the save
as a re-schedule (`needsReschedule() == true`) and runs a degenerate `RescheduleUpdate(U → U)` against a version
that no longer exists. This re-points the base row's `updated_in` to the **current** (already-applied) version and
leaves a **gap** in the version chain around the current version, so no row is valid "now" → the rule is
unavailable. The coupon (keyed by `rule_id`) is never touched, so it is left orphaned; and because the rule can no
longer be loaded, the ordinary API/CE cleanup path cannot delete it either.

The debug capture that confirmed it (Tab A stale save):
`update_id='…820' postCreatedIn='…820' | dbRows=[{created_in:1, updated_in:MAX}]` — i.e. the POST's `created_in`
matched the update, but the only persisted row was the base version; the scheduled version was already gone.

## 4. The fix

`SaveAction::execute()` edit branch now verifies, **against the persisted version rows** (not the untrusted POST),
that the entity still has a version scheduled in the update being edited. If not (concurrently removed), it throws
a clean `LocalizedException("This scheduled update no longer exists. Reload the page and try again.")` **before**
any re-schedule, so nothing is mutated.

- File: `app/code/Magento/Staging/Model/Entity/Update/Action/Save/SaveAction.php`
  (EE: `magento2ee/app/code/Magento/Staging/...`).
- New private `isEntityScheduledInUpdate()` uses the already-injected `TypeResolver` + `MetadataPool` to look up
  the entity metadata and queries `<entity table> WHERE <identifier> = :id AND created_in = :updateId`
  (with `disable_staging_preview`). Returns `true` (proceed) when the row exists, `false` (reject) when it does not.
- The lookup is skipped for non-`AbstractModel` hydrator returns and for entities without an identifier, which
  keeps valid Category staging saves (their hydrator builds a fresh object) from false-positiving.

Blast radius: the guard is on the generic Staging route shared by Product, Category, CMS Page/Block, Sales Rule and
Catalog Rule. Because it only rejects when the persisted scheduled version is genuinely absent, valid edits — for
every entity type, including the "change the update's start date" flow — are unaffected.

## 5. Tests (Red → Green)

- **MFTF (faithful reproduction):**
  `app/code/Magento/SalesRuleStaging/Test/Mftf/Test/AdminStaleTabScheduledUpdateKeepsCartPriceRuleAvailableTest.xml`
  — establishes the applied-update precondition (schedule + `cron:run --group=staging`), creates a coupon rule,
  schedules a permanent future update, drives the two-tab remove-then-stale-save sequence, and asserts the rule is
  still present in the grid. **Red** before the fix (rule missing) → **Green** after.
- **Unit (deterministic guard logic):**
  `Magento/Staging/Test/Unit/.../Save/SaveActionTest.php` — `testExecuteEditUpdateRejectsEntityNotScheduledInUpdate`
  (no persisted version row → throws, never reschedules) and `testExecuteEditUpdateProceedsWhenEntityScheduledInUpdate`
  (row present → reschedules). All 7 methods pass.

## 6. Validation performed

- `php -l` + `phpcs --standard=Magento2` clean on both changed files.
- Unit: `SaveActionTest` 7/7 pass.
- Cross-domain integration (staging): SalesRuleStaging + `CatalogStaging\ProductStagingTest` +
  `Staging\...\EntitySynchronizerTest` — 12/12 pass.
- Static: PHP Coding Standard Verification (LiveCodeTest: PHPCS/PHPMD/PHPStan) pass on the changed files.
- MFTF: `AdminStaleTabScheduledUpdateKeepsCartPriceRuleAvailableTest` green.

## 7. Retrospective notes

- **Reproduction was the hard part.** Three conditions were each individually easy to miss and each necessary:
  (a) the applied-update precondition (advanced staging version), (b) a *permanent* (not temporary) scheduled
  update, (c) a specific coupon. Pure-CE and single-request integration attempts could not reproduce it — the bug
  is inherently multi-request/two-tab, which the integration harness's single wrapping transaction masks. MFTF was
  the right (and only faithful) tool.
- **First fix attempt was wrong for an instructive reason:** guarding on the entity's `created_in` failed because
  that value comes from the *stale POST*, which legitimately still names the removed version. The reliable signal
  is the *persisted* version rows, so the corrected guard queries the DB.
- **Manual-cleanup hazard:** deleting `staging_update` rows without resetting the `flag.staging` `current_version`
  left the version manager pointing at a missing update and 404'd the whole instance. Recovery:
  `UPDATE flag SET flag_data='{"current_version":"1"}' WHERE flag_code='staging'` + `cache:flush`. When cleaning
  staging test data, reset the flag alongside (or leave the referenced update row intact).
