# STOP 3 — Green, ACP2E-5597 (dry run)

**Status:** the fix is in the working tree (not staged, not committed). Every method passes under `--filter`, as the STOP 2 table requires.
**Needs your decision:** the whole-class run has one **pre-existing** in-class failure (cart invariant). It is not caused by the fix (see "Green evidence").

Scout freshness: `status: verified`, `gap_files_reindexed: 0`, `derived_views_verified: false`. Caller and graph claims are still provisional.

## The fix (`git -C magento2ee diff`)
```diff
diff --git a/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php b/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php
index 52ac72a9e9..67ea0e5ae9 100644
--- a/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php
+++ b/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php
@@ -198,9 +198,10 @@ class Newsletter extends AbstractCondition
         $customerSelect = $this->getResource()->createSelect();
         $table = $this->getResource()->getTable('customer_entity');
         $customerSelect->from(['root' => $table], ['entity_id']);
+        $customerSelect->where($this->_createCustomerFilter($customer, 'root.entity_id'));
         $select = $this->getConditionsSql($customer, $websiteId);
         $customerSelect->where($select);
-        $matchedParams = $this->matchParameters($select, $params);
+        $matchedParams = $this->matchParameters($customerSelect, $params);
         $result = $this->getResource()->getConnection()->fetchOne($customerSelect, $matchedParams);
         return $result > 0;
     }
```

## Approach and justification
- **What it does:** the outer `customer_entity AS root` query in `isSatisfiedBy()` is now limited to the checked customer, `root.entity_id = :customer_id`.
  - It reuses `_createCustomerFilter()` (`magento2ee/app/code/Magento/CustomerSegment/Model/Condition/AbstractCondition.php:110-113`).
  - It is a primary-key lookup, so it returns at most one row, whatever the number of customers.
- **STOP 2 decision 2 (keep strict):** the query is correlated to the customer. There is no `LIMIT`.
- **Binding:** `matchParameters()` now scans the full outer query (`AbstractCondition.php:202-220`). It binds the same `customer_id` that the subquery already needed. No new parameter is added.
- **"Not subscribed" (value 0):** the meaning is kept, by code reasoning. The `IFNULL(subselect, 1)` expression is unchanged and only filters the customer's own row. **No test covers value 0.**
- **Why not run the subquery directly** (as `Address.php` does): for value 0, `getConditionsSql()` returns a `Zend_Db_Expr`, not a select. That would need a new wrapper for each value. The chosen change handles both values with two lines.
- **Bulk path is unchanged (STOP 2 decision 5):** `getConditionsSql()` is untouched, including its `$isFiltered === false` branches.
  - So `getSatisfiedIds()` → `getConditionsSql(null, $websiteId, false)`, `AbstractCombine::getSatisfiedIds()` and `LinksMatcher` build the same SQL.
  - This is checked by reading the code only. No bulk test was run.
  - There are no new collaborators, no DI changes and no signature changes.
- **Behavior change for unknown ids:** a customer id with no `customer_entity` row now returns false. Before the fix, it could return true just because other customers existed. This is more correct.

## Green evidence, before vs after
| Method | Scenario | Before fix (Stage 2, `--filter`) | After fix (Patch Generator, `--filter`) | Whole class, before fix (Orchestrator) | Whole class, after fix (Orchestrator) |
|---|---|---|---|---|---|
| `testValidateSubscribedCustomerExaminesOnlyThatCustomerRecord` | Defect | FAIL (6 of 6 rows) | **PASS** (4 assertions) | FAIL (6 of 6 rows) | **PASS** |
| `testValidateUnsubscribedCustomerIsNotSatisfied` | Invariant | PASS | PASS (1) | PASS | PASS |
| `testValidateCustomerSubscribedInOtherWebsiteIsNotSatisfied` | Invariant | PASS | PASS (2) | PASS | PASS |
| `testCollectTotalsForSubscribedCustomerAppliesSegmentCartRuleDiscount` | Invariant | PASS | PASS (5) | **FAIL** (`array contains '5'`, line 203) | **FAIL** (`array contains '3'`, line 203) |

- Orchestrator re-ran the cart invariant alone with the fix in place: `OK (1 test, 5 assertions)`.
- The cart invariant fails inside the class both **with and without** the fix, so its failure is pre-existing and does not come from the fix.
  - Stage 2 ran the methods only one at a time, so this was not seen before.
  - The root cause was not investigated. It is probably state carried between tests in the same process. **Unverified.**
- **Stage 2 false-positive note:** the assertion counts show that exactly one captured query touches `customer_entity`, before and after the fix.
  - Before the fix, the failure output showed it was the Newsletter condition query.
  - After the fix it returns at most one row, the checked customer's.
  - The SQL text after the fix was not printed at runtime; its shape is known from the code.
- `php -l Newsletter.php`: no syntax errors.

## Files changed in the repository
- Modified: `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php` (the only production change).
- Unchanged: `NewsletterTest.php`. Its SHA1 `c5773da569ffb640e44722fa83c9ee0c9e609007` matches the Stage 2 baseline.
- CE `git status --short` shows nothing. In `magento2ee`, the other untracked items (`.scout/` and the test directory) were already there before this stage.
- Nothing is staged or committed.

## Not run (Stage 4, Consensus Gateway)
- PHPCS with the Magento2 standard, the static test suite and PHPMD.
  - `Newsletter.php` has no `declare(strict_types=1)`, and the `isSatisfiedBy()` docblock has no summary line. Both were already like this.
- MIG `--diff` and `--mock-impact` on the patch.
- Bulk-path tests (`getSatisfiedIds()` / `LinksMatcher`) and the existing `Model/CustomerTest::testProcessEventCustomerWithSubscription`.
- MFTF tests for `Magento_CustomerSegment` newsletter, and the suites for dependent domains: Cart & Checkout, Order Management & Delivery, B2B Commerce.
- Scout-indexed review of the patch (`review_pr`). Derived views are still unverified.

## Process notes
- Patch Generator said it had no Scout tools. It used one read-only `grep` on `AbstractCondition.php` and labelled it UNINDEXED. The Orchestrator then checked both helpers with Scout (`AbstractCondition.php:110-113`, `202-220`).
- To get a before-fix baseline for the whole class, the Orchestrator briefly reverted the fix in `Newsletter.php` and then put it back. The resulting blob `67ea0e5ae9` is the same as Patch Generator's.

## Decisions requested at STOP 3
1. **Approve Green?** The STOP 2 per-method table is met: Defect FAIL → PASS, and the three Invariants stay PASS.
2. **Whole-class failure of the cart invariant** (pre-existing, also fails before the fix). Choose one:
   - (a) Accept it for now and log it as a known test-isolation defect to fix before merge.
   - (b) Loop back to Test Author to make `NewsletterTest` order-independent. This changes the test, so Red/Green would need to be confirmed again.
   - (c) Investigate the root cause first, with no code changes.
   Stage 4's Consensus Gateway will run the whole class, so (a) means it will report this failure.
3. **Test for "not subscribed" (value 0):** add an invariant test for a "not subscribed" segment condition, since the changed outer query also serves that case? This would be a contract change (a new scenario).
4. **Bulk-path evidence:** is "by inspection" enough for now, or should Stage 4 explicitly run a bulk or `LinksMatcher` test?
5. **Go-ahead for Stage 4** (Consensus Gateway, then Scribe egress).
