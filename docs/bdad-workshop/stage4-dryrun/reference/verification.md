# Orchestrator verification — ACP2E-5597 Stage 4 (dry run)

Repo root `/Users/dyushkin/Projects/Adobe/Magento-1`. Branch `ACP2E-5597` (CE, `magento2ee`, `ext/magento/inventory`).
Scout: `scout-ensure_fresh` on magento2ee → `status: verified`, `gap_files_reindexed: 0`, `derived_views_verified: false`
(indexed_through `50f7712c40ce…`); on magento2ce → `status: verified`, `gap_files_reindexed: 0`, `derived_views_verified: false`
(indexed_through `90f60c1e8ae5…`). No Scout source evidence was passed in handoffs this stage.

## V1. Start-of-stage state
- `git -C magento2ee --no-pager diff --stat` → `Newsletter.php | 3 ++-` (Stage 3 fix applied).
- `shasum NewsletterTest.php` → `c5773da569ffb640e44722fa83c9ee0c9e609007` (= Stage 2/3 baseline).

## V2. After Test Author (1-test-author.md)
`git --no-pager diff --no-index NewsletterTest-before.php.txt magento2ee/.../NewsletterTest.php` — identical to the diff Test Author reported:
```diff
@@ -64,6 +64,9 @@ class NewsletterTest extends TestCase
     {
         $this->objectManager = Bootstrap::getObjectManager();
         $this->fixtures = DataFixtureStorageManager::getStorage();
+        // The shared segment model memoizes the active-segment collection per event/website, which would
+        // otherwise carry segments of previously run tests into this one (DbIsolation is disabled).
+        $this->objectManager->get(SegmentCustomer::class)->_resetState();
     }
@@ -213,6 +216,31 @@ class NewsletterTest extends TestCase
+    /**
+     * Scenario: A customer who is not subscribed to the newsletter is checked against a "not subscribed" segment
+     * Expected: The condition is satisfied
+     */
+    #[
+        DataFixture(CustomerFixture::class, as: 'customer'),
+        DataFixture(
+            SegmentFixture::class,
+            [
+                'conditions' => [
+                    '1' => ['type' => Root::class, 'aggregator' => 'all', 'value' => '1', 'new_child' => ''],
+                    '1--1' => ['type' => Newsletter::class, 'value' => '0', 'new_child' => ''],
+                ],
+            ],
+            'segment'
+        ),
+    ]
+    public function testValidateUnsubscribedCustomerSatisfiesNotSubscribedCondition(): void
+    {
+        $customer = $this->fixtures->get('customer');
+        $segment = $this->loadSegment((int)$this->fixtures->get('segment')->getId());
+
+        $this->assertTrue($segment->validateCustomer((int)$customer->getId(), (int)$customer->getWebsiteId()));
+    }
+
```
`git -C magento2ee status --short` → ` M …/Newsletter.php`, `?? .scout/`, `?? dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/`. CE `git status --short` → empty.

## V3. After Patch Generator Red/Green (2-patch-generator.md)
```
$ git -C magento2ee --no-pager diff | diff - docs/bdad-workshop/stage3-dryrun/reference/newsletter.diff && echo DIFF_IDENTICAL
DIFF_IDENTICAL
$ shasum magento2ee/.../NewsletterTest.php
f42fd08a4ed8d87ab5766ad0e23dc342c77b8a7f
$ php -l magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php
No syntax errors detected in magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php
```
(The first command also ran a `git diff > /dev/null` warm-up; no file was written.)

Whole-class run (fix applied, Orchestrator):
```
$ vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$PWD/magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php" --testdox
Time: 00:08.141, Memory: 206.00 MB

Newsletter (Magento\CustomerSegment\Model\Segment\Condition\Customer\Newsletter)
 ✔ Validate subscribed customer examines only that customer record
 ✔ Validate unsubscribed customer is not satisfied
 ✔ Validate customer subscribed in other website is not satisfied
 ✔ Collect totals for subscribed customer applies segment cart rule discount
 ✔ Validate unsubscribed customer satisfies not subscribed condition

OK (5 tests, 13 assertions)
```

## V4. Spot-check of Gateway verdict #1 (3-consensus-gateway.md)
```
$ sed -n 180,196p magento2ee/.../Newsletter.php
    /**
     * @param int $customer
     * @param int $websiteId
     * @param array $params
     * @return bool
     */
    public function isSatisfiedBy($customer, $websiteId, $params)
$ vendor/bin/phpcs --standard=Magento2 magento2ee/.../Newsletter.php | grep -E 'WARNING \||ERROR \||FOUND'
FOUND 0 ERRORS AND 2 WARNINGS AFFECTING 2 LINES
 187 | WARNING | Missing short description
 188 | WARNING | There must be exactly one blank line before tags
$ git -C ext/magento/inventory status --short
 M _metapackage/composer.json
?? .scout/
```
Confirmed: the two warnings are on the unchanged `isSatisfiedBy()` docblock (pre-existing, in a touched file). The inventory
`_metapackage/composer.json` modification was not made by any agent this stage (no agent was allowed to write there).

## V5. After loop-back #1 (4-patch-generator.md)
```
$ git -C magento2ee --no-pager diff
diff --git a/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php b/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php
index 52ac72a9e9..fc1960e05e 100644
--- a/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php
+++ b/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php
@@ -185,6 +185,8 @@ class Newsletter extends AbstractCondition
     }
 
     /**
+     * Check whether the customer satisfies the newsletter subscription condition for the website.
+     *
      * @param int $customer
      * @param int $websiteId
      * @param array $params
@@ -198,9 +200,10 @@ class Newsletter extends AbstractCondition
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
$ vendor/bin/phpcs --standard=Magento2 magento2ee/.../Newsletter.php | grep -cE 'WARNING \||ERROR \|'
0
$ vendor/bin/phpunit … NewsletterTest.php --testdox
 ✔ Validate subscribed customer examines only that customer record
 ✔ Validate unsubscribed customer is not satisfied
 ✔ Validate customer subscribed in other website is not satisfied
 ✔ Collect totals for subscribed customer applies segment cart rule discount
 ✔ Validate unsubscribed customer satisfies not subscribed condition
OK (5 tests, 13 assertions)
```
Note: the production diff no longer equals `stage3-dryrun/reference/newsletter.diff` byte-for-byte (blob is now `fc1960e05e`,
was `67ea0e5ae9`). The two approved fix lines are unchanged; the only addition is the docblock hunk at 185-191.

## V6. Spot-check of Gateway verdict #2 (5-consensus-gateway.md)
```
$ git --no-pager ls-files --others --exclude-standard -- '*.php' | wc -l
      17
```
Confirms the gateway's count of 17 untracked, non-ignored CE PHP files outside `changed_files_local.txt` (impact-graph tool sources/tests
and `docs/retrospectives/ACP2E-5537/AddProductsToCartErrorTest.php`). None are ticket files; none were written this stage.
(`git config --get status.showUntrackedFiles` was denied by the harness, so why CE `git status --short` shows them as empty was not established.)

## Verdict as seen by the Orchestrator
Gateway verdict (final, after 1 loop-back): **NOT-GREEN**. The remaining blockers have no code owner:
(1) static changed-file list coverage vs 17 non-ticket untracked PHP files; (2) dependent-domain suites not executed.
