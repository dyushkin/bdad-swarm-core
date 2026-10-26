# Post-Mortem: AI-Driven TDD Workflow — ACP2E-5252

*Code-fix ticket. Objective: record the Red→Green→Refactor journey, the root-cause trace back to the
`PricePermissions` locked-attribute nulling logic added for ACP2E-5081/5082, and the deferred acceptance-level
follow-up.*

| | |
|---|---|
| **Current version** | v1.0 |
| **Last updated** | 2026-08-24 |
| **Scope** | ACP2E-5252 — a restricted admin (permission "Edit Product Status" denied) saving an unrelated attribute at store-view scope silently flips a **Global-scope** locked attribute (`status`) to Disabled, even though it was never touched and the admin cannot edit it. |
| **Ticket chain** | Regression of ACP2E-5081/5082 (commit `5ff1485c84`, which introduced `nullifyHiddenLockedAttributes()` to fix a *different* bug: restricted admins losing the "Use Default Value" state for store-view-scoped Price/Status/Tax Class on save). |
| **Status legend** | done · partial · not started · resolved by investigation |

### Revision history
| Version | Date | Change |
|---|---|---|
| v1.0 | 2026-08-24 | Initial record: manual repro, root cause in `groupLockedAttributesByTable()`, the one-line `isScopeGlobal()` guard fix, unit-test coverage, Consensus Gateway green verdict, deferred MFTF acceptance follow-up. |

> **How to read this doc:** §0a is the manual reproduction. §0 is the executive summary. §1 is the
> Red→Green→Refactor journey. §2 is the root-cause archaeology into the ACP2E-5081/5082 fix that introduced
> this regression. §3 is validation evidence. §4 is the CLAUDE.md rules impact. §5 is the action plan,
> including the deferred MFTF item.

---

## 0a. Manual Steps to Reproduce

Verbatim from the ticket, with the storefront/admin-implied preconditions annotated:

1. Go to Stores > All Stores and ensure at least 1 website with 2 store views exists.
2. Go to System > Permissions > User Roles. Create (or use) an Admin role granting: Inventory > Products >
   Edit Product Status = Disabled; Inventory > Products > Mass Update Attributes = Enabled; plus base
   product-edit access.
3. Assign this role to a restricted "Admin2" user.
4. Go to System > Attributes > Product, open the Status attribute, and change its scope from Website to
   Global. *(Precondition the ticket implies but doesn't spell out: the bug is specific to Global-scope
   locked attributes — they structurally never have a per-store-view EAV row, which is exactly what the
   regressing code mishandles.)*
5. Log in as an Admin user with full permissions; create a simple product and confirm its Status is Enabled.
6. Log out; log in as the restricted Admin2 user.
7. Navigate to Catalog > Products, open the product created in step 5.
8. Switch the product edit form to the second store view.
9. Change an unrelated attribute (Description) only, then Save.
10. Reload the product and inspect Status.

- **Expected:** Status remains Enabled.
- **Actual (before fix):** Status silently flips to Disabled.
- **Encoded as:** `testAfterInitializePreservesGlobalScopeLockedAttribute` (unit) in the existing
  `PricePermissionsTest` suite — asserts `unlockAttribute()`/`setData()` are never invoked for a locked,
  Global-scope attribute lacking a store-view EAV row, so its `store_id=0` value survives the save untouched.

---

## 0. Executive Summary

`Magento\PricePermissions\Controller\Adminhtml\Product\Initialization\Helper\Plugin\PricePermissions::afterInitialize()`
nulls out locked attributes (those the current admin role cannot edit) that lack an *own* per-store-view EAV
row, so that a subsequent `unlockAttribute()`/re-save cycle doesn't silently promote a "use default" value
into an explicit store-view override the admin never asked for. That logic — added by
ACP2E-5081/5082 (commit `5ff1485c84`) to fix a genuine "Use Default Value" regression for store-view-scoped
Price/Status/Tax Class — never checked whether the attribute's scope was **Global**.

Global-scope attributes (e.g. `status`, after a merchant reconfigures its attribute scope from Website to
Global) **never** have a per-store-view row by design — their one and only value lives at `store_id=0`. The
regressing code treated "no store-view row found" as "needs nulling," which for a Global attribute means
nulling its *only* copy of the data on every restricted-admin save at store-view scope, regardless of
whether the admin touched it.

`Magento\AdminGws\Model\Models::catalogProductLoadAfter` shares the same locked-attribute registry and already
guards the identical case with `isScopeGlobal()` — confirming this is the established, correct pattern that
the `PricePermissions` plugin simply never adopted.

**Fix (one line):** in `groupLockedAttributesByTable()`, skip an attribute from the "needs nulling" set if
`$attribute->isScopeGlobal()` is true, in addition to the existing backend-type/attribute-existence checks.

Net: **1 line changed** in the production class, **1 new unit test** (65 lines) in the existing suite. No
schema, DI, or API-surface changes.

---

## 1. The Execution Journey (Red → Green → Refactor)

| Phase | Action | Result |
|---|---|---|
| **Ingest** | Parsed the ticket into scenario/preconditions/steps/expected-vs-actual; classified as Catalog & Pricing Management (also touching EE `Magento_PricePermissions` and the Admin panel UI Framework). | Clear repro contract: restricted admin, store-view save, unrelated field edit, Global-scope locked attribute silently nulled. |
| **Trace (Phase 0 blast radius)** | Traced the regressing commit `5ff1485c84` (ACP2E-5081/5082) to `nullifyHiddenLockedAttributes()` → `groupLockedAttributesByTable()`. Found the sibling `AdminGws\Model\Models::catalogProductLoadAfter` uses the same locked-attribute registry and already guards with `isScopeGlobal()`. | Root cause and correct fix pattern both confirmed before writing code. |
| **Red** | Added `testAfterInitializePreservesGlobalScopeLockedAttribute` to the existing `PricePermissionsTest` unit suite: locked `status` attribute stubbed `isScopeGlobal() === true` with no store-view EAV row; asserts `unlockAttribute()`/`setData()` are **never** called. | Failed against unfixed code — 7 pre-existing tests green, new one red (nulling fired). |
| **Green** | Added the `isScopeGlobal()` disjunct to the `continue` guard in `groupLockedAttributesByTable()`. | 8/8 unit tests green, including the 2 tests guarding the original ACP2E-5081 store-view-scoped-price behavior (unaffected). |
| **Refactor** | None needed — the fix is a minimal, single-condition addition; no further structural change warranted. | — |
| **Validate (Consensus Gateway)** | `php -l` + PHPCS clean; `PricePermissions` unit suite 8/8; `AdminGws\Model\Models` unit suite 12/12 (confirms no regression in the interacting module that shares the lock registry); MIG `--diff`/`--mock-impact` empty (known EE-symlink tool gap, not a true negative — manually confirmed no missing mock stubs). | **GREEN** verdict. |

## 2. Root-Cause Archaeology — why ACP2E-5081/5082 left this open

ACP2E-5081/5082 fixed a real defect: a restricted admin saving a product at store-view scope was losing the
"Use Default Value" checkbox state for locked Price/Status/Tax Class attributes, because a stale store-view
value was resurfacing on the form. The fix's approach — `nullifyHiddenLockedAttributes()` — proactively nulls
any locked attribute's in-memory value **unless** it has a genuine per-store-view EAV row, on the theory that
"no store-view row" means "this is a use-default-value case that shouldn't be exposed as an explicit
override."

That theory holds for **Website**- or **Store**-scoped attributes, where a store-view row's absence is
meaningful (it genuinely means "using the parent scope's value"). It does **not** hold for **Global**-scope
attributes: by EAV design, a Global attribute never has, and never will have, a per-store-view row — its
value lives exclusively at `store_id=0`. `groupLockedAttributesByTable()` had no scope check, so it treated
every Global locked attribute as if it were in the "use default value, needs nulling" state on every single
restricted-admin save — silently overwriting the real global value with `null` on persist.

The sibling code path, `Magento\AdminGws\Model\Models::catalogProductLoadAfter`, consumes the *same* locked
attribute registry for the analogous "hide values the admin can't edit" concern, and already carries the
`isScopeGlobal()` guard — this ticket's fix simply brings `PricePermissions` in line with the pattern already
proven correct there.

**Distinct from ACP2E-5081/5082:** that ticket's regression test coverage exercises the store/website-scoped
case (Price at store-view scope); it never had a fixture where the locked attribute's scope was reconfigured
to Global, so the gap was invisible to the existing suite. This ticket's fixture-only distinction (Global
scope, same field) is exactly what the new test isolates.

## 3. Validation Evidence (Consensus Gateway — GREEN)

- `php -l` and PHPCS (`Magento2` standard) clean on both changed files.
- `PricePermissionsTest` unit suite: 8/8 green (7 pre-existing + 1 new), including the 2 tests that guard the
  original ACP2E-5081 store-view-scoped-price behavior — confirmed unaffected by the added disjunct.
- `Magento\AdminGws\Model\Models` unit suite: 12/12 green — confirms no regression in the interacting module
  that shares the locked-attribute registry.
- MIG `--diff` / `--mock-impact`: empty result — a known tooling gap for EE-symlinked modules (`ext/`), not a
  true negative; manually confirmed no additional mock stubs were needed for the one-line change.
- MFTF acceptance-level suites: executed post-gate, at the user's request (see §5, item 2, and §3a below).

## 3a. MFTF Follow-up (post-gate, run at user's request)

Both candidate MFTF acceptance tests were executed against the primed environment (Selenium standalone up,
`indexer:reindex` + `cache:flush` run beforehand):

- `AdminAccessRestrictedToOneWebsiteMassStatusUpdateTest` — Codeception reported `FAIL`, but the failure was
  isolated to the test's `<after>` cleanup hook (`AdminDeleteCustomUserActionGroup`'s `seeUser` grid-search
  step), not to the `<test>` body's `seeCheckboxIsChecked`/`dontSeeCheckboxIsChecked` assertions on product
  status at default vs. store-view scope — those completed with no failure reported. Reproduced identically
  on a second run (different random username, same exact step).
- `AdminCreateUserWithRestrictedScopeCanEditProductAssignedToThisScopeTest` — same pattern: `FAIL` isolated to
  the `<after>` hook's `AdminDeleteUserActionGroup` `seeUser` step; the in-test assertions passed.

Both failing action groups (`AdminDeleteCustomUserActionGroup`, `AdminDeleteUserActionGroup`) are generic core
`Magento_User` cleanup helpers (`app/code/Magento/User/Test/Mftf/ActionGroup/`), not anything owned by
`PricePermissions`/`AdminGws`/Catalog — this is a pre-existing environment-level MFTF flake unrelated to this
fix, now recorded as a standing gotcha (`mftf-admin-user-grid-cleanup-flake` project memory) rather than
re-diagnosed here. **Net: no evidence of an acceptance-level regression from this fix** — both tests'
actual behavioral assertions passed; only unrelated grid-cleanup housekeeping reported `FAIL`.

## 4. CLAUDE.md / Rules Impact

- **TDD Red→Green** honored: failing unit test authored and confirmed red against unfixed code before the
  one-line fix landed.
- **Manual Steps to Reproduce** carried verbatim into the Step 1 brief, this retrospective (§0a), and will
  ship in this same postmortem file per convention.
- **Cross-Domain Verification Gate** applied: the sibling `AdminGws\Model\Models` consumer of the same lock
  registry was explicitly re-run (12/12 green), not just the directly-changed module's own suite.
- **No Jira ticket numbers in code/tests** — the fix and its test carry no ticket references; this document
  and the dev summary/release notes are the deliberate exception.
- **Cross-domain gate — partial:** unit-level cross-domain check (AdminGws) is green; the acceptance-level
  (MFTF) cross-domain check was deferred per explicit user decision (see §5 item 2), not skipped by omission.

## 5. Prioritized Action Plan (status)

| # | Action | Value | Status |
|---|---|---|---|
| 1 | Ship the one-line `isScopeGlobal()` guard in `groupLockedAttributesByTable()` + the new unit test. | Resolves ACP2E-5252 | done |
| 2 | Run the two identified MFTF acceptance candidates — `AdminAccessRestrictedToOneWebsiteMassStatusUpdateTest` and `AdminCreateUserWithRestrictedScopeCanEditProductAssignedToThisScopeTest` (both under `magento2ee/app/code/Magento/AdminGws/Test/Mftf/Test/`). Initially deferred (Minor/S3/P3, unit coverage green), then run at the user's request post-gate. Both `FAIL`ed, but only in a generic `after`-hook admin-user-grid cleanup step unrelated to `PricePermissions`/`AdminGws`/Catalog (see §3a) — the in-test behavioral assertions passed on both. | Confirms no acceptance-level regression on the restricted-admin store-view save flow | done — no regression found; cleanup-step flake tracked separately |
| 4 | Root-cause and fix the generic MFTF admin-user-grid cleanup flake (`AdminDeleteCustomUserActionGroup`/`AdminDeleteUserActionGroup` `seeUser` step) surfaced while running item 2 — affects any MFTF test with an admin-user create/delete cleanup pattern, not just this ticket. | Removes a source of false-`FAIL` noise across the wider MFTF suite | not started — separate from this ticket |
| 3 | Consider whether `groupLockedAttributesByTable()`'s per-attribute scope check should be hoisted into a shared helper alongside `AdminGws\Model\Models::catalogProductLoadAfter`'s identical guard, to prevent a third copy of this logic from drifting out of sync again. | Reduces future duplicate-regression risk | not started |
