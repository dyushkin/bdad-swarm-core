# Retrospective — ACP2E-5597 (BDAD Swarm Stage 4b, DRY RUN)

Final verdict: Consensus Gateway **GREEN with deferrals**. Written to the dry-run egress directory only (not `docs/retrospectives/`).

## Summary
The "Newsletter Subscription" segment condition's `isSatisfiedBy()` issued an outer `SELECT root.entity_id FROM customer_entity AS root WHERE (<uncorrelated subquery>)` with no customer filter. A subscribed customer therefore returned every `customer_entity` row (3M in the report) on each cart totals call. The fix adds the customer filter to the outer select and passes the full select to `matchParameters()`. File: `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php`.

Domain: Customer Segments (primary); touches Cart Price Rules (sales rule) and Quote/Cart totals. Repro class: single-session.

## Manual Steps to Reproduce
Preconditions:
1. A customer exists and is subscribed to the newsletter in a store of the website under test. Many other customers exist (the large table is what makes the defect visible).
2. Customer segments are enabled. A segment is active for that website with the condition "Newsletter Subscription is subscribed".
3. A couponless cart price rule is conditioned on that segment and is active.

Steps:
1. Reindex `salesrule_rule`.
2. As the subscribed customer, add a product to the cart (non-empty cart).
3. Clean the `customer_segments` cache.
4. Send `POST /rest/V1/carts/mine/totals-information` (with the customer token).
5. Observe the SQL issued by the segment check, `SELECT root.entity_id FROM customer_entity AS root WHERE (...)`.

Expected vs. actual:
- Before the fix: the outer query returns all `customer_entity` rows.
- After the fix: it returns at most one row (the checked customer), and the discount is still applied.

## Behavior contract (approved at STOP 1, amended at STOP 3)
STOP 1 approved 4 scenarios (the original Scenario 5 was merged into the Defect). STOP 3 added the "not subscribed" invariant, giving 5.

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

## Timeline / stages
1. **Stage 1 (intake):** Ticket normalized from the Glean snapshot (indexed 2026-11-19T11:23:46Z). Contract drafted.
2. **STOP 1:** Contract approved with 4 scenarios. Scope is the Newsletter condition only. The end-to-end discount invariant is kept. Go-ahead for MIG Phase 0.
3. **Stage 2/3 (Red):** New `NewsletterTest`. **STOP 2:** Red approved. The Defect assertion stays strict (a `LIMIT 1`-only fix is not acceptable). "Examined" means rows returned, with no EXPLAIN check. Test placement kept. The bulk path must stay the same.
4. **Stage 3 (Green):** Two-line production fix. Defect test Red (failed 6 of 6 rows) → Green. **STOP 3:** Green approved.
   - **Loop-back (STOP 3):** Test Author to make the test class order-independent (`SegmentCustomer::_resetState()` in `setUp()`, because DbIsolation is disabled) and to add Scenario 5. Bulk-path tests to run in Stage 4. Gateway model pinned to gpt-6.1-sol. Patch Generator re-confirmed Red/Green.
5. **Stage 4 (Gateway):** NOT-GREEN after 1 loop-back.
   - **Loop-back #1 (docblock):** a docblock hunk was added to `isSatisfiedBy()` to fix PHPCS/static `LiveCodeTest::testCodeStyle` warnings at lines 187/188.
   - Remaining blockers: §B list coverage (17 untracked non-ticket CE PHP files) and dependent-domain suites not run.
6. **STOP 4:** (1) Cart & Checkout, Order Management & Delivery and B2B Commerce suites deferred to CI; local evidence accepted. (2) The 17 untracked CE files are outside the ticket; §B is judged against the declared change set (profile v2.24). (3) Docblock hunk approved. (4) `_resetState()` isolation accepted as is, with no bisect. (5) Re-run Gateway, then egress on green.
7. **Stage 4b:** Gateway re-run → GREEN with deferrals, no loop-backs. Egress (this step).

## Validation results
| Check | Status |
|---|---|
| `php -l` and `phpcs --standard=Magento2` (Newsletter.php, NewsletterTest.php) | PASS |
| §B coverage vs. declared change set | PASS |
| Static suite (`dev/tests/static/phpunit.php.xml`) | PASS (6 tests, 5 assertions, 1 skipped) |
| MIG `--diff HEAD` / `--mock-impact HEAD` | PASS (one changed class, plugins/dispatches/observes `[]`; mock_impact `[]`) |
| `SegmentMatchConsumerTest` | PASS (6 tests, 34 assertions) |
| `SegmentMatchConsumerWithoutRealTimeCustomerSegmentValidationTest` | PASS (6 tests, 34 assertions) |
| `Model/CustomerTest` | PASS (3 tests, 13 assertions) |
| `NewsletterTest` | PASS (5 tests, 13 assertions) |
| Bulk SQL preservation (`getConditionsSql()` untouched) | PASS |

Integration total: 20 tests, 94 assertions. The Orchestrator spot-check re-ran phpcs (0 findings) and MIG `--diff HEAD`; the FQCN matches the Phase 0 subject.

## Behavior change
A customer id with no `customer_entity` row now makes the condition false (it could previously be true because other customers existed). The bulk path (`getSatisfiedIds()` / `LinksMatcher`) is unchanged.

## Not run locally
- **Cart & Checkout suite** — DEFERRED (human, STOP 4), to CI.
- **Order Management & Delivery suite** — DEFERRED (human, STOP 4), to CI.
- **B2B Commerce suite** — DEFERRED (human, STOP 4), to CI.
- **MFTF §C** — N/A (no `Test/Mftf/*.xml` changed; scope decision).
- **No bisect** of the cause of the test order-dependence; `_resetState()` isolation accepted as is.
- **Red not re-run** after the docblock-only change.
- **PHPMD** not run.
- **Scout `review_pr`** not run.

## Usage
Pending session end. The authoritative source is `/Users/dyushkin/bdad-dryrun/ACP2E-5597-stage4b-20261119-221546/usage.json` (`agentMetrics`). No token or usage figures are estimated here. Per-dispatch usage the runtime did not expose to the Orchestrator is **unknown**.

## Lessons / follow-ups
- A correlated customer filter belongs in every per-customer segment condition. Other conditions may be worth auditing (not done; out of scope).
- Order-dependence from memoized segment state was masked rather than root-caused.
- Reviewers should expect the 2-line production change plus the docblock hunk, and one new test class.
