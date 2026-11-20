# STOP 3 decisions — ACP2E-5597 (human, 2026-11-19)

| # | Decision requested at STOP 3 | Human decision |
|---|---|---|
| 1 | Approve Green | **Approved.** The Stage 3 fix in `Newsletter.php` (two lines: correlate the outer `customer_entity` select to the checked customer; pass `$customerSelect` to `matchParameters()`) is accepted. |
| 2 | Cart invariant fails when the whole class runs (line 203; fails with and without the fix) | **(b) Loop back to Test Author.** Make `NewsletterTest` order-independent so the whole class passes in one process. The test changes, so Red and Green must be confirmed again on the new test. |
| 3 | Add a "not subscribed" (`value = 0`) Invariant | **Yes.** This is a contract change; the contract now has **5 scenarios** (Scenario 5 below). |
| 4 | Bulk path coverage | **Run** in Stage 4: `SegmentMatchConsumerTest`, `SegmentMatchConsumerWithoutRealTimeCustomerSegmentValidationTest`, `Model/CustomerTest`. |
| 5 | Consensus Gateway model | **`gpt-6.1-sol`**, pinned in the profile, so the gate is checked by a different provider. |
| 6 | Who re-confirms Red after the test changes | **Patch Generator.** It undoes the fix with its edit tool, runs the whole class (expects Red), restores the fix exactly, then runs the whole class again (expects Green). |
| 7 | Scribe egress location | **Dry-run artifact directory only.** `docs/retrospectives/ACP2E-5597/retrospective.md` stays untouched. |
| 8 | Go-ahead | **Proceed to Stage 4** (STEP 5 Consensus + STEP 6 egress), ending at STOP 4. |

Gateway scope decisions (before Stage 4):
- **MFTF §C: skip.** No `Test/Mftf/*.xml` changed, so the Gateway records §C as "not applicable".
- **Static suite §B: run.** The harness writes the changed-file list (`changed_files_local.txt`) from the multi-repo diff
  before the run, because the CE-root `git diff` fallback cannot see magento2ee changes.

## Behavior contract (approved, 5 scenarios)

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

Scenario: Invariant: An unsubscribed customer satisfies the "not subscribed" newsletter condition
  Given an active segment with the condition "Newsletter Subscription is not subscribed"
  And   the customer is not subscribed to the newsletter
  When  the segment condition is evaluated for that customer
  Then  the condition is satisfied
```

Scenario 5 covers the `value = 0` path: `getConditionsSql()` returns an `IFNULL(...)` expression. It must pass both
before and after the fix.

## Files in scope

| File | State at start of Stage 4 | Who may change it |
|---|---|---|
| `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php` | Stage 3 fix applied (uncommitted) | Patch Generator only (Red/Green re-confirmation; Gateway loop-back) |
| `magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php` | Approved Red test (untracked), 4 methods | Test Author only |

## Required results

| Method | Scenario | Fix undone (whole class) | Fix applied (whole class) |
|---|---|---|---|
| `testValidateSubscribedCustomerExaminesOnlyThatCustomerRecord` | Defect | FAIL | PASS |
| `testValidateUnsubscribedCustomerIsNotSatisfied` | Invariant | PASS | PASS |
| `testValidateCustomerSubscribedInOtherWebsiteIsNotSatisfied` | Invariant | PASS | PASS |
| `testCollectTotalsForSubscribedCustomerAppliesSegmentCartRuleDiscount` | Invariant | PASS | PASS |
| *(new, Scenario 5)* | Invariant | PASS | PASS |
