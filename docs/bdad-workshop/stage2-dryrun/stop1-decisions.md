# STOP 1 decisions (human, approved)

Stage 1 artifacts: `docs/bdad-workshop/stage1-dryrun/reference/` (`stop1.md`, `01-scribe.md`, `02-dependency-tracer.md`).

| # | Question at STOP 1 | Decision |
|---|---|---|
| 1 | Approve or amend the behavior contract | **Approved with one amendment:** Scenario 5 is merged into the Defect scenario (4 scenarios in total). |
| 2 | Merge Scenarios 1 and 5? | **Merged** (see contract below). |
| 3 | Scope | **Newsletter condition only.** The Tracer's census found no sibling condition with the same pattern. |
| 4 | Scenario 4 (end-to-end discount) | **Keep** as an integration invariant test. |
| 5 | Go-ahead for formal MIG Phase 0 | **Approved.** |

## Approved behavior contract (pass verbatim to Test Author)

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
```

Notes carried from the Orchestrator's STOP 1 synthesis:
- This is a cost defect; results are already correct. Only the Defect scenario can fail before the fix.
  The three Invariants must pass both before and after the fix.
- The Red test must assert rows examined or the SQL shape, never wall-clock time.
- The fix must stay in `isSatisfiedBy()`. `getConditionsSql(null, website, false)` also feeds bulk
  `getSatisfiedIds()` and `LinksMatcher`.
