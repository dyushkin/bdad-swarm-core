# STOP 2 — Red gate: ACP2E-5597 (DRY RUN, real Red test)

**Preflight:** PASS. This session can dispatch custom agents, so it is running as the main-session Orchestrator.
**Branch:** `ACP2E-5597` @ `90f60c1e8ae`.

**Scout freshness** (`scout-ensure_fresh`, Stage 2 start):
- status=`verified`
- gap_files_reindexed=`0`
- derived_views_verified=`false`
- indexed_through=`90f60c1e8ae`

Tracked files are current. Caller and graph claims remain **provisional**. No `setup.sh` was run and no branch was checked out during the run, so no re-check was needed.

**Flow run:** Orchestrator → Dependency Tracer (sync, formal MIG Phase 0) → Orchestrator → Test Author (sync, Red) → Orchestrator (Red verification) → **STOP 2**.

## Approved behavior contract (STOP 1, verbatim)

```gherkin
Scenario: Defect: Checking the newsletter condition for a subscribed customer examines only that customer
  Given customer segments are enabled and a segment is active for the customer's website
  And   the segment condition is "Newsletter Subscription is subscribed"
  And   many other customers exist on the store besides the one being checked
  And   the checked customer is subscribed to the newsletter in a store of the segment's website
  When  the segment condition is evaluated for that customer
  Then  the condition is satisfied
  And   only that customer's record is examined, not the other customers' records
  And   the amount of customer data examined does not depend on how many customers exist

Scenario: Invariant: An unsubscribed customer does not satisfy the newsletter condition
  Given an active segment with the condition "Newsletter Subscription is subscribed"
  And   the customer is not subscribed to the newsletter
  When  the segment condition is evaluated for that customer
  Then  the condition is not satisfied

Scenario: Invariant: A subscription in a store outside the segment's website does not satisfy the condition
  Given an active segment with the condition "Newsletter Subscription is subscribed" for one website
  And   the customer is subscribed only in a store that belongs to a different website
  When  the segment condition is evaluated for that customer
  Then  the condition is not satisfied

Scenario: Invariant: The cart price rule discount still applies to a subscribed customer
  Given an active couponless cart price rule conditioned on a customer segment whose condition is "Newsletter Subscription is subscribed"
  And   the customer is subscribed and has a product in the cart
  When  the cart totals are calculated
  Then  the discount is applied to the cart totals
```

## Phase 0 Blast-Radius Report (Dependency Tracer, condensed; full text in 1-dependency-tracer.md)

**MIG results:**

| Query | Result |
|---|---|
| `--plugins-subject Newsletter` | `[]` |
| `--plugins-method isSatisfiedBy` | `[]` |
| `--mocks Newsletter` | `[]` |
| `--diff HEAD` | `changed: []` (no change yet) |
| `AbstractCondition` / `AbstractCombine` plugins | `[]` |
| `--mftf-module Magento_CustomerSegment` | 29 tests; 5 are newsletter-related |

**Domains:**
- Owning domain: Customer Management (`domains.md:29`).
- Dependents per the adjacency map: Cart & Checkout, Order Management & Delivery, B2B Commerce (`domains.md:82`).
- Observed consumer: Merchandising & Promotions (SalesRule via `CustomerSegmentsProvider`).

**Callers** (provisional): `Segment::validateCustomer` → Root / `AbstractCombine::isSatisfiedBy` → `Newsletter::isSatisfiedBy`. That is reached from `CustomerSegmentsProvider`, `QuoteMatcher`, `Customer::processEvent`, and SalesRule `FilterTextGenerator\Segment`. No direct Newsletter-specific callers were found in CE or B2B.

**Bulk-path guard:** `AbstractCondition::getSatisfiedIds` → `getConditionsSql(null, $websiteId, false)` → `LinksMatcher`. The fix must stay in `isSatisfiedBy()` (`Newsletter.php:187-206`).

**Anti-pattern census:** Newsletter is the only outer-root + `fetchOne` occurrence in `Model/Segment/Condition`.

**Mock surface:** none is expected for an `isSatisfiedBy()`-only fix. The unit `Condition/CustomerTest` mocks Newsletter only for option generation. Run `--mock-impact` once a diff exists.

## Test file changed in the repository

| File | Status |
|---|---|
| `magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php` | **new**, untracked, 256 lines; also reachable via the `dev/tests/integration/testsuite/Magento/CustomerSegment/` symlink |

No other repository files changed. No production code was touched, nothing was staged or committed, and no new `_files/` fixtures were added. `.scout/` was already untracked.

## Method-to-scenario map and Red/Pass per method

| Method | Contract scenario | Result (pre-fix) | Expected after fix |
|---|---|---|---|
| `testValidateSubscribedCustomerExaminesOnlyThatCustomerRecord` | Defect: Checking the newsletter condition for a subscribed customer examines only that customer | **FAIL (Red)**: Test Author's run, then re-confirmed by the Orchestrator | PASS |
| `testValidateUnsubscribedCustomerIsNotSatisfied` | Invariant: An unsubscribed customer does not satisfy the newsletter condition | PASS (1 assertion; Test Author's run) | PASS |
| `testValidateCustomerSubscribedInOtherWebsiteIsNotSatisfied` | Invariant: A subscription in a store outside the segment's website does not satisfy the condition | PASS (2 assertions; Test Author's run) | PASS |
| `testCollectTotalsForSubscribedCustomerAppliesSegmentCartRuleDiscount` | Invariant: The cart price rule discount still applies to a subscribed customer | PASS (5 assertions; Test Author's run) | PASS |

**Commands** (one per method, from the repo root):
```
T="$PWD/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php"
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$T" --filter testValidateSubscribedCustomerExaminesOnlyThatCustomerRecord
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$T" --filter testValidateUnsubscribedCustomerIsNotSatisfied
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$T" --filter testValidateCustomerSubscribedInOtherWebsiteIsNotSatisfied
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$T" --filter testCollectTotalsForSubscribedCustomerAppliesSegmentCartRuleDiscount
```

**Orchestrator Red verification** (full output in red-verification.md):
```
Expected only customer 17's record to be examined, got 6 rows (of 6 customers): [12, 13, 14, 15, 16, 17]
SQL: SELECT `root`.`entity_id` FROM `customer_entity` AS `root` WHERE (SELECT 1 FROM `newsletter_subscriber` AS `main` WHERE (main.customer_id = :customer_id) AND (main.subscriber_status = 1) AND (main.store_id IN ('1')) LIMIT 1)
Tests: 1, Assertions: 4, Failures: 1.   (failure at NewsletterTest.php:106 — the row-volume assertion; assertions 1–3 passed)
```

The Defect test fails for the right reason. The outer `customer_entity AS root` query has no customer filter, so it returns every row, matching `Newsletter.php:198-204`. No timing assertion is involved.

## Orchestrator synthesis notes and caveats

1. **How the Red test works.** It turns on the DB profiler around the real `Segment::validateCustomer()` call. It then re-runs each captured query that touches `customer_entity` with the same bound parameters, and asserts that at most 1 row comes back and that it contains no other customer's ID.
   - The contract's "examined" is measured by **rows returned**, not by an `EXPLAIN` scan plan.
   - Scale independence is shown with a single data set: 5 other customers plus the checked one. There is no second data set of a different size.
2. **The test is strict.** As Test Author flagged, a fix that only adds `LIMIT 1` would still FAIL, because it returns the lowest `entity_id` and that is another customer. The test therefore requires the outer query to filter on the checked customer, or to drop `customer_entity` altogether.
   - This follows the approved Then clause, "only that customer's record is examined".
   - It narrows the STOP 1 note that left both of the reporter's options open.
3. **Possible false positive after the fix (low risk).** The check covers any captured query whose SQL contains `customer_entity`. If the fixed validation path also runs an unrelated `customer_entity` query that returns several rows, the test would flag it. In this run only the root query was captured. Re-check this at Green.
4. **Isolation.** The class uses `#[DbIsolation(false)]`, which the store fixture requires, and relies on fixture rollbacks.
   - Cleanup was observed: ids moved from 3–8 to 12–17, and the customer count stayed at 6.
   - The other-website test creates a website, store group and store. Its rollback was not separately inspected.
5. **Scout was unavailable to Test Author.** Its code reads are labelled UNINDEXED. Fixture classes were checked only by running the tests, which loaded and executed them successfully.
6. **Side observation (out of scope).** Test Author reports that `OrdersnumberTest` uses `as: 'x{1..3}'`, which may create fewer fixtures than intended. This is not verified and is not in scope.
7. **Not run:**
   - The Cross-Domain Verification Gate (Cart & Checkout, Order Management & Delivery, and B2B suites).
   - PHPCS and static checks.
   - `--mock-impact`, because no diff exists yet.

   These belong to Green and Consensus.

## Decisions requested from the human

1. **Approve Red.** Accept `NewsletterTest` as the failing test for the Defect scenario and the three Invariants as pre-fix guards.
2. **Strictness.** Accept that the Defect assertion requires per-customer correlation (or removing the `customer_entity` read), which rules out a `LIMIT 1`-only fix. The alternative is to relax it to "at most 1 row".
3. **Measure of "examined".** Is "rows returned" enough, or should Test Author add an `EXPLAIN` check (no full scan of `customer_entity`)?
4. **Test placement.** Accept the new class at `magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php`, or move the tests into `Model/CustomerTest.php`, where the Tracer recommended placing them beside the existing newsletter test.
5. **Go-ahead for Stage 3.** Approve dispatching Patch Generator to implement the fix inside `Newsletter::isSatisfiedBy()` only, leaving `getConditionsSql()` unchanged.

**STOPPED at STOP 2. No further stages run (dry run).**
