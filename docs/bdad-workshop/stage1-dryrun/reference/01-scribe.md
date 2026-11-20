# Intake brief: ACP2E-5597

Source: Glean Jira snapshot (indexed 2026-11-19T11:23:46Z, not live), plus the local triage record and Scout anchors from the handoff. I did not read the code or the retrospective myself. Anchors below are "per Orchestrator handoff".

## Title
Customer segment "Newsletter Subscription" condition reads the whole `customer_entity` table on every check. This slows cart totals for large customer bases.

## Scenario
A registered, newsletter-subscribed customer's cart totals are calculated, and `REST POST /rest/V1/carts/mine/totals-information` is the observed entry point. An active cart price rule is conditioned on a Customer Segment. That segment uses the condition "Newsletter Subscription is subscribed".

Since ACP2E-2926, every active segment is validated on each totals calculation. The Newsletter condition's per-customer check issues an outer `SELECT root.entity_id FROM customer_entity AS root WHERE (<uncorrelated subquery>)` with no customer filter and no LIMIT. For a subscribed customer the subquery is true for every row, so MySQL returns all `customer_entity` rows to PHP, which uses only the first.

## Preconditions
- Clean 2.4-develop CE+EE (reporter used commit 0af2ad9f), PHP 8.4, default mode, indexers on Update by Schedule, Customer Segments enabled.
- `customer_entity` has a very large row count (3M in the report). The website does not matter.
- A customer is subscribed to the newsletter (`newsletter_subscriber.subscriber_status = 1`) in a store of the segment's website.
- An active segment exists for that website, for registered customers, with the condition "Newsletter Subscription is subscribed".
- An active, couponless cart price rule (e.g. 10%) has the condition "Customer Segment is <segment>". `salesrule_rule` has been reindexed. Without that reindex there are 0 newsletter queries.
- The cart is **not empty**. An empty cart is rejected before `collectTotals`, so the path is never reached. The local triage record confirms this.
- The `customer_segments` cache type is cleaned before each measurement. A cache hit hides the cost: ACP2E-4299 caches per customer/website/cart for one hour.

## Steps (from the ticket, verbatim in substance)
1. Create a customer on the default website and subscribe them to the newsletter.
2. Create an active segment for the default website, registered customers, with the condition Newsletter Subscription is subscribed.
3. Create an active couponless cart price rule (e.g. 10%) with the condition Customer Segment is that segment. Reindex `salesrule_rule`.
4. As that customer, add a product to the cart. Clean the `customer_segments` cache type. POST `/rest/V1/carts/mine/totals-information`.

Annotations (implied by the ticket, not stated in it):
- The cart must be non-empty.
- The customer must be subscribed. Unsubscribed costs about 0.1 ms.
- The cache must be cold.

## Expected vs Actual
**Expected:** the condition examines only the given customer, so cost does not depend on the number of customers.

**Actual:** the discount applies, but the full table is read for subscribed customers.

| Customers | Totals time | Query time |
|---|---|---|
| 4 rows | ~510 ms | 0 ms |
| 100k | ~530 ms | 24 ms |
| 1M | ~750 ms | 248 ms |
| 3M | ~1,260 ms | ~745 ms |

- Warm cache at 3M is ~465 ms.
- Direct query at 3M: 754.5 ms as built, 2.2 ms with `LIMIT 1`, 0.4 ms with `root.entity_id = :customer_id`.
- The merchant's 2.9M-row copy shows 381 ms.
- New Relic production: the `customer_entity` select per totals-information goes from 2 ms to 186 ms. For shipping-information it goes from 4 ms to 217 ms.
- Local reproduction (full CE/EE/B2B/MSI, branch ACP2E-5597) returned AFF 3,000,001 rows in 0.3465 s within a 408 ms HTTP 200 request, with SQL and call-stack logging on.

The reporter's suggested fix is a customer filter or `LIMIT 1`. This brief does not adopt it. The reporter also says other conditions with a root select on `customer_entity` may share the pattern. That is unverified.

## Code anchors (per Orchestrator handoff, Scout-returned, not re-read by me)
- `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php:187-206` (method `isSatisfiedBy`, signature at L193).
- `magento2ee/app/code/Magento/CustomerSegment/Model/CustomerSegmentsProvider.php:25-139` (class; `getCustomerSegmentIdsByCustomerIdQuoteId` declared at L80).
- Callers named in the ticket but not Scout-verified: `SalesRule\Model\Validator::initTotals` and the free shipping calculator.

## Domain classification
- **Primary: Customer Management.** Customer groups and segments (`Magento_CustomerSegment`).
- **Touches:**
  - Merchandising & Promotions (`Magento_SalesRule` consumer, Newsletter).
  - Cart & Checkout (totals-information and shipping-information paths).
  - Performance (a query-cost defect).
  - Commerce Web APIs (REST entry point).
- Per the adjacency map for a Customer Management change, the dependent suites are Cart & Checkout, Order Management & Delivery and B2B Commerce. Merchandising & Promotions is also relevant because of SalesRule.

## Repro class
- **`repro-class: single-request`.** It needs no concurrent sessions or stale cross-request state. It is a deterministic single-process behavior.
- It is data-volume sensitive. The ticket's own measurements show about 4 ms to 745 ms across 4 rows to 3M rows. Wall-clock assertions are fragile.
- **Vehicle hint:** assert the structure of the query rather than timing. Capture the SQL, or the rows returned or examined, for the Newsletter check with extra `customer_entity` rows present. It should be independent of the table size. An integration test is feasible with a few extra customers plus a row-count or SQL-shape assertion. MFTF is not required.
- The manual repro needs a large dataset, and ~3M rows were already seeded locally.

## Open questions / evidence gaps
1. Kateryna Muntianu (2026-11-16) could not confirm a performance issue from a 4M vs 3M comparison. The ticket's numbers and the local reproduction contradict this. The comparison probably used an unsubscribed customer, a warm cache, or an unreindexed rule. This needs confirming.
2. The local reproduction ran with SQL and call-stack logging on. There are no logging-off numbers, no warm-cache numbers and no small-data baseline locally.
3. Discount application was not separately confirmed in the local final response.
4. Scope: do other segment conditions that build a root select on `customer_entity` share the pattern? The ticket says "may". Should those be in scope? Recommend a Dependency Tracer sweep, and the human decides at STOP 1.
5. Does the "subscribed in a store of the website" filter (`store_id IN (...)`) need to be preserved exactly? The contract assumes yes.
6. Backports: ACP2E-5598 (2.4.8-p5) blocks on this ticket. Code is identical in 102.1.5-p16 and 102.1.8-p3 per the reporter. Not verified here.
7. The Scout index was `derived_views_verified=false` (indexed through 90f60c1e8ae).

## Behavior contract
```gherkin
Scenario: Defect: Checking the newsletter condition for a subscribed customer examines only that customer
  Given customer segments are enabled and a segment is active for the customer's website
  And   the segment condition is "Newsletter Subscription is subscribed"
  And   many other customers exist on the store besides the one being checked
  And   the checked customer is subscribed to the newsletter in a store of the segment's website
  When  the segment condition is evaluated for that customer
  Then  the condition is satisfied
  And   only that customer's record is examined, not the other customers' records

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

Scenario: Invariant: The amount of customer data examined does not depend on how many customers exist
  Given the same subscribed customer and segment as above
  And   the store has either few customers or very many customers
  When  the segment condition is evaluated for that customer
  Then  the same limited set of records is examined in both cases
  # OPEN: Should this be merged with the Defect scenario? It is kept separate to express scale independence. Merge it if the 5-scenario cap or redundancy is a concern.
```

Notes on the contract:
- The inverse "unsubscribed" check relies on the ticket's own measurement (unsubscribed 0.1 ms) and observed correctness.
- The Then clauses avoid timings and class names.
- Scenarios 1 and 5 overlap. The Orchestrator can collapse them at STOP 1.
- "Unsubscribed" assumes `subscriber_status` values other than 1 all count as not subscribed. This is unverified.

Dry run: I wrote no files and ran no tools beyond reading `domains.md`.
