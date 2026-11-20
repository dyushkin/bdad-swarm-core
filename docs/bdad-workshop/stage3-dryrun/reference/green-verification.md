# Green verification (Orchestrator), ACP2E-5597

Run independently by the Orchestrator after Patch Generator returned. Repo root `/Users/dyushkin/Projects/Adobe/Magento-1`,
branch `ACP2E-5597` (CE and `magento2ee`), HEAD `90f60c1e8ae`.
Scout freshness: `status: verified`, `gap_files_reindexed: 0`, `derived_views_verified: false`.

## 1. Lint
```
$ php -l magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php
No syntax errors detected in magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php
```
(Run twice: once after Patch Generator returned, and again after the fix was re-applied, see section 4.)

## 2. Diff review
```
$ git -C magento2ee --no-pager diff
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
Review findings:
- The change is confined to `isSatisfiedBy()`. `getConditionsSql()` (including the `$isFiltered === false` bulk branches) is untouched,
  so `getSatisfiedIds()` → `getConditionsSql(null, $websiteId, false)` and `LinksMatcher` build the same SQL as before (by inspection).
- Scout-verified helpers:
  - `_createCustomerFilter()` returns `"{$fieldName} = :customer_id"` when `$customer` is truthy
    (`magento2ee/app/code/Magento/CustomerSegment/Model/Condition/AbstractCondition.php:110-113`). `isSatisfiedBy()` returns early
    on a falsy `$customer`, so the new outer predicate is always `root.entity_id = :customer_id`. This is a correlation to the
    checked customer, not a `LIMIT`-only fix (STOP 2 decision 2).
  - `matchParameters()` only string-searches the SQL for `:customer_id`, `:website_id`, `:quote_id`, `:visitor_id`
    (`AbstractCondition.php:202-220`). Running it on the outer select binds the same `customer_id` the subquery already
    required; no new parameter is introduced.

## 3. Repository state
```
$ git -C magento2ee status --short
 M app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php
?? .scout/
?? dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/
$ git status --short          # CE root
(empty)
$ shasum magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php
c5773da569ffb640e44722fa83c9ee0c9e609007  ...NewsletterTest.php
```
- Only `Newsletter.php` is modified in production code. Nothing staged or committed.
- Test file SHA1 `c5773da5…0007` equals the Stage 2 baseline in `test-sha-before.txt`: **test unchanged**.

## 4. Whole-class run (no `--filter`)
Command:
```
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$PWD/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php" --testdox
```

### 4a. With the fix (relevant output, verbatim)
```
...F                                                                4 / 4 (100%)

Time: 00:07.505, Memory: 163.00 MB

Newsletter (Magento\CustomerSegment\Model\Segment\Condition\Customer\Newsletter)
 ✔ Validate subscribed customer examines only that customer record
 ✔ Validate unsubscribed customer is not satisfied
 ✔ Validate customer subscribed in other website is not satisfied
 ✘ Collect totals for subscribed customer applies segment cart rule discount
   │
   │ Failed asserting that an array contains '3'.
   │
   │ /Users/dyushkin/Projects/Adobe/Magento-1/magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php:203
   │ /Users/dyushkin/Projects/Adobe/Magento-1/vendor/bin/phpunit:122
   │

FAILURES!
Tests: 4, Assertions: 8, Failures: 1.
```
The cart-rule invariant fails at `NewsletterTest.php:203` (`assertContains(ruleId, appliedRuleIds)`) when run inside the class,
although Patch Generator reported it passing under `--filter`.

### 4b. Diagnosis: the cart invariant alone, with the fix
```
$ vendor/bin/phpunit ... "$T" --filter testCollectTotalsForSubscribedCustomerAppliesSegmentCartRuleDiscount
.                                                                   1 / 1 (100%)
OK (1 test, 5 assertions)
```

### 4c. Diagnosis: whole class with the fix temporarily reverted (pre-fix baseline)
The fix was reverted with the edit tool in `Newsletter.php` (the only allowed write); `git -C magento2ee diff --stat` was empty before the run.
```
F..F                                                                4 / 4 (100%)

Time: 00:06.820, Memory: 163.00 MB

Newsletter (Magento\CustomerSegment\Model\Segment\Condition\Customer\Newsletter)
 ✘ Validate subscribed customer examines only that customer record
   │
   │ Expected only customer 42's record to be examined, got 6 rows (of 6 customers): [37, 38, 39, 40, 41, 42]
   │ SQL: SELECT `root`.`entity_id` FROM `customer_entity` AS `root` WHERE (SELECT 1 FROM `newsletter_subscriber` AS `main` WHERE (main.customer_id = :customer_id) AND (main.subscriber_status = 1) AND (main.store_id IN ('1')) LIMIT 1)
   │ Failed asserting that false is true.
   │
   │ .../NewsletterTest.php:106
   │
 ✔ Validate unsubscribed customer is not satisfied
 ✔ Validate customer subscribed in other website is not satisfied
 ✘ Collect totals for subscribed customer applies segment cart rule discount
   │
   │ Failed asserting that an array contains '5'.
   │
   │ .../NewsletterTest.php:203
   │

FAILURES!
Tests: 4, Assertions: 8, Failures: 2.
```
The fix was then re-applied with the edit tool. `git -C magento2ee diff` shows the same blob `index 52ac72a9e9..67ea0e5ae9` as
Patch Generator's diff, and `php -l` is clean.

**Conclusion:** the in-class failure of the cart invariant is **pre-existing and independent of the fix**: it fails in-class
both before and after the fix, and passes in isolation. It is a test-isolation / test-order problem in `NewsletterTest`
(Stage 2 only ever ran the methods one at a time). The root cause was not investigated; it is likely state carried between
tests in the same PHP process (for example, cached segment or rule data), but that is **unverified**.
The whole-class run was not re-run after the re-application; the per-method results and the identical blob stand.

## 5. Stage 2 false-positive note (only the fixed condition query touches `customer_entity`)
- The Defect test makes 3 fixed assertions plus 1 per captured query whose SQL contains `customer_entity`.
- Whole-class assertion totals: with fix, 8 = Defect 4 + Unsubscribed 1 + Other website 2 + Cart 1 (failed at its first assertion).
  Pre-fix, also 8 with the same split (Defect failed on its 4th assertion). Patch Generator's isolated run also reported Defect `OK (1 test, 4 assertions)`.
- So exactly **one** captured query touches `customer_entity`, before and after the fix. Pre-fix, the failure output shows it is
  the Newsletter condition query. After the fix it passes (≤ 1 row, no foreign ids).
- Limitation: the post-fix SQL text was not printed at runtime (the test prints SQL only on failure, and no extra php commands are allowed).
  The expected shape, from code, is
  `SELECT root.entity_id FROM customer_entity AS root WHERE (root.entity_id = :customer_id) AND ((SELECT 1 FROM newsletter_subscriber AS main WHERE ... LIMIT 1))`.

## 6. Verdict
**GREEN per the STOP 2 method table** (each method run with `--filter`): Defect FAIL → PASS; three Invariants PASS → PASS.
**Whole-class run is not green** because of a pre-existing in-class failure of the cart invariant, unrelated to the fix. This is raised at STOP 3.
