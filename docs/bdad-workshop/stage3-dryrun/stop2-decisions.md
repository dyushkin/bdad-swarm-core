# STOP 2 decisions — ACP2E-5597 (human, 2026-11-19)

| # | Decision requested at STOP 2 | Human decision |
|---|---|---|
| 1 | Approve Red | **Approved.** `NewsletterTest` is the failing test for the Defect scenario; the three Invariant tests are pre-fix guards. |
| 2 | Strictness of the Defect assertion | **Keep strict.** The outer query must be correlated to the checked customer (or must not read `customer_entity` at all). A `LIMIT 1`-only fix is not acceptable. |
| 3 | Measure of "examined" | **Rows returned**, as written. No `EXPLAIN` check. |
| 4 | Test placement | **Keep** the new class `magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php`. |
| 5 | Fix scope | **Patch Generator decides within `Newsletter.php`.** Its choice must keep the bulk path (`getSatisfiedIds()` → `getConditionsSql(null, $websiteId, false)`) behaving the same, and it must justify the choice. |

The test file must not be changed during Green. If Patch Generator believes the test is wrong, it reports this and
stops; it does not edit the test.

## Test file (Red, approved)

`magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php`
(also reachable through the CE symlink `dev/tests/integration/testsuite/Magento/CustomerSegment/`).

| Method | Scenario | Pre-fix | Required after fix |
|---|---|---|---|
| `testValidateSubscribedCustomerExaminesOnlyThatCustomerRecord` | Defect | FAIL | PASS |
| `testValidateUnsubscribedCustomerIsNotSatisfied` | Invariant | PASS | PASS |
| `testValidateCustomerSubscribedInOtherWebsiteIsNotSatisfied` | Invariant | PASS | PASS |
| `testCollectTotalsForSubscribedCustomerAppliesSegmentCartRuleDiscount` | Invariant | PASS | PASS |

## Carried-over notes from STOP 2

- Possible false positive (Stage 2 note 3): the Defect check covers any captured query that touches `customer_entity`.
  At Green, confirm that only the fixed condition query is captured.
- Production file in scope: `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php`
  (`isSatisfiedBy()` at lines 187-206; `getConditionsSql()` feeds the bulk path).
- Out of scope for Stage 3 (belongs to Stage 4 / Consensus Gateway): PHPCS Magento2 standard, static suite,
  `--diff` / `--mock-impact`, dependent-domain MFTF suites.
