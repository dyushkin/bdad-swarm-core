# ACP2E-5597: Red test report

The Defect test fails for the right reason and all three Invariant tests pass. The Defect run shows `isSatisfiedBy()` reading every row of `customer_entity` when it checks one subscribed customer. No production code was changed, and nothing was staged or committed.

Scout tools were not available in this session, so I searched the code with read-only native reads (marked **UNINDEXED**).

## 1. Files created
- `magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php` (new). It is also reachable through the symlink at `dev/tests/integration/testsuite/Magento/CustomerSegment/...`.
- I also created the new directory `.../Model/Segment/Condition/Customer/` with `mkdir`. I didn't need any new `_files/` fixtures.

**Existing tests checked:**
- **MFTF:** I didn't search the MFTF layer. STOP 1 set repro-class to single-request, so MFTF isn't required.
- **Integration:** `Model/CustomerTest::testProcessEventCustomerWithSubscription` already builds a Newsletter condition. I reused its fixture format (`SegmentWithComplexConditions` with `loadPost`-style conditions) but did not extend that class. It covers event-driven segment matching through `processEvent`, while these tests cover evaluating the newsletter condition. The new class sits next to the other condition tests (`Segment/Condition/Sales/OrdersnumberTest`, `.../Combine/RootTest`) and uses the same namespace convention.

## 2. Method-to-scenario map
| Method | Contract scenario |
|---|---|
| `testValidateSubscribedCustomerExaminesOnlyThatCustomerRecord` | Defect: Checking the newsletter condition for a subscribed customer examines only that customer |
| `testValidateUnsubscribedCustomerIsNotSatisfied` | Invariant: An unsubscribed customer does not satisfy the newsletter condition |
| `testValidateCustomerSubscribedInOtherWebsiteIsNotSatisfied` | Invariant: A subscription in a store outside the segment's website does not satisfy the condition |
| `testCollectTotalsForSubscribedCustomerAppliesSegmentCartRuleDiscount` | Invariant: The cart price rule discount still applies to a subscribed customer |

## 3. Commands run (one per method)
```
T="$PWD/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php"
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$T" --filter testValidateSubscribedCustomerExaminesOnlyThatCustomerRecord
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$T" --filter testValidateUnsubscribedCustomerIsNotSatisfied
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$T" --filter testValidateCustomerSubscribedInOtherWebsiteIsNotSatisfied
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$T" --filter testCollectTotalsForSubscribedCustomerAppliesSegmentCartRuleDiscount
```

## 4. Results
| Method | Result |
|---|---|
| Defect | **FAIL (expected Red)**, 4 assertions; the first 3 passed (returned true, queries captured, more than 5 customers) |
| Unsubscribed | PASS (1 assertion) |
| Other website | PASS (2 assertions) |
| Cart rule discount | PASS (5 assertions) |

Defect failure excerpt:
```
Expected only customer 8's record to be examined, got 6 rows (of 6 customers): [3, 4, 5, 6, 7, 8]
SQL: SELECT `root`.`entity_id` FROM `customer_entity` AS `root` WHERE (SELECT 1 FROM `newsletter_subscriber`
AS `main` WHERE (main.customer_id = :customer_id) AND (main.subscriber_status = 1) AND (main.store_id IN ('1'))
LIMIT 1)
```
The outer query has no customer filter, so it returns the whole table. This is not a setup error: `validateCustomer` returned `true` and the fixtures loaded.

**Earlier failed run:** I first wrote `as: 'other{1..5}'`, which created only one extra customer. That run failed my check of "more than 5 customers in the table". I switched to the framework's real syntax, `as: 'other', count: 5`. Note that `OrdersnumberTest` uses the same `{1..3}` form, so it may be silently creating fewer fixtures than intended. That is outside this ticket's scope.

**Cleanup:** The Defect run found 6 customers (ids 3–8), so the 2 customers from the earlier run had been rolled back.

## How the tests work
- **Defect test:**
  - It turns on the default connection's DB profiler around the real `Segment::validateCustomer()` call, which builds params the same way production does.
  - It re-runs every captured query that touches `customer_entity` with the same bound params.
  - It then asserts the query returns at most 1 row and no customer id other than the one being checked.
  - A guard assertion (`assertNotEmpty($queries)`) makes the test fail if no queries are captured. There is no wall-clock timing anywhere.
- **Subscriptions:** `CustomerFixture` with `extension_attributes.is_subscribed = true` subscribes the customer through the Newsletter plugin, in the current store (store 1). When the fixture deletes the customer, the plugin removes the subscription too.
- **Other-website test:** It creates website2, a store group and a store. The segment is scoped to website2 and the customer is subscribed only in store 1 (website 1).
- **Cart rule test:**
  - It uses a real segment, a couponless `RuleFixture` (20% off, `Condition\Segment == $segment.id$`), a subscribed customer, a $100 product and a customer cart.
  - It calls `SegmentCustomer::processEvent('customer_save_commit_after', …)`. With the real-time check at its default of 1, this goes through `validateCustomer` → `Newsletter::isSatisfiedBy`.
  - It asserts the rule id is in the applied rules, subtotal is 100, discount is −20, and **subtotal + discount + shipping + tax = grand total**, and that the quote and address grand totals match.
- **Self-cleaning:** The class uses `#[DbIsolation(false)]` because the store fixture needs it. Every fixture is revertible: deleting the customer removes the subscription, deleting the segment cascades its matched-customer rows, and the rule and cart are reverted. The only test-body write is the `processEvent` call, which is the system under test and is removed by that cascade.

## 5. Deviations, assumptions and open questions for STOP 2
1. **Test placement:** The ticket's scope required the legacy `dev/tests/integration/testsuite` tree, which overrides the usual module-local `Test/Integration` placement. I named the class after its flow, matching the sibling condition tests.
2. **A LIMIT-1-only fix would still fail this test.** That fix returns the first `customer_entity` row, and the checked customer is created after the 5 others, so its id is never the lowest. The test therefore requires the outer query to filter on the checked customer, or to not read `customer_entity` at all. This matches "only that customer's record is examined". Please confirm STOP 2 accepts this strictness.
3. **Fix-agnostic by design:** If the fix drops `customer_entity` from the query entirely (for example, a direct read of `newsletter_subscriber` only), the per-query loop has nothing to check. The test then passes on `true` plus the non-empty-queries guard.
4. **Returned rows, not an EXPLAIN plan:** "Examined" is measured by the rows the query returns, not by the database's EXPLAIN output, because EXPLAIN results vary by MariaDB version and table statistics. If STOP 2 wants a stronger proof of what is scanned, an EXPLAIN check (no full-table or full-index scan on `customer_entity`) could be added.
5. **Bulk path left alone:** `getConditionsSql(null, website, false)` (used by `getSatisfiedIds` and `LinksMatcher`) is not exercised, per the STOP 1 notes.
6. **Line length:** I checked the 120-character limit by eye only; the `awk` length check was blocked by a permission prompt. The longest lines are about 118 characters.
7. **Not run:** the Cross-Domain Verification Gate. The SalesRule-to-segment discount path is covered by the cart rule test; no MSI or B2B suites were run.

| criterion | status | strongest exact evidence | confidence | gap |
|---|---|---|---|---|
| Defect test is Red for the right reason | met | `got 6 rows (of 6 customers): [3..8]`, outer `customer_entity` SQL has no filter | high | none |
| 3 Invariant tests are Green | met | OK 1 / 2 / 5 assertions | high | not yet re-run after the fix |
| Self-cleaning, no production changes | met | git status shows only the new test dir; customer ids reused cleanly across runs | high | `.scout/` was already untracked, not mine |
