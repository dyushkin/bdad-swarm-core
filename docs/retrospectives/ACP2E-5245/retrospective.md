# Post-Mortem: AI-Driven TDD Workflow — ACP2E-5245

*Code-fix ticket. Objective: record the Red→Green→Refactor journey, the root-cause archaeology into the ACP2E-2923 fix that this regression re-opened, the emulated MIG blast-radius pass, and the residual second-order defect (lost default-billing) that the naïve fix would have introduced.*

| | |
|---|---|
| **Current version** | v1.0 |
| **Last updated** | 2026-08-14 |
| **Scope** | ACP2E-5245 — a newly-created customer with no address on file has their shipping/billing address **duplicated** in the customer address book after their first successful order; the duplicate surfaces in the Shipping-step selector on the next checkout. |
| **Ticket chain** | ACSD-60632 / ACSD-61793 / ACSD-62246 (T3, same symptom family) → **ACP2E-5245** (core dev, this branch). Prior/related: ACP2E-2923 (2.4.8-beta1, *failed-retry* variant), ACP2E-4997 (backport), AC-12463 (open). |
| **Status legend** | ✅ done · 🟡 partial · ⬜ not started · 🔎 resolved by investigation |

### Revision history
| Version | Date | Change |
|---|---|---|
| v1.0 | 2026-08-14 | Initial record: Red→Green→Refactor of the duplicate-address regression, two-file coordinated fix, the second-order default-billing defect caught by invariant assertions, emulated MIG pass, rules status, action plan. Companion to [`postmortem-ACP2E-5203.md`](../ACP2E-5203/retrospective.md) and [`postmortem-ACP2E-5180.md`](../ACP2E-5180/retrospective.md). |
| v1.1 | 2026-08-14 | Review-cycle corrections (§8): test reframed from atomic bug-test to scenario-scoped flow test (`NewCustomerOrderTest`, +order-placed assertion → 4 assertions); corrected an earlier wrong claim about fixture-attribute override; three CLAUDE.md rules codified (manual-repro-steps, scenario-scoped-over-atomic, test-docblock conventions); §6/§7 statuses updated accordingly. |

> **How to read this doc:** §0 is the summary. §1 is the Red→Green→Refactor journey. §2 is the root-cause archaeology (why ACP2E-2923's fix left this door open). §3 is the second-order defect the invariant assertions caught. §4 is the emulated MIG blast-radius pass. §5–§7 carry BDAD improvements, CLAUDE.md rules status, and the action plan. §8 records the review-cycle corrections made after v1.0.

---

## 0a. Manual Steps to Reproduce

Verbatim from the ticket, with the one implied precondition annotated:

1. On a clean 2.4-develop instance, create a new customer account via the storefront — **do not add any address to the account**.
2. Add any product to the cart and complete checkout through to a successful order. *(Precondition the storefront supplies but the ticket omits: the Shipping step's "Save in address book" is checked → the request carries `save_in_address_book = 1`. This is the trigger; the quote-address column defaults to `0`, so a test that omits it will not reproduce — see §5.)*
3. Return to the storefront home page.
4. Add any product to a new cart and proceed to Checkout.
5. On the Shipping step, observe the address selector.

- **Actual:** the address entered during the first checkout appears **twice** in the Shipping-step address list.
- **Expected:** exactly **one** entry.
- **Encoded as:** `NewCustomerOrderTest` (integration) — a canonical *new-customer-first-order* flow test: steps 1–2 as fixtures + a `savePaymentInformationAndPlaceOrder` call; asserts the flow invariants *order placed* + *customer address-book count == 1* (the "duplicate on next checkout" symptom) + *that address is default billing AND shipping*.

---

## 0. Executive Summary

For a customer account created immediately before checkout (no address on file) who checks out with a single address (**shipping == billing**, *Save in address book* on), the address is written to the customer address book **twice** on the first successful order. Two independent code paths each persist it:

1. `Checkout\Model\PaymentInformationManagement::processShippingAddress()` (runs in `savePaymentInformation`, *before* `placeOrder`) saves the shared address once and stamps `customer_address_id` onto **both** the billing and shipping quote addresses — the ACP2E-2923 behavior.
2. `Quote\Model\QuoteManagement::_prepareCustomerQuote()` (runs inside `submit`/`placeOrder`) then re-exports the billing quote address with `exportCustomerAddress()` and saves it **again**, because its billing branch is gated on `save_in_address_book` and never checks whether the address was already persisted.

`exportCustomerAddress()` never carries `customer_address_id` (the `sales_convert_quote_address → to_customer_address` fieldset maps it only via the `to_order` aspect), so the re-exported address has no id and is inserted as a fresh row → the duplicate.

**Fix (two coordinated one-liners + a guard removal):**
- `QuoteManagement::_prepareCustomerQuote()` — when the billing quote address already references a `customer_address_id`, stamp it onto the exported object so the existing `if (!$billingAddress->getId())` guard skips the re-save. *(kills the duplicate)*
- `PaymentInformationManagement::saveAddressesAsDefault()` — drop the `!$billingAddress->getSaveInAddressBook()` condition (and the now-unused `$billingAddress` param). This method only ever runs in the same-as-billing branch, so the single saved address must become the customer's default **billing** too — otherwise removing the duplicate save (which previously became the default billing) leaves the customer with a default shipping but **no** default billing. *(preserves the invariant)*

Net: **11 insertions / 9 deletions across 2 files**, one net-new integration test.

---

## 1. The Execution Journey (Red → Green → Refactor)

| Phase | Action | Result |
|---|---|---|
| **Ingest** | Parsed the Jira HTML export (grep past the DOM scaffolding to `#description-val`). Extracted STEPS/ACTUAL/EXPECTED and the "likely area" hint (`processShippingAddress` / `ShippingInformationManagement`). | Clear repro contract: new customer, no address, 1st order → 2 addresses. |
| **Locate** | Read both service classes + `QuoteManagement::_prepareCustomerQuote`; traced `exportCustomerAddress()` and the `fieldset.xml` aspect map. | Identified the two-writer race; confirmed `customer_address_id` is not carried by the export. |
| **Red** | New integration test (later reframed to the flow-oriented `NewCustomerOrderTest`) reusing the `Set*Address`/`SetDeliveryMethod` fixtures, with `save_in_address_book => 1` (the storefront default a new customer sends; DB default is `0`, so the stock fixtures would not reproduce it). | ❌ `Failed asserting that actual size 2 matches expected size 1` — reproduced. |
| **Green** | Added the `customer_address_id` guard in `_prepareCustomerQuote`. | ✅ count == 1. |
| **Refactor / harden** | Added default-billing + default-shipping invariant assertions → they exposed `default_billing = NULL` (see §3). Fixed `saveAddressesAsDefault`; removed the unused param + dead comment. | ✅ 1 address, default shipping **and** billing both point to it (3 assertions). |
| **Validate** | `php -l` ✓ · `phpcs --standard=Magento2` clean on all 3 files ✓ · unit: `PaymentInformationManagementTest` 9/9, `QuoteManagementTest` + `ShippingInformationManagementTest` (29 total) ✓ · integration: `QuoteManagementTest` 13/13, existing `Payment`/`Shipping` `InformationManagementTest` 2/2 ✓ · static suite (LiveCodeTest) 6/6 ✓. | All green. |
| **Review** | Reframed the test to a scenario-scoped flow (`NewCustomerOrderTest`) + added an *order-placed* assertion (→ 4 assertions); applied test-docblock conventions; codified three CLAUDE.md rules (§8). Final direct check re-run per reviewer preference. | `NewCustomerOrderTest` OK (1 test, 4 assertions), phpcs clean. |

## 2. Root-Cause Archaeology — why ACP2E-2923 left this open

ACP2E-2923 ("Multiple addresses added to the account when checkout as new customer") targeted the **failed-retry** variant: repeated address creation when order placement *fails* and the shopper retries. Its fix introduced `AddressComparator`, `processShippingAddress()`, and the `customer_address_id` back-stamping onto the billing/shipping quote addresses so a retry would recognize the already-saved address.

What it did **not** do was teach the *downstream* writer — `QuoteManagement::_prepareCustomerQuote()`, which runs on the **successful** path inside `submit()` — to honor that stamp. Its billing branch condition is:

```php
if (!$billing->getCustomerId() || $billing->getSaveInAddressBook()) { ... exportCustomerAddress() → save ... }
```

For a logged-in customer `getCustomerId()` is truthy, so the branch hinges entirely on `save_in_address_book`. A new customer sends `save_in_address_book = 1`, so the branch fires and re-saves — the id stamped by `processShippingAddress` is invisible to it because `exportCustomerAddress()` drops the id. ACP2E-2923's own regression test (`testCustomerAddressIdAfterPlacingOrder`) sets `billing.save_in_address_book = null`, so it never exercised the `save_in_address_book = 1` path — the exact gap this ticket falls through.

**Distinct from ACP2E-2923:** here the first order *succeeds*; the duplicate appears only on the *next* checkout. Same code neighborhood, different trigger — which is why it re-opened on mainline despite ACP2E-2923 being "Done."

## 3. The second-order defect the invariants caught 🔎

The minimal duplicate-killer alone (guard in `_prepareCustomerQuote`) turned the test green on the *count* assertion — but the CLAUDE.md **Invariant-First** rule (money/quantity → assert cross-field invariants; here the address-book analogue: *a new customer's sole, used address is their default for both billing and shipping*) said to assert defaults too. Those assertions failed: `default_shipping = 5`, **`default_billing = NULL`**.

Cause: `saveAddressesAsDefault()` skipped marking default-billing whenever `billing.save_in_address_book` was true, on the (now-false) assumption that `_prepareCustomerQuote` would save a *separate* billing address and make *that* the default. Pre-fix, the **duplicate** row was becoming the default billing — so eliminating the duplicate silently orphaned the default-billing pointer. Because `saveAddressesAsDefault()` is only ever called from the same-as-billing block, the shared address is unconditionally the billing address, so the guard was simply wrong. Dropping it restores the invariant.

**Lesson:** a naïve "delete the duplicate" fix would have shipped a subtler regression (customer with a default shipping but no default billing). The count assertion passed; only the invariant assertion caught it. This is a textbook win for the Invariant-First rule.

## 4. Emulated MIG pass (blast radius)

Per CLAUDE.md Phase 0 + the [Impact Graph proposal](../../mig/impact-graph-proposal.md), resolved the diff's true blast radius including the grep-invisible DI wiring:

| Changed symbol | Resolved impact (incl. indirect edges) | Tests selected & run |
|---|---|---|
| `QuoteManagement::_prepareCustomerQuote` (protected) | `PREFERS` `CartManagementInterface → QuoteManagement`; `virtualType asyncCartManagement` inherits it; `INTERCEPTS` on `submit`/`placeOrder` from AsyncOrder, Bundle, NegotiableQuote, OfflinePayments, Persistent, SalesRule, PurchaseOrder (all wrap, none touch address persistence). Only self-caller is `submit()`. | unit `QuoteManagementTest` ✓; integration `QuoteManagementTest` 13/13 (incl. `testCustomerAddressIdAfterPlacingOrder`, `testSubmitGuestCustomer`) ✓ |
| `PaymentInformationManagement::processShippingAddress` / `saveAddressesAsDefault` (private) | `PREFERS` `PaymentInformationManagementInterface → PaymentInformationManagement`; entrypoint REST `POST /V1/carts/mine/payment-information`. Callers are internal only. | unit `PaymentInformationManagementTest` 9/9 ✓; integration `PaymentInformationManagementTest` ✓; net-new `NewCustomerOrderTest` ✓ |

**Recall-first note (shared @api model):** `QuoteManagement` is the order-submission chokepoint for guest, registered, admin, async, and B2B/EE flows. The change is *gated* on `billing.getCustomerAddressId()` already being set, which happens **only** in the same-as-billing new-customer path — guest checkout (no customer address id) and distinct billing/shipping (`processShippingAddress` never stamps billing) are provably unaffected, and `testSubmitGuestCustomer` confirms it. A full MFTF MSI/B2B acceptance run was **not** executed locally (⬜ below) but the gating argument bounds the risk to the exact scenario under test.

## 5. How to improve BDAD (from this ticket)

- **Fixture-default vs. storefront-default trap.** The stock `Set{Billing,Shipping}Address` fixtures omit `save_in_address_book`, and the column defaults to `0` — so a test built naïvely on them would be **green while the bug is fully present**. The reproduction hinges on passing `save_in_address_book => 1` to match what a real new-customer storefront sends. Worth a CLAUDE.md note: *when reproducing address-book/customer-persistence bugs, assert the fixture's flags match the storefront request, not the DB default.*
- **Invariant assertions earned their keep.** The count assertion alone would have shipped the §3 regression. Reinforces: assert the *cross-field invariant* (sole address ⇒ default for both), never just the scalar count.
- **Regression-test the sibling flag value.** ACP2E-2923's test pinned `save_in_address_book = null`; the bug lived at `= 1`. When a fix branches on a flag, cover *both* values.
- **Prefer scenario-scoped flow tests over atomic per-bug tests (review-cycle decision).** The first draft was a bug-named atomic test (`NewCustomerAddressDuplicationTest`). Reframed to a canonical *new-customer checkout order-placement* flow test (`NewCustomerOrderTest`) that asserts the flow's whole invariant set — order placed **+** exactly one stored address **+** that address is default billing and shipping — with the duplication being one guarded invariant. This amortizes the expensive order-placement setup, curbs suite bloat, and (crucially) is the structure that makes the §3 second-order regression impossible to miss. Naming: class = flow, method = case (`testFirstOrderStoresSingleDefaultAddress`), never repeat the case qualifier in both. Now codified in CLAUDE.md §4.
- **Corrected a framework fact mid-review.** An earlier in-session claim that augmenting an existing test class is "fragile" (due to `array_replace`-by-index merging) was **wrong** for PHP-8-attribute fixtures: `AbstractDataFixture::_getFixtures()` parses method scope first and only falls back to class scope when the method declares none, so method-level `#[DataFixture]`s **fully replace** class-level ones (clean override). The `array_replace` merge applies only to legacy `@magentoDataFixture` docblocks. Lesson: verify framework behavior against the parser source before asserting a constraint that steers test design.

## 6. CLAUDE.md / Rules impact (status)

- ✅ **TDD Red→Green** honored: failing integration test first, verified red, then implementation.
- ✅ **Phase 0 Blast-Radius** performed (grepped callers/plugins/preferences across `app/code`; `ext`/EE/B2B symlinks not present in this CE checkout — noted).
- ✅ **Invariant-First Assertions** — directly caught the §3 second-order defect.
- ✅ **Mock Surface Sync** — `saveAddressesAsDefault` signature change is a private method; the unit test mocks the exported address as `CustomerAddressInterface` (auto-stubbed), so the extra `setIsDefaultBilling` call is covered; verified 9/9.
- 🟡 **Cross-Domain Gate** — local unit+integration+static all green; MFTF MSI/B2B acceptance groups **not** run locally (see §4 recall note and action item #2).
- ✅ **New rule codified — Manual Steps to Reproduce (§1 of CLAUDE.md):** every ticket carries the storefront/API repro steps + preconditions into the pre-fix analysis, the retrospective, and the postmortem (see §0a of this doc).
- ✅ **New rule codified — Scenario-Scoped over Atomic-Per-Bug (§4):** flow-scoped integration test asserting the full invariant set, with the defect as one method/assertion; class named after the flow, method after the case.
- ✅ **New rule codified — Test DocBlock Conventions (§4):** single-sentence, ticket-number-free docblocks — class describes the flow generally, each test method states its scenario+invariant above the mandatory `@return void`, and private test helpers keep a one-line description + `@return`.
- ✅ **Candidate rule promoted:** the "fixture flag vs. storefront default" hygiene (§5) is now captured inside the Manual-Steps-to-Reproduce rule (annotate any precondition the storefront implies but the ticket omits, e.g. a flag that defaults differently in a fixture than in the real request).

## 7. Prioritized Action Plan (status)

| # | Action | Value | Status |
|---|---|---|---|
| 1 | Ship the two-file fix + `NewCustomerOrderTest` (scenario-scoped new-customer checkout flow test; duplication guarded as one invariant among order-placed + single-address + default-billing/shipping). | Resolves ACP2E-5245 | ✅ |
| 2 | Run MFTF checkout + MSI/B2B new-customer acceptance groups in CI before merge (Cross-Domain Gate). | Confirms no cross-domain regression on the shared submit path | ⬜ |
| 3 | Backport candidate assessment (mirrors ACP2E-4997's backport of ACP2E-2923) to active patch lines. | Same symptom family reported across ACSD T3s | ⬜ |
| 4 | Consider carrying `customer_address_id` on `exportCustomerAddress()` (add `to_customer_address` aspect) as a deeper fix — evaluate blast radius first; deferred as too broad for a support fix. | Removes the root "export drops the id" foot-gun | ⬜ |
| 5 | Codify CLAUDE.md rules surfaced by this ticket: Manual-Steps-to-Reproduce (§1), Scenario-Scoped-over-Atomic (§4), Test-DocBlock-Conventions (§4) — the last two subsuming the fixture-flag-vs-DB-default hygiene. | Prevents green-while-buggy tests; standardizes test structure + docs | ✅ |
| 6 | MIG Phase 0 (ACP2E-5180 ledger L15) would mechanically flag `_prepareCustomerQuote` as a high-fan-in submit node whose billing branch ignores an already-stamped id. | Systemic, cross-ticket | ⬜ (tracks ACP2E-5180 L15) |
| 7 | Report the SVC infra bug to the `magento-semver` / SVC pipeline owners: the checker **fatals instead of reporting a verdict** — `ClassMethodOperationUnary::getCode()` returns `null` (undefined array key), which PHP 8.5's strict `: string` return type turns into an uncaught `TypeError` in `HtmlDbSchemaReporter::outputTable()` (exit 255). A tool that crashes on a valid diff blocks unrelated branches; it should degrade gracefully. A signature-stability workaround is applied on this branch to avoid tripping the crash, but the root cause is the tool, not the fix. | Unblocks any branch whose diff hits the same reporter path; not a code defect in this ticket | ⬜ |

---

## 8. Review-cycle corrections (post-v1.0)

Captured for completeness — the changes made after the v1.0 record, during review with the reviewer.

### 8.1 Test structure: atomic → scenario-scoped flow
- **What changed:** the reproduction test evolved `NewCustomerAddressDuplicationTest` → `NewCustomerFirstOrderTest` → **`NewCustomerOrderTest`**, and gained an *order-placed* assertion (now 4 assertions: order placed + exactly one stored address + default shipping == that address + default billing == that address).
- **Why:** a bug-scoped atomic test tends to under-assert (only the scalar the bug touched) — precisely how the §3 default-billing regression could have slipped. A flow-scoped test that asserts the whole invariant set catches that class by construction.
- **Naming rationale:** the class names the *flow* (`NewCustomerOrderTest`); the method names the *case* (`testFirstOrderStoresSingleDefaultAddress`). "First" belongs on the method, not both — otherwise the class name lies about its scope and can't host a future `testRepeatOrder…` method. Class-level `#[DataFixture]`s encode the first-order preconditions; a future case would override them at method scope.

### 8.2 Framework fact corrected
An earlier in-session assertion that augmenting an existing test class is "fragile" (index-merge via `array_replace`) was **wrong** for PHP-8-attribute fixtures. `AbstractDataFixture::_getFixtures()` (lines 61–64) parses **method scope first and only falls back to class scope when the method declares none** → method-level `#[DataFixture]`s **fully replace** class-level ones (clean override). The `array_replace` path governs only legacy `@magentoDataFixture` docblocks. Corrected before it could steer a wrong test-design decision; verified against the parser source.

### 8.3 Docblock conventions applied
The test received minimal, ticket-number-free docblocks per the new convention: a **general class docblock** (the flow, not the bug), a **one-sentence test-method description** above `@return void`, and a **one-line description + `@return`** on the private `createBillingAddress()` data-builder.

### 8.4 CLAUDE.md rules codified from this ticket
Three rules were added so the above becomes standard, not ad-hoc:
1. **§1 Manual Steps to Reproduce** — carry the storefront/API repro steps + preconditions into pre-fix analysis, retrospective, and postmortem; annotate storefront-implied preconditions the ticket omits (subsumes the fixture-flag-vs-DB-default hygiene from §5).
2. **§4 Scenario-Scoped over Atomic-Per-Bug** — flow-scoped integration tests with the invariant set; class-named-after-flow / method-named-after-case; note that method-scope fixtures fully override class-scope ones.
3. **§4 Test DocBlock Conventions** — single-sentence, ticket-free docblocks for class, test methods, and private test helpers.
