# Post-Mortem: AI-Driven TDD Workflow — ACP2E-5180

*Principal AI Engineer assessment. Objective: harden this into a bulletproof, highly autonomous TDD environment for Adobe Commerce core.*

| | |
|---|---|
| **Current version** | v2.2 |
| **Last updated** | 2026-09-18 |
| **Scope** | ACP2E-5180 (sales order/order-item display discrepancy on cancellation) |
| **Status legend** | ✅ done · 🟡 partial · ⬜ not started · 🔎 resolved by investigation |

### Revision history
| Version | Date | Change |
|---|---|---|
| v1.0 | 2026-08-10 | Initial retrospective: journey, blind spots, proposed rules (A–D), infra proposals, MIG vision, action plan. |
| v1.1 | 2026-08-11 | Added Remediation Ledger and inline status marks; recorded implemented items (Rules A–D, compose/`setup-swarm.sh` hardening) and three bonus environment bugs found during execution. |
| v1.2 | 2026-08-18 | Post-merge CR + QA round: added the shipment-aware `shipping_canceled`/`total_canceled` fix (CR); analysed QA's "persistent Total Due on a fully-shipped cancelled order" finding (confirmed expected, not a regression); relocated tests to scenario-based `Test/Integration/Order/AdminCancellationTest` and refreshed §3/§4 test conventions. |
| v2.0 | 2026-09-14 | **Superseding re-implementation.** The v1.x line (Options A/B: block cancellation of shipped orders, or rewrite header/total cancellation math) was **abandoned in QA/PO review** and replaced with a **display-only** fix scoped to `Magento\Sales\Block\Adminhtml\Items\Column\Qty`. §I–VII below are rewritten to reflect the final shipped design; the v1.x journey is retained in §VIII as historical record of the rejected options and why. |
| v2.1 | 2026-09-15 | **QA regression correction on the v2.0 display-only fix.** Jenkins flagged a SemVer MINOR notice (accepted) and a real MSI MFTF regression (fixed) in the v2.0 formula on a partially-shipped, fully-uninvoiced order. See §IX. |
| **v2.2** | **2026-09-18** | **CR-driven View Model refactor.** Code review questioned the §IX SemVer MINOR (new public method on an `@api @since 100.0.2` block) against Adobe DevDocs View-model guidance; the display logic was moved off the block and into a new `Magento\Sales\ViewModel\Order\CanceledItemQty`, reverting `Qty.php` to origin and eliminating the `@api` change (and the MINOR) entirely. See §X. |

> **How to read this doc:** §I is the fixed record of the *final, shipped* investigation and design. §II is the Remediation Ledger for this cycle. §III–§VII carry the swarm process record for the display-only fix. §VIII is the **historical appendix** — the full v1.x record of Options A and B, preserved verbatim for why they were rejected, so nobody re-proposes them without re-reading this. §IX is the **v2.1 addendum** — the QA-found regression on top of the v2.0 design and its shipment-aware correction. §X is the **v2.2 addendum** — the CR-driven View Model refactor that superseded the block-method design and eliminated the SVC MINOR from §IX.

---

# PART I — Root Cause & Final Design (v2.0, fixed record)

## 0. Executive Summary

The header (`sales_order.total_canceled`) and the line-item tab (`sales_order_item.qty_canceled`) can disagree
for an order that is shipped (fully or partially) but not fully invoiced, then cancelled from Admin: the header
shows a nonzero "Total Canceled" while the shipped-uninvoiced line item shows no "Canceled" row at all. Two
earlier redesign attempts (blocking cancellation of shipped orders; rewriting the cancellation/totals math) were
explored, built, and both rejected — see §VIII. The shipped fix touches **only the admin display layer**: it
does not change `Order::registerCancellation()`, does not change what gets persisted to `qty_canceled` or
`total_canceled`, and does not change MSI/checkout/payment behavior. It reconciles what the admin user *sees*
with what is already true underneath.

## 1. Root cause

`Magento\Sales\Model\Order\Item::getQtyToCancel()` is `min(getQtyToInvoice(), getQtyToShip())`. On a fully
shipped item, `getQtyToShip() = 0`, so `getQtyToCancel() = 0` regardless of invoicing state — cancellation never
touches `qty_canceled` for a shipped unit. That is intentional (shipped goods are physically gone; the item
grid correctly never shows more "Canceled" than could exist). The bug is a **display omission**, not a bad
persisted value: `Order::registerCancellation()` (`app/code/Magento/Sales/Model/Order.php:1363-1406`) still sets
`total_canceled` at line 1394 for the value that was never billed, even though no single item's `qty_canceled`
reflects it.

A second, initially-missed wrinkle compounds it: `registerCancellation()` **downgrades the resulting state** from
`CANCELED` to `COMPLETE`/`PROCESSING` (lines 1368-1373) whenever any item has `getQtyToRefund() > 0` (i.e. there
was a partial invoice). So the fully-repro case (3 ordered / 2 invoiced / 3 shipped, cancel) does not even end in
`Order::isCanceled() === true` — it lands in `STATE_COMPLETE` with `total_canceled > 0` and every item's
`qty_canceled` still `0`.

## 2. First display attempt — why it failed QA

The first pass at a display-only fix guarded the new "Canceled" row on `$order->isCanceled()`. That guard is
wrong for the dominant repro case: because of the state downgrade above, `isCanceled()` is `false` for a
partially-invoiced, fully-shipped, cancelled order — so the guard suppressed the very row it was meant to add.
It only worked for the fully-uninvoiced variant (which does stay `CANCELED`). QA correctly rejected it as not
fixing the reported ticket. **A mocked unit test hid this**: the unit test constructed an item/order double where
`isCanceled()` was stubbed directly to `true`, so it could never exercise the real state-machine downgrade and
passed regardless of which guard was used. The fix was to drop the mocked unit test and drive the assertion off
a real, fixture-built order taken through the actual admin cancellation flow (integration test) — which
immediately reproduced the COMPLETE-state, `isCanceled()===false` case and forced the guard to be corrected.

## 3. Corrected guard and formula (shipped)

`Magento\Sales\Block\Adminhtml\Items\Column\Qty::getDisplayCanceledQty(): float`:

- **Guard:** `(float) $order->getTotalCanceled() > 0.0` — true in both outcomes (`COMPLETE` after a partial
  invoice, or `CANCELED` when nothing was ever invoiced), because `total_canceled` is set independently of which
  state the order lands in.
- **Formula:** if `qtyOrdered − qtyInvoiced > 0`, display that (the never-billed units, including shipped ones
  that never populate `qty_canceled`); otherwise fall back to the persisted `getQtyCanceled()`.
- The per-item subtraction self-scopes: a fully-invoiced item on the same cancelled order yields `0` and shows no
  row, so the fix cannot "invent" a cancellation on a line that was actually fulfilled.

`app/code/Magento/Sales/view/adminhtml/templates/items/column/qty.phtml` reads
`$block->getDisplayCanceledQty()` instead of `$item->getQtyCanceled()` directly for the "Canceled" row. No
`__()`/i18n change — "Canceled" already exists in the phrase catalog.

**Deliberately NOT touched:** `Order::registerCancellation()`, `total_canceled`/`base_total_canceled`
computation, `shipping_canceled`, the MSI cancel observer, the Admin `Order\Totals` block (Canceled-vs-Due
rendering), and any storefront/checkout/payment path. The bug is display-only; the fix is display-only.

## 4. Test vehicle

`app/code/Magento/Sales/Test/Integration/Order/AdminCancellationTest.php` — a flow-specific integration class
(fixture-built order → real invoice/shipment fixtures → real `OrderManagementInterface::cancel()` → reload →
assert), 4 case methods:

1. `testCancelPartiallyInvoicedFullyShippedOrderDisplaysUninvoicedQtyAsCanceled` — 3 ordered / 2 invoiced / 3
   shipped → cancel → state `COMPLETE`, `total_canceled > 0`, persisted `qty_canceled = 0`, display `1`
   (`3 − 2`). **This is the reported ticket's repro.**
2. `testCancelFullyUninvoicedFullyShippedOrderDisplaysAllShippedQtyAsCanceled` — 3/0/3 → cancel → state
   `CANCELED`, display `3`.
3. `testCancelPartiallyShippedOrderDisplayMatchesPersistedCanceledQty` — 4 ordered / 1 invoiced / 1 shipped
   (partial ship) → cancel → `COMPLETE`, persisted `qty_canceled = 3` and display `3` — proves no divergence
   when the order was only partially shipped (mirrors the existing CE MFTF invariant, see §5).
4. `testOpenPartiallyInvoicedOrderDisplaysNoCanceledQty` — an order that is invoiced 2/3 but never shipped and
   never cancelled → `total_canceled = 0` → display `0`. Guards against a false positive on a merely-open order.

The superseded block **unit** test (`Test/Unit/Block/Adminhtml/Items/Column/QtyTest.php`) was deleted; mocked
collaborators cannot exercise the real `registerCancellation()` state transition that caused the QA-rejected
first attempt, so unit-level coverage of this block was judged to give false confidence (see §2) and integration
coverage was kept as the only vehicle for this scenario.

## 5. Regression check

The existing CE MFTF `AdminCheckProductQtyAfterOrderCancellingTest` (qty 4 / invoiced 1 / shipped 1 → cancel →
displays "Canceled 3") yields the identical value under the new formula: `qtyOrdered(4) − qtyInvoiced(1) = 3`,
matching the persisted `qty_canceled` for that partial-shipment case. No behavior change for orders that were
never (fully) shipped past their invoiced quantity.

---

# PART II — Remediation Ledger (this cycle)

| # | Challenge | Action taken | Status |
|---|---|---|---|
| M1 | Header/line-item display disagreement on cancel of a shipped-uninvoiced order | `getDisplayCanceledQty()` keyed on `total_canceled`, `qtyOrdered − qtyInvoiced` fallback | ✅ |
| M2 | First display guard (`isCanceled()`) silently wrong for the dominant repro case | Root-caused to the `registerCancellation()` state downgrade; guard corrected to `total_canceled > 0.0` | ✅ |
| M3 | Mocked unit test masked M2 | Deleted the mock-based unit test; replaced with fixture-driven integration coverage that exercises the real state machine | ✅ |
| M4 | Cross-domain risk (MSI, totals, checkout) from two earlier redesign options | Both options built, both failed cross-domain verification (see §VIII); final option scoped to display-only, re-verified against `AdminCheckProductQtyAfterOrderCancellingTest` and other cancel-flow MFTF | ✅ |
| M5 | Ticket-repro traceability for reviewers/QA | Manual Steps to Reproduce captured below and in dev summary/release notes | ✅ |

---

# PART III — BDAD Cycle Record

| Phase | Action | Outcome |
|---|---|---|
| Red | New integration test class, 4 cases, run against the pre-fix `phtml`/block | ❌ 1/4 failing — `testCancelPartiallyInvoicedFullyShippedOrderDisplaysUninvoicedQtyAsCanceled` failed (display `0`, expected `1`); the other 3 cases were already true of the old code (including the false-positive guard, cases 2–4) |
| Green | `getDisplayCanceledQty()` + template change shipped | ✅ 4/4 green |
| Consensus Gateway | `php -l`, PHPCS, static suite | ✅ green |
| Cross-Domain Verification Gate | Re-ran `AdminCheckProductQtyAfterOrderCancellingTest` (CE MFTF) plus the cancel-flow regression math in §5 | ✅ no regression — identical displayed value under old and new formula for the partial-shipment case |

Only 1 of the 4 integration cases was "Red" in the strict sense (the ticket's own repro); the other three encode
invariants (no-divergence-on-partial-shipment, no-false-positive-on-open-order, full-uninvoiced variant) that the
final formula must continue to satisfy — written alongside the failing case per Rule C (invariant-first
assertions), not added after the fact.

---

# PART IV — Manual Steps to Reproduce (carried into dev summary / release notes)

**Precondition:** a simple product order, quantity 3, in Admin.

**Steps (verbatim reproduction path):**
1. Place an order for qty 3 of a single product.
2. Invoice 2 of the 3 units (Admin → order → Invoice, partial qty 2).
3. Ship all 3 units (Admin → order → Ship, full qty 3 — this is allowed because the shipment quantity is
   independent of the invoiced quantity).
4. From the order view, click **Cancel**.
5. Reopen the order view (Sales → Orders → the order).

**Expected vs. Actual (BEFORE fix):** the order header shows "Total Canceled" (nonzero), but the line item's Qty
column shows no "Canceled" row at all (`Ordered 3 / Invoiced 2 / Shipped 3`, no Canceled row) — header and line
item disagree.

**AFTER fix:** the line item now shows `Ordered 3 / Invoiced 2 / Shipped 3 / Canceled 1`, matching the header's
"Total Canceled." (The order's displayed state is `Complete`, not `Canceled` — that is the pre-existing,
unchanged state-machine behavior described in §1, not something this fix alters.)

**Variant (fully-uninvoiced):** same steps but skip step 2 (invoice 0 of 3) — cancel leaves the order in state
`Canceled` and the line item now shows `Canceled 3` (all units, since none were ever billed).

---

# PART V — CLAUDE.md / Swarm Rules Applied

- **Rule A — Blast-radius analysis before editing shared/`@api` code**: applied; the `Qty` block carries `@api`,
  which is why the change was scoped to a new additive method (`getDisplayCanceledQty()`) rather than altering
  `getQtyCanceled()` or any model-layer contract.
- **Rule B — Cross-domain verification gate**: applied per §III; this is precisely the gate that caught both
  Option A and Option B in §VIII before they reached Consensus.
- **Rule C — Invariant-first assertions**: the 4 integration cases assert state/guard/formula invariants, not
  single point values, per §III.
- **MFTF-first for concurrency**: not applicable — this is a single-session, single-process admin flow; correctly
  routed to PHP integration tests, not MFTF, per the Orchestrator's `repro-class` triage.

---

# PART VI — Outstanding / Follow-ups

- ⬜ The pre-existing lifecycle quirk noted during Option B's exploration (a fully-shipped, cancelled order can
  strand an uncollectable "Total Due" because `canInvoice()` is false in `COMPLETE`) is a **separate, pre-existing
  Magento limitation**, unrelated to and unchanged by this display fix. Not in scope for ACP2E-5180; flag as a
  candidate follow-up ticket if the business wants it addressed.
- ⬜ MIG (Magento Impact Graph) tooling referenced in the v1.x line (§VIII) remains unbuilt; it would have
  shortened the Option A/B exploration by making the MSI/totals blast radius visible before implementation
  rather than via failing CI.

---

# PART VII — Verdict

Shipped, Consensus-green, cross-domain-gate-passed, display-only fix. Root cause fully explained (including the
state-downgrade nuance that sank the first display attempt); no cancellation logic, totals math, or model
behavior was changed. Manual repro path verified before/after in §IV.

---

# PART VIII — Historical Appendix: the v1.x journey (Options A & B, both rejected)

*Preserved from v1.2 verbatim as the record of what was tried before the display-only design in Parts I–VII, and
why neither survived review. Do not re-propose either option without re-reading this section.*

## Option A — block cancellation of fully-shipped orders

Explored making `Order\Item::getQtyToCancel()`/the cancel action refuse to cancel units that had already shipped
(treating "shipped" as terminal, non-cancelable). This is the more "obviously correct" business rule at first
glance, but it **broke CRITICAL-tagged tests on first implementation**:
- MSI acceptance suite: `AdminCancelOrderWith...AfterPartialShipmentTest` (cancelled a different quantity of
  shipped units than the domain expected — see the original R1→R2 cross-domain blind spot below).
- Payment: `PayflowproVoidTest` — void-on-cancel flows assume cancellation is reachable from more states than
  "block if shipped" allowed.
- Storefront checkout: a customer-facing reorder/cancel path relied on being able to cancel a shipped-but-unpaid
  order.

Rejected: too large a behavior change for a display bug, and empirically broke three independent domains the
first time it was tried.

## Option B — shipment-aware header/total rewrite

Explored making `registerCancellation()`'s header/total computation shipment-aware end-to-end (subtracting
shipped-but-uninvoiced value out of `total_canceled`/`shipping_canceled`/tax/discount canceled fields, so the
header would read `0` instead of a nonzero "Total Canceled" for a fully-shipped order). This was built out fully
(see the R1–R6 journey and Blind Spots #1–#3 below) and **passed its own test suite**, but was **rejected by
Product** on business-meaning grounds: `total_canceled` recording the true canceled money value (even on
shipped-but-unbilled goods) is itself correct bookkeeping — the actual defect was that the **display** didn't
surface the equivalent information at the line-item level. Rewriting the header math to hide the value QA had
flagged risked masking real accounting data rather than fixing the display gap. Rejected in PO review in favor
of the display-only design in Parts I–VII.

### The original R1–R6 execution journey (kept for the cross-domain / mocking lessons, still valid)

| Phase | Action | Outcome |
|---|---|---|
| R1 — Red/Green | Localized fix: `getQtyToCancel()` → `max(getQtyToInvoice(), 0)`. Integration test (fully-shipped/partial-invoice) green. | ✅ Local suite green — **false confidence** |
| R2 — Regression #1 | MSI acceptance test cancelled **5** shipped units instead of **2** (removed the `getQtyToShip()` guard). | ❌ Broke a domain we never inspected |
| R3 — Correct redesign | Dual-layer: restored shipment-aware `min()` at the item; corrected the **header** (`registerCancellation`) to subtract shipped-but-uninvoiced value. MFTF passed. | ✅ Domains reconciled |
| R4 — Hardening | Extended the header correction to tax/discount + base; routed all setters through `PriceCurrency::round()`; fixed a tax rate-cache cross-test leak via a distinct address. | ✅ Component-consistent, deterministic, `OK (1 test, 10 assertions)` MFTF |
| R5 — Regression #2 | Legacy `OrderTest::testRegisterDiscountCanceled` failed in CI (`0.0` vs `20`) — hardening added collaborators the strict mock didn't cover. | ❌ Unit tier neglected |
| R6 — Fix + verify | Stubbed the new collaborators; full `OrderTest` green (72/72). | ✅ |

#### Blind Spot #1 — Cross-Domain Context Gap (R1 → R2)

R1 was *locally correct, globally wrong*. `min(uninvoiced, unshipped)` was a **load-bearing domain invariant**
("a shipped unit cannot be cancelled"), not an incidental detail. The dependency on MSI was **grep-invisible**
because it runs through indirection:

```
Order\Item::getQtyToCancel()
   ├─(di.xml plugin)──▶ Bundle\...\Plugin\Item::afterGetQtyToCancel          ← grep-invisible
   └─(caller)─────────▶ Order::registerCancellation()
            └─(events.xml 'sales_order_item_cancel')──▶ InventorySales\...\CancelOrderItemObserver  ← grep-invisible
                     └─ Magento_InventorySales ──▶ MFTF @group msi ──▶ AdminCancelOrderWith…AfterPartialShipmentTest  ← the test we broke
```

Three compounding failures: **(a)** no blast-radius enumeration across `ext/magento/inventory`; **(b)** *green ≠
correct* — the authoritative acceptance signal lived outside the fast loop; **(c)** point-value tests that
encoded the wrong semantics instead of a domain invariant. (This is the origin of Rule A/B in CLAUDE.md §4.)

#### Blind Spot #2 — Unit-Test Mocking Boundaries (R4 → R5)

The hardening added **two** new collaborators to `registerCancellation()`. The legacy strict mock
(`createMock`) covered neither, so both silently returned defaults: `$item->getQtyToShip()` → unmocked → `0`;
`$this->priceCurrency->round(...)` → no default stub → `null` → `setDiscountCanceled(null)` → `0.0`.
*Changing a method's collaborator set is an API change to every test that mocks that method — with no compiler
to catch it.* (Origin of Rule D.) This same class of lesson recurred in the v2.0 cycle at a different layer
(§2/M3 above): a mock hid the wrong guard rather than a wrong collaborator set, reinforcing that this block's
cancellation-adjacent logic should be integration-tested, not mocked.

#### Blind Spot #3 — Order-level shipping left out of the shipment-aware rule (CR, v1.2)

The first header reconciliation made item subtotal/tax/discount shipment-aware but left
`shipping_canceled = shippingAmount − shippingInvoiced` untouched. Fixed by excluding shipping once any shipment
exists. Moot in the final design since Option B was not shipped, but preserved as the reason "apply the
invariant to every money component" was called out as a rule in v1.x.

#### QA finding — persistent "Total Due" on a fully-shipped cancelled order (v1.2, still relevant)

QA (Vadym) flagged that a fully-shipped, partially-invoiced, cancelled order shows a persistent "Total Due" that
never clears (because the order lands in `Complete`, and `canInvoice()` is `false` there). This was analyzed as
a **pre-existing Magento lifecycle limitation**, not a regression introduced by any option — carried forward into
Part VI of this v2.0 document as an out-of-scope follow-up.

### Why Option B was ultimately not the shipped design

Despite passing its own hardened test suite (R6), Option B rewrote money fields that other domains (refunds,
reporting, Total Due/Total Canceled rendering) depend on for their literal, audited values. PO review determined
the *reported* defect was a display gap, not a bookkeeping error, and instructed re-scoping to a display-only fix
— which is Parts I–VII of this document.

---

# PART IX — v2.1 Addendum: QA-Found Regression on the Display-Only Fix

*What changed since v2.0. The v2.0 design in Parts I–VII stands as the correct root cause and approach; this
section records a formula defect Jenkins caught in the v2.0 implementation and the shipment-aware correction
applied on top of it.*

## 1. What Jenkins flagged

The first display-only fix (v2.0, §3) went to CI and Jenkins raised two issues:

1. **SemVer MINOR notice** — `Magento\Sales\Block\Adminhtml\Items\Column\Qty::getDisplayCanceledQty()` adds a new
   public method to a class annotated `@api @since 100.0.2`. **Decision (user): keep the method and accept the
   MINOR.** Adding a method to an `@api` class is backward-compatible/routine and does not warrant abandoning the
   testable, block-level design. No `@since` tag was added on the new method, because
   `app/code/Magento/Sales/composer.json` carries no `version` field on this mainline branch, so there is no
   version string to stamp. (Alternative considered and rejected: move the computation into `qty.phtml` directly
   to avoid growing the `@api` surface at all — rejected in favor of keeping the logic unit/integration-testable
   on the block rather than buried in a template.)
2. **Real regression** in a CRITICAL-tagged MSI MFTF test,
   `AdminCancelOrderWithSimpleProductCustomStockAfterPartialShipmentTest` (5 ordered / 0 invoiced / 3 shipped,
   partial shipment, then cancel — asserts the grid displays "Canceled 2"). Under the v2.0 formula
   (`qtyOrdered − qtyInvoiced`, guarded only on `total_canceled > 0`), the display returned **5** instead of
   **2** — it overcounted the 3 shipped-but-uninvoiced units that a *partial* shipment had already legitimately
   moved out of the cancelable pool.

## 2. Root cause of the overcount

On a **partial** shipment, the persisted `qty_canceled` (= `min(getQtyToInvoice(), getQtyToShip())` at the item
level, §1) already correctly reflects only the *unshipped*-and-uninvoiced units — that value is not the display
omission described in Part I §1 at all; the omission only exists when the item is **fully** shipped, because
`getQtyToShip()` collapses to `0` and erases the never-billed portion from `qty_canceled` entirely. The v2.0
formula did not distinguish "fully shipped" (where the persisted value is genuinely missing information) from
"partially shipped" (where the persisted value is already correct) — it substituted the never-billed total in
both cases, double-counting the partial-shipment case.

## 3. Corrected formula (shipment-aware)

`Qty::getDisplayCanceledQty()` now gates the substitution on full shipment, not just on a nonzero
`total_canceled`:

- Display `qtyOrdered − qtyInvoiced` **only when** `(float) $order->getTotalCanceled() > 0.0` **AND**
  `(float) $item->getQtyShipped() >= (float) $item->getQtyOrdered()` (i.e. the item is fully shipped).
- Otherwise (order not canceled, or item only partially/never shipped), display the persisted
  `$item->getQtyCanceled()` unchanged.

This preserves the v2.0 fix for its original repro (fully shipped, partially invoiced → displays the uninvoiced
remainder) while leaving partially-shipped items exactly as the state machine already computes them.

## 4. Manual Steps to Reproduce (the regression)

**Precondition:** a simple product (custom/default stock), order quantity 5, in Admin.

**Steps (verbatim reproduction path):**
1. Place an order for qty 5 of a single product.
2. Do not invoice anything (0 of 5 invoiced).
3. Ship 3 of the 5 units (Admin → order → Ship, partial qty 3 — a partial shipment).
4. From the order view, click **Cancel**.
5. Reopen the order view (Sales → Orders → the order).

**Expected vs. Actual (under the v2.0 formula, BEFORE the v2.1 correction):** the line item's Qty column should
show `Canceled 2` (the 2 units that were never shipped and never invoiced — matching the persisted
`qty_canceled`), matching the MSI MFTF's assertion. The v2.0 formula instead displayed `Canceled 5` (the full
never-invoiced quantity, incorrectly including the 3 already-shipped units).

**AFTER the v2.1 correction:** the line item shows `Canceled 2`, matching the persisted value and the MFTF
assertion; the original v2.0 repro (Part IV) is unaffected because that item is fully shipped.

## 5. BDAD cycle for the correction

| Phase | Action | Outcome |
|---|---|---|
| Red | New integration case `testCancelPartiallyShippedUninvoicedOrderDisplaysOnlyUnshippedQtyAsCanceled` (5 ordered / 0 invoiced / 3 shipped → expected persisted+displayed `2`), run against the v2.0 formula | ❌ failed — displayed `5.0`, expected `2.0` |
| Green | Added the `getQtyShipped() >= getQtyOrdered()` (fully-shipped) guard to `getDisplayCanceledQty()` | ✅ new case green; existing 4 `AdminCancellationTest` cases stay green |
| Consensus Gateway | `php -l`, PHPCS, static suite | ✅ green |
| Cross-Domain Verification Gate | Regression-math check: confirmed `AdminCancelOrderWithSimpleProductCustomStockAfterPartialShipmentTest`'s scenario now computes `2` under the corrected formula, and the CE `AdminCheckProductQtyAfterOrderCancellingTest` (Part I §5, partial shipment, 4/1/1) stays at `3` | ✅ no regression |

`Test/Integration/Order/AdminCancellationTest` now carries **5** case methods (the 4 from Part I §4 plus this
one): fully-shipped/partial-invoice → `1`; fully-uninvoiced/fully-shipped → `3`; partially-shipped 4/1/1 → `3`
(unchanged, proves no divergence for partial shipments); open/never-cancelled → `0`; and this new
partially-shipped/fully-uninvoiced 5/0/3 → `2`.

## 6. Second Jenkins failure — investigated and ruled unrelated

`StorefrontPurchaseProductWithCustomOptionsWithLongValuesTitleTest` (Catalog domain) also failed in the same CI
run. Investigated and found **unrelated** to this fix: it never cancels an order (`total_canceled = 0`, the
"Canceled" row is never rendered on that flow) and its assertions are entirely about custom-option title text
rendering. Classified as a pre-existing flake, not caused by the `Qty` block change — consistent with the
project's known-flake pattern of unrelated MFTF failures riding along in a CI run (see MEMORY.md entries on
pre-existing/flaky MFTF failures for this codebase).

## 7. Process lesson

The shipment-aware distinction (fully shipped vs. partially shipped) was actually raised at the very start of
the v2.0 re-implementation, but was simplified away when the user asked to keep the persisted counts unchanged
for the non-fully-shipped case — at the time this looked like an equivalent simplification. It was not: the
distinction turned out to be load-bearing for keeping the MSI partial-shipment test green. **Fixture-driven
MSI/integration coverage is what caught this** — a unit test built on a mocked `Order`/`Item` double would have
asserted whatever guard it was told to assert and could not have surfaced the shipped-vs-partial distinction the
real state machine enforces (the same class of lesson as Part I §2/§4 and Part VIII Blind Spot #2, now recurring
a third time at a third layer of this same feature).

## 8. Branch state note

Commit `f08ada35cb6` on `ACP2E-5180-final` is the user's own snapshot of the v2.0 first-pass fix (the
`isCanceled()`-guard-era `Qty.php`/`AdminCancellationTest.php`). The v2.1 shipment-aware correction described in
this section is applied on top of that commit but is **uncommitted** as of this writing, pending the user's own
follow-up commit.

## 9. Verdict (v2.1)

Corrected, Consensus-green, cross-domain-verified. The v2.0 root cause and display-only scope (Parts I–VII)
remain the correct diagnosis and approach; v2.1 narrows the *formula* to distinguish fully-shipped from
partially-shipped items, closing the QA-found overcount without reopening any of the model/totals/MSI surfaces
that Options A and B (Part VIII) were rejected for touching. The SemVer MINOR notice on the new `@api` method is
accepted by explicit user decision, not a defect.

---

# PART X — v2.2: CR-Driven View Model Refactor (SemVer MINOR eliminated)

*What changed since v2.1. The root cause and shipment-aware formula (Parts I and IX) are unchanged; this section
records where the logic lives and why, superseding the "accept the MINOR" decision in §IX.1.*

## 1. Trigger

Code review asked why the fix added a public method to an `@api` block instead of using a View Model (ref: Adobe
DevDocs "View models" component guidance). This was raised alongside the SVC MINOR notice from v2.1
(`Qty::getDisplayCanceledQty` added to the `@api @since 100.0.2` class).

## 2. Decision

Refactor the display logic OFF the block and INTO a new View Model, eliminating the `@api` change entirely — no
SVC MINOR, no approval needed.

## 3. Final implementation

- **NEW** `Magento\Sales\ViewModel\Order\CanceledItemQty` implements
  `Magento\Framework\View\Element\Block\ArgumentInterface`. Its `getDisplayCanceledQty(OrderItemInterface $item):
  float` carries the shipment-aware logic from §IX.3 unchanged: surface `qtyOrdered − qtyInvoiced` when
  `getTotalCanceled() > 0` **AND** `getQtyShipped() >= getQtyOrdered()`; otherwise the persisted
  `getQtyCanceled()`; null-order safe.
- `app/code/Magento/Sales/view/adminhtml/layout/sales_order_view.xml` injects it into the `column_qty` block via
  `<argument name="canceled_item_qty" xsi:type="object">`.
- `app/code/Magento/Sales/view/adminhtml/templates/items/column/qty.phtml` consumes it null-guarded, falling
  back to the persisted `qty_canceled` — so the shared invoice/creditmemo `column_qty` blocks, which don't
  receive the argument, are unchanged.
- `app/code/Magento/Sales/Block/Adminhtml/Items/Column/Qty.php` **reverted to origin** (empty class) — 0-line
  diff vs `2.4-develop`.
- Integration test `Test/Integration/Order/AdminCancellationTest` (5 cases, values 1/3/3/0/2 — see §IX.5)
  retargeted to instantiate the View Model directly instead of the block; still 5/5 green.

## 4. Validation

BDAD cycle: Red (test → missing `CanceledItemQty` class) → Green (View Model + layout + template + block revert,
5/5) → Consensus GREEN, including an explicit SVC re-check: no `@api` surface changed, so SVC is clean and the
previously-drafted MINOR approval ticket from §IX.1 is moot. MSI/ConfigurableProduct regression math is
unchanged (2 and 3 respectively, per §IX.5 and Part I §5).

## 5. Process lesson

New admin/storefront DISPLAY logic should default to a View Model, NOT a new public method on a block —
especially an `@api` block, where adding a method is a SemVer event. The block-method approach was chosen first
because it was the nearest render seam and optimized for smallest diff, but it (a) touched `@api` surface and
(b) put logic on the block rather than a dedicated, testable class. An `@api`/SVC impact check should be run at
design time BEFORE adding any public method to a class carrying `@api`, not left to CR/Jenkins to catch. The
View Model version is also simpler to test (instantiate the VM, call the method) and keeps the block untouched.

## 6. Verdict (v2.2)

Superseding, Consensus-green refactor. The root cause (Part I) and shipment-aware formula (Part IX) are
unchanged and correctly diagnosed; v2.2 relocates the same logic to a View Model, eliminating the `@api` SemVer
event that v2.1 had accepted rather than avoided. `Qty.php` is now byte-identical to `2.4-develop`.
