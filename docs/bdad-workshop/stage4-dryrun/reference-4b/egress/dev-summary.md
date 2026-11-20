# ACP2E-5597 — Developer Summary

## Problem
The "Newsletter Subscription" customer segment condition checks one customer at a time in `isSatisfiedBy()`. That check issued `SELECT root.entity_id FROM customer_entity AS root WHERE (<uncorrelated subquery>)` with no customer filter. For a subscribed customer, MySQL returned every `customer_entity` row (3M in the report). This happened on each cart totals calculation, for example `POST /rest/V1/carts/mine/totals-information`. It was triggered by an active segment-conditioned cart price rule plus ACP2E-2926, which forces segment validation on each totals call.

## Root cause
The outer select on `customer_entity` was never restricted to the customer being checked. In addition, `matchParameters()` was given the condition sub-select instead of the full outer select.

## Fix
`magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php`, `isSatisfiedBy()` only:
- Added `$customerSelect->where($this->_createCustomerFilter($customer, 'root.entity_id'))`, so the outer query is limited to that customer.
- `matchParameters()` now receives `$customerSelect`.
- Added a short docblock summary (it fixes the PHPCS/static `LiveCodeTest::testCodeStyle` warnings).

`getConditionsSql()` is untouched, so the bulk path (`getSatisfiedIds()` / `LinksMatcher`) builds the same SQL as before.

## Tests
New `NewsletterTest` (CustomerSegment integration, 5 methods, one per behavior-contract scenario):
- Defect: a subscribed customer is satisfied and only that customer's row is returned (Red before the fix: failed 6 of 6 rows; Green after).
- Invariants: unsubscribed is not satisfied; a subscription in another website is not satisfied; the cart rule discount still applies; unsubscribed satisfies "not subscribed".
- `setUp()` resets the memoized active-segment state, so the class is order-independent.

## Validation (Consensus Gateway: GREEN with deferrals)
- `php -l` and `phpcs --standard=Magento2`: pass. Static suite: pass (6 tests, 1 skipped).
- MIG `--diff HEAD`: one changed class, no plugin/dispatch/observer fan-out. `--mock-impact`: empty.
- `SegmentMatchConsumerTest` and `...WithoutRealTimeCustomerSegmentValidationTest`: pass (6 tests each). `Model/CustomerTest`: pass (3 tests). `NewsletterTest`: pass (5 tests, 13 assertions).
- Integration total: 20 tests, 94 assertions.

## Behavior change to note
A customer id with no `customer_entity` row now evaluates the Newsletter condition to false. Before the fix it could be true just because other customers existed.

## Deferrals
- Cart & Checkout, Order Management & Delivery and B2B Commerce suites were not run locally. Deferred to CI at STOP 4.
- MFTF is N/A (no MFTF files changed).
- Red was not re-run after the docblock-only change.
- The cause of the earlier test order-dependence was not bisected. The `_resetState()` isolation was accepted as is.
