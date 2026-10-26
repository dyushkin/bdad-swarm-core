# Post-mortem — ACP2E-5288

**[CLOUD] Cart price rule deletion on scheduled updates with multiple tabs**
Type: Customer Request / Bug — Severity S2 — Priority P2 — Affects 2.4.8-p5 (reproduced on 2.4-develop).
Domain: **EE Staging** (`Magento_Staging` / `Magento_SalesRuleStaging`). Not reproducible on CE.

> Per the BDAD split, this per-ticket postmortem holds the ticket's own code/test/build changes. The
> process/agent rules that this ticket happened to trigger (the mandatory i18n-coverage rule and the MFTF
> generation-check gate) live in `docs/bdad-changelog.md` (PART XII), not here. The monolithic-vs-swarm
> experiment artifacts for this ticket are archived under `docs/experiments/acp2e-5288-mono-vs-swarm/`.

---

## 1. Summary

With the same Cart Price Rule open in two Admin browser tabs, removing its scheduled update in one tab and then
saving the *stale* scheduled-update modal in the other tab makes the rule become **unavailable** (it drops out
of the Cart Price Rules grid; the subsequent save reports `The wrong rule is specified.`), while its coupon is
left **orphaned** in `salesrule_coupon`.

## 2. Manual Steps to Reproduce

**Preconditions (one-time per environment):** a scheduled update must have already *taken effect*, which
advances the global staging version and creates the `flag WHERE flag_code='staging'` row. (Schedule a +1–2 min
update on any rule → wait → `bin/magento cron:run --group=staging` → confirm the flag row.) Without this the
rule's `created_in` stays at the initial version and the defect is unreachable.

**Trigger:** create a Cart Price Rule **with a specific coupon**; *Schedule New Update* with a **future start
date and no end date** (permanent — a temporary start+end update does NOT trigger it) → Save. Open the rule in
Tab A, *View/Edit* the scheduled change (leave the modal open). Open the same rule in Tab B; *View/Edit* →
*Remove from Update* → *Delete the Update* → *Done* → main **Save**. Back in Tab A: **Save** the (now stale)
modal → main **Save**.

**Actual:** the rule disappears from the grid; the main save says `The wrong rule is specified.`; the coupon
persists in `salesrule_coupon`. **Expected:** the stale save is rejected cleanly; the rule stays available and
SalesRule/coupon data stays consistent.

## 3. Root cause

Scheduling a permanent future update `U` produces a 2-row version chain: base `[created_in=1, updated_in=U]` and
future `[created_in=U, updated_in=MAX]`. Tab B's *Remove from Update* deletes the future row and restores the
base to `[1, MAX]`; the `staging_update` row for `U` survives but no longer holds a version of this rule.

Tab A's stale modal save posts to `salesrulestaging/update/save` →
`Magento\Staging\Model\Entity\Update\Action\Save\SaveAction::execute()` (edit branch, `update_id = U`). The
stale form still carries `created_in = U`, so the hydrated entity's `created_in` *looks* valid even though no
persisted version row exists at `created_in = U` anymore. The versioned Update operation then re-schedules the
entity into the now-empty `U`, rewriting the surviving row's interval against a phantom origin and gapping the
rule out of the visible version range → the rule is unavailable. The coupon (keyed on logical `rule_id`, not the
versioned `row_id`) is never touched, so it is left orphaned. `The wrong rule is specified.` is a downstream
symptom (`VersionManager` falls back to version 1, tripping SalesRule's `checkRuleExists()`), not the mechanism.

## 4. The fix

`SaveAction::execute()` (edit branch) now verifies, **against the persisted version rows** (not the untrusted
POST `created_in`), that the entity still has a version scheduled in the update being edited. If not
(concurrently removed), it throws a clean `LocalizedException` **before** any re-schedule, so nothing is
mutated.

- File: `magento2ee/app/code/Magento/Staging/Model/Entity/Update/Action/Save/SaveAction.php`.
- New private `assertEntityPresentInUpdate()` uses the already-injected `TypeResolver` + `MetadataPool` to query
  `<entity table> WHERE <identifier> = :id AND created_in = :updateId` (with `disable_staging_preview`). No-op
  for non-`AbstractModel` hydrator returns and identifier-less entities (keeps Category staging saves valid).
- Blast radius: the guard is on the generic Staging route shared by Product, Category, CMS Page/Block, Sales
  Rule and Catalog Rule; it only rejects when the persisted scheduled version is genuinely absent, so valid
  edits for every entity type are unaffected.
- i18n: the new `LocalizedException` phrase was added to `magento2ee/app/code/Magento/Staging/i18n/en_US.csv`
  (owning module), per the mandatory i18n-coverage rule.

## 5. Tests

- **Unit (deterministic guard logic):**
  `magento2ee/.../Staging/Test/Unit/.../Save/SaveActionTest.php` — `testExecuteEditRejectsStaleSaveWhenEntityRemovedFromUpdate`
  (no persisted row → throws, never reschedules) and `testExecuteEditProceedsWhenEntityStillPresentInUpdate`
  (row present → reschedules). 7/7 green.
- **MFTF (faithful two-tab reproduction):**
  `magento2ee/app/code/Magento/SalesRuleStaging/Test/Mftf/Test/AdminStaleTabScheduledUpdateKeepsCartPriceRuleAvailableTest.xml`
  — establishes the applied-update precondition, creates a coupon rule, schedules a permanent future update,
  drives the two-tab remove-then-stale-save sequence, and asserts the rule is still present in the grid.

## 6. Test/build fixes made while landing the ticket

- **Unused-variable cleanup:** dropped an unused `$entity` assignment in
  `SaveActionTest::testExecuteEditRejectsStaleSaveWhenEntityRemovedFromUpdate` (the reject case never references
  the returned mock; the helper wires it internally).
- **MFTF generation break (EE build):** the initial MFTF action group referenced three Section selectors
  (`customerGroupsToggle`/`SelectAll`/`DoneButton`) that were undefined in the CE/EE section, so `mftf
  generate:tests` failed with `Could not resolve entity reference`. Rebuilt against the real section elements.
- **MFTF runtime break (B2B build):** B2B (SharedCatalog) overrides
  `AdminCartPriceRulesFormSection.customerGroups` from a plain `<select>` into a JS multiselect widget
  (`div.admin__action-multiselect-text`). The bespoke customer-groups action group (EE base + B2B `<remove>`
  merge) hit the known non-deterministic cross-module `<remove>` merge: when the remove didn't fire, the plain
  `selectOption` ran against the widget `div` → `Strict locator ... malformed: 0 => NOT LOGGED IN`. **Fix:**
  deleted the bespoke EE base action group and its B2B merge; the test now creates rules via the **core
  `AdminCreateCartPriceRuleActionGroup`** (which carries the platform-maintained B2B merge for the widget), and
  assigns the Specific Coupon via edition-safe inline steps (reopen → coupon type + code → save); the
  precondition rule dropped its unnecessary coupon. Files: deleted
  `magento2ee/.../SalesRuleStaging/Test/Mftf/ActionGroup/AdminCreateCartPriceRuleWithCouponUsingGroupsWidgetActionGroup.xml`
  and `magento2b2b/.../B2b/Test/Mftf/ActionGroup/AdminCreateCartPriceRuleWithCouponUsingGroupsWidgetActionGroup.xml`;
  edited the test XML. **Validation:** regenerated 3× in a B2B-linked env — all clean, zero
  `selectOption(...multiselect-text...)` patterns, customer groups rendered as core's deterministic widget
  sequence (`expand → select-all → done`). Full B2B *runtime* confirmation still requires the B2B CI build.
  (Generalizable lesson — prefer core/platform action groups over hand-rolled base+B2B-merge pairs for
  select→widget fields — recorded in memory; the generation-check gate proves refs *resolve*, not that an
  edition merge *fires at runtime*.)

## 7. Validation performed

- `php -l` + `phpcs --standard=Magento2` clean on both changed PHP files.
- Unit: `SaveActionTest` 7/7 pass.
- MFTF: `AdminStaleTabScheduledUpdateKeepsCartPriceRuleAvailableTest` generates cleanly across EE and B2B
  (3× regeneration on B2B).
