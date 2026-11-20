# STOP 1 — Intake gate: ACP2E-5597 (DRY RUN)

**Preflight:** PASS. This session can dispatch custom agents, so it is running as the main-session Orchestrator.
**Branch:** `ACP2E-5597` @ `90f60c1e8ae`.
**Ticket evidence:** Glean Jira snapshot https://jira.corp.adobe.com/browse/ACP2E-5597, indexed update 2026-11-19T11:23:46Z. This is a snapshot, not live state.
- Status: Triage / Unresolved, Major.
- Assignee: Dmytro Yushkin. Reporter: Luke Fenn. Due 2026-11-26.
- Blocks the backport ACP2E-5598 (2.4.8-p5).

**Scout freshness:** `scout-ensure_fresh` returned:
- status=`verified`
- gap_files_reindexed=`0`
- derived_views_verified=`false`
- indexed_through=`90f60c1e8ae`

Tracked files are current. Symbol and vector graphs are not guaranteed current, so treat Scout-derived caller and census claims as provisional.

**Routing:** the named-symbol exception applied (`Newsletter::isSatisfiedBy`). Scribe and the pre-intake Dependency Tracer were dispatched in parallel and synchronously. The Tracer had a budget of 30 calls and 150 lines. Both returned to the Orchestrator.

## Intake brief (Scribe, condensed; full text in 01-scribe.md)

**Scenario:** cart totals are calculated (`POST /rest/V1/carts/mine/totals-information`) for a newsletter-subscribed customer. An active couponless cart rule is conditioned on a segment with "Newsletter Subscription is subscribed". On a cold `customer_segments` cache, `Newsletter::isSatisfiedBy()` returns every `customer_entity` row.

**Preconditions:**
- Customer Segments enabled.
- About 3M customers.
- The customer is subscribed in a store of the segment's website.
- The segment and cart rule are active, and `salesrule_rule` has been reindexed.
- The cart is **non-empty**.
- The `customer_segments` cache has been cleaned.

**Expected:** only the given customer is examined, so cost does not depend on the size of `customer_entity`.

**Actual:** the ticket reports these times at 3M customers:

| Measurement | Time |
|---|---|
| Query as built | 754.5 ms |
| Query with `LIMIT 1` | 2.2 ms |
| Query with `root.entity_id` filter | 0.4 ms |
| Totals calculation | ~1,260 ms |
| Totals calculation, warm cache | ~465 ms |

Local reproduction returned AFF 3,000,001 rows in 346.5 ms inside a 408 ms HTTP 200 request, with SQL logging on.

**Domain:** the primary domain is Customer Management (`Magento_CustomerSegment`). It also touches:
- Merchandising & Promotions (SalesRule)
- Cart & Checkout
- Commerce Web APIs
- Performance

**repro-class:** `single-request`, deterministic and data-volume sensitive. Recommended vehicle: an integration test asserting SQL shape or row count, not wall-clock time. MFTF is not required.

## Pre-intake mechanism (Dependency Tracer, condensed; full text in 02-dependency-tracer.md)

**Mechanism (ticket claim confirmed):**
- The outer `customer_entity AS root` select is created in the `Newsletter::isSatisfiedBy` override: `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php:187-206` (Scout range).
- The customer filter applies only to the inner `newsletter_subscriber` subquery, in the `$isFiltered` branch of `getConditionsSql`. The base `AbstractCondition::isSatisfiedBy` does not create this root select.

**Sibling census:** only Newsletter shows the uncorrelated outer-root + `fetchOne()` pattern.
- Address, Attributes and Storecredit already filter per customer.
- The bulk-scan selects are intentional: `Attributes::getCustomerSelect`, the wishlist `getSatisfiedIds`, and Newsletter's `$isFiltered=false` branch.

**Callers:** SalesRule `Validator::getRules` → AdvancedSalesRule collection plugin → `FilterTextGenerator\Segment` → `CustomerSegmentsProvider` → `Segment::validateCustomer` → Root combine → `Newsletter::isSatisfiedBy`.

**Fix-design risk:** the bulk path (`AbstractCombine::getSatisfiedIds` / `LinksMatcher`) uses `getConditionsSql(null, website, false)`. Keep the correction confined to the per-customer evaluation.

**Tests:** no direct test of `Newsletter::isSatisfiedBy()` was found. The integration test `CustomerTest::testProcessEventCustomerWithSubscription` would not detect this defect.

## Orchestrator synthesis notes (corrections and caveats)

1. **Scribe misread the reviewer comment.** Kateryna Muntianu compared **4 customers vs 3 million**, not "4M vs 3M". The ticket table shows ~510 ms vs ~1,260 ms totals for those sizes.
2. **This is a cost defect; current results are correct.** The inner subquery already filters `main.customer_id = :customer_id`. Current code therefore returns the right true/false result for subscribed, unsubscribed and other-website customers.
   - The Tracer's suggested regression ("unsubscribed customer while another customer is subscribed") would **pass on current code**. It is an invariant guard, not a Red test.
   - The Red test must assert the examined or returned row volume, or the SQL shape. Only Scenario 1/5 of the contract can fail before the fix. Scenarios 2–4 are invariants that should pass both before and after.
3. **The Tracer's remark about outer `LIMIT 1`** ("does not correct which outer rows qualify") does not affect the boolean `fetchOne()` result. Both of the reporter's options remain open for fix design. Neither is adopted here.
4. **Citation format:** the Tracer cited short paths such as `Newsletter.php:193-204` rather than full repo-relative paths. Only the two anchors above were re-confirmed by the Orchestrator through Scout. The Tracer's other ranges are its own Scout results, with `derived_views_verified=false`, and are not independently re-verified.
5. **Not done:** formal MIG Phase 0 was not run (dry run; it writes `var/impact`). It is required after STOP 1 if approved.

## Behavior contract (Scribe, verbatim)

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

## Decisions requested from the human

1. **Approve or amend** the behavior contract above. The contract will be passed unchanged to Test Author and to Scribe at egress.
2. **Scenarios 1 and 5:** merge them, or keep both?
3. **Scope:** fix Newsletter only, as the Tracer's census suggests, or also sweep the other segment conditions?
4. **Scenario 4** (end-to-end discount): keep it as an integration invariant, or treat it as covered by existing coverage?
5. **Go-ahead** for formal MIG Phase 0. This is a real run, not a dry run.

**STOPPED at STOP 1. No further stages run (dry run).**
