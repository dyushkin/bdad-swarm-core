# Retrospective: Newsletter Customer Segment Query Amplification - ACP2E-5597

*Living record of ticket intake, environment preparation, and successful manual reproduction.
This is not a completed-fix retrospective: no automated Red, implementation, Green, or consensus verdict exists yet.*

| Field | Value |
|---|---|
| Current version | v0.1 |
| Last updated | 2026-11-19 |
| Ticket | [ACP2E-5597](https://jira.corp.adobe.com/browse/ACP2E-5597) |
| Title | Customer segment "Newsletter Subscription" condition reads the whole customer_entity table on every check |
| Work branch | `ACP2E-5597` in CE, EE, B2B, and MSI |
| Current stage | Manual reproduction confirmed; paused before fix planning and implementation |
| Artifact policy | Documentation only; not staged or committed by this task |

## Revision history

| Version | Date | Change |
|---|---|---|
| v0.1 | 2026-11-19 | Recorded intake, full-edition installation, dataset reset/reseed, empty-cart blocker, exact SQL capture, HTTP measurement, reproduction steps, and remaining validation. |

## 0. Executive summary

**The reported inefficient SQL behavior was reproduced on a full CE/EE/B2B/MSI instance.**
Checking one subscribed customer's newsletter-segment eligibility returned **3,000,001 customer IDs**
and took **346.5 ms** within a totals request reported by PhpStorm as **408 ms, HTTP 200**.

The subscription subquery checks the supplied customer, but the outer `customer_entity` query has
neither a customer filter nor an outer `LIMIT`. When the subscription condition is true, every
customer row qualifies. The measured SQL duration is approximately **85%** of the reported HTTP
duration. SQL and call-stack logging were enabled, so this is not an uninstrumented benchmark.

Earlier local attempts did not reach the problematic execution path because the test customer's cart
was empty. The totals endpoint rejects an empty cart before collecting totals. Adding a product
allowed the target query to execute. The earlier reviewer's inability to confirm the issue remains
unexplained; neither an empty cart nor a warm cache has been established for that review.

## 1. Evidence and execution boundaries

### Ticket evidence

Glean search followed by retrieval of the ticket supplied its description and comments.
The indexed update was **2026-11-16T17:39:33Z**. This is a snapshot, not a guarantee of live Jira state.

| Snapshot field | Value |
|---|---|
| Status / resolution | Triage / Unresolved |
| Priority | Major |
| Assignee | Dmytro Yushkin |
| Reporter | Luke Fenn |

The description reports that totals calculation validates active customer segments following
[ACP2E-2926](https://jira.corp.adobe.com/browse/ACP2E-2926), while
[ACP2E-4299](https://jira.corp.adobe.com/browse/ACP2E-4299) /
[ACSD-68537](https://jira.corp.adobe.com/browse/ACSD-68537) adds a one-hour segment-result cache.
Cache misses still incur the query cost. The merchant also needs newsletter and loyalty eligibility
changes to apply in the current cart; changing cache freshness is not part of this investigation.

The November 16 comment by Kateryna Muntianu says she could not confirm a performance issue,
considered the difference between four and three million customers insufficient, and left it for
another reviewer. Her setup and query timings were not documented in the retrieved comment.
It may describe a severity disagreement rather than failure to execute the query.

### Evidence classification

| Evidence | Status |
|---|---|
| Full installation, customer counts, subscription status, rule/segment configuration, and SQL log | Locally inspected |
| Customer appearing in the segment and final HTTP duration/status/response size | Reported by the user |
| Merchant measurements, affected release versions, cache history | Ticket-reported; not independently validated across releases |
| Warm-cache masking of the original review | Hypothesis only |
| Small-versus-large dataset scaling | Not independently measured in this session |

No application fix, automated regression test, formal MIG Phase 0 report, or Consensus Gateway
execution has been completed. No Jira update, commit, push, or PR was made for this work.

## 2. Environment preparation and dataset lifecycle

The user approved replacing the local `magento` and `magento_integration_tests` databases and creating
`ACP2E-5597` from freshly fetched component mainlines. Installation used:

```bash
./setup.sh ACP2E-5597 --ee --b2b --msi
```

| Component | Origin mainline at installation | Revision |
|---|---|---|
| CE | `2.4-develop` | `90f60c1e8ae5d3b54415e70c931c2eacb2a031d8` |
| EE | `2.4-develop` | `50f7712c40cedca18cf8df0e7116f606f451867f` |
| B2B | `develop` | `e995871f8a1001caf5c35c0a1bb82a8c4bfed1e0` |
| MSI | `develop` | `bd438040f592b355cf7b8ba0b3639f88243b7bc4` |

These are installation-time revisions, not a claim that the instance continuously tracks latest mainline.
The local stack used PHP 8.5.12, MariaDB 12.3, OpenSearch 3.0, Redis 7.2, and RabbitMQ 4.3.
Storefront: **http://magento1.com/**. Default website: **`base`, ID `1`**; store ID **`1`**.
Setup/schema checks passed, 482 modules were enabled, and storefront/admin returned HTTP 200.
Initial integration priming passed with one test and two assertions; this was environment validation,
not a regression test for ACP2E-5597. Composer reported 60 advisories affecting 20 packages; not remediated.

Pre-existing CE test and MSI Composer edits were preserved in path-specific stashes:

| Repository | Preserved stash commit |
|---|---|
| CE | `4fca8e34446c11bf99d485ce335a767482d6d219` |
| MSI | `35705bf10f75f5141e15b6af1af491c06ad24af7` |

Setup left its expected MSI `_metapackage/composer.json` modification. Scout metadata was preserved.
Do not restore these stashes blindly into the triage branch.

### Dataset reset and reseed

1. Initially generated exactly three million default-website customers and populated their grid rows.
   Synthetic accounts used `acp2e5597-<n>@example.invalid`, without passwords or subscriptions.
2. The user created subscribed test customer **`3000001`**, segment **`1`**, rule **`1`**, and cart **`1`**.
3. When segment membership was unclear, the user approved removing only generated customers.
   Batched deletes removed three million synthetic accounts and their grid rows, preserving the test
   customer's entity row, subscription, cart, segment, and rule. No reinstall was needed.
4. An attempted customer-grid refresh reported an existing reindex lock and was skipped.
   The lock was not forcibly reset. This does not establish a segment-membership indexing defect.
5. The user confirmed the test customer appeared in the segment.
6. Generated three million additional customers without customer-grid reindexing.
   Verification found **3,000,001 customers and distinct emails on website 1**, zero synthetic
   newsletter subscriptions, and the original customer still subscribed.

The second load's target was three million synthetic accounts in addition to the existing test customer.
The generator is resumable and uses committed batches. Customer-grid rows are not needed to enlarge
`customer_entity` for this query.

Operational logs and generators are local session artifacts, not portable repository dependencies:
`/Users/dyushkin/.copilot/session-state/fa9073d0-fb95-4155-bd68-721012bec7a0/files/`.
Relevant files include `execution-receipt.md`, `verification.json`, `seed_customers.php`,
`remove_generated_customers.php`, `remove_generated_customers.log`, and `reseed_customers.log`.
The SQL evidence was inspected in `var/debug/db.log`; its contents may change on later requests.

## 3. Manual steps to reproduce

### Preconditions

- Customer Segments enabled.
- A subscribed registered customer on the default website who can authenticate.
- An active customer segment for that website, applied to registered customers, with
  **Newsletter Subscription is subscribed**.
- An active couponless cart price rule, applicable to that website and the customer's group, offering
  a 10% discount with **Customer Segment is the segment above**.
- A purchasable product in that customer's active cart. An empty cart is not a valid reproduction.
- Approximately three million additional customers in `customer_entity`; subscriptions are not needed.
- A valid bearer token for the same customer.
- Flat Rate shipping enabled, or substitute an available carrier/method in the request.

After saving the rule, reindex only the relevant indexer:

```bash
php bin/magento indexer:reindex salesrule_rule
```

A full `indexer:reindex` is unnecessary for this retry and can be costly with millions of customers.
Customer-grid indexing, segment membership, and the segment-result cache are distinct mechanisms.

### PhpStorm HTTP request

Replace the token locally; do not commit a real token. Texas region ID `57` was verified in this instance.

```http
@baseUrl = http://magento1.com
@customerToken = REPLACE_WITH_CUSTOMER_TOKEN

### Calculate cart totals
POST {{baseUrl}}/rest/default/V1/carts/mine/totals-information
Authorization: Bearer {{customerToken}}
Content-Type: application/json
Accept: application/json

{
  "addressInformation": {
    "address": {
      "country_id": "US",
      "region_id": 57,
      "region": "Texas",
      "postcode": "78628",
      "city": "Georgetown",
      "street": ["105 Eagle Pass Trail"]
    },
    "shipping_carrier_code": "flatrate",
    "shipping_method_code": "flatrate"
  }
}
```

### Capture cold- and warm-cache requests

1. Enable Magento SQL logging:
   ```bash
   php bin/magento dev:query-log:enable \
     --include-all-queries=true \
     --include-call-stack=true \
     --include-index-check=false
   ```
2. Watch new records in another terminal:
   ```bash
   tail -n 0 -f var/debug/db.log
   ```
3. Confirm that the customer has a nonempty active cart. Immediately before the measured request:
   ```bash
   php bin/magento cache:clean customer_segments
   ```
4. Send the totals request once, without visiting cart/checkout or issuing another totals request
   between cache cleaning and measurement. Confirm HTTP 200, record the duration, and check the discount.
5. Find the query containing both `customer_entity` and `newsletter_subscriber` with a stack through
   the newsletter segment condition. Record SQL/binds, `TIME`, and driver-reported `AFF`.
   `TIME` is seconds: `0.3465` means 346.5 ms. An ordinary subscription lookup is not the target query.
6. Resend the identical request without cleaning the cache and record the same measurements.
   Repeat cold/warm pairs under otherwise equivalent conditions, avoiding unrelated traffic.
7. Disable logging:
   ```bash
   php bin/magento dev:query-log:disable
   ```
8. Repeat overall cold/warm timing without logging to assess instrumentation overhead.
   Keep SQL logs local: they contain parameter values, potentially including authentication data.

The warm-cache and logging-disabled comparisons above remain follow-up instructions, not completed results.

## 4. Investigation: why earlier captures missed the query

Earlier captures showed only the normal subscription load:

```sql
SELECT `newsletter_subscriber`.*
FROM `newsletter_subscriber`
WHERE (customer_id = 3000001)
  AND (store_id IN ('1'))
LIMIT 1
```

That lookup returned one row in approximately 0.4-0.7 ms. Its stack was customer subscription loading,
not segment-condition validation. No target query or `CustomerSegmentsProvider` trace appeared.
Neither cache cleaning nor reloading millions of customers resolved the missing execution path.

Read-only inspection then found active cart `1` for customer `3000001` with **zero items** and no
`quote_item` rows. The segment/rule were active on website `1`; the rule included customer group `1`.

`TotalsInformationManagement::calculate()` loads the quote and calls `validateQuote()` before
`collectTotals()`. Empty carts raise:

> Totals calculation is not applicable to empty cart

Source: `app/code/Magento/Checkout/Model/TotalsInformationManagement.php:50-51,74,86-91`.
After the user added a product and retried, the newsletter-segment SQL appeared.

**Lesson:** validate HTTP status/body and cart contents before interpreting missing SQL as cache masking
or an indexing issue. A customer appearing in a segment does not prove that a particular request
reaches cart-rule evaluation.

## 5. Confirmed cold-cache result

Capture timestamp: **2026-11-19 21:55:59 UTC / 15:55:59 CST**.

```sql
SELECT `root`.`entity_id`
FROM `customer_entity` AS `root`
WHERE (
    SELECT 1
    FROM `newsletter_subscriber` AS `main`
    WHERE (main.customer_id = :customer_id)
      AND (main.subscriber_status = 1)
      AND (main.store_id IN ('1'))
    LIMIT 1
)
```

```text
BIND: :customer_id = 3000001
AFF: 3000001
TIME: 0.3465
```

The logged stack includes:

| Source location | Significance |
|---|---|
| `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php:204` | Newsletter-condition query execution |
| `magento2ee/app/code/Magento/CustomerSegment/Model/CustomerSegmentsProvider.php:105` | Customer segment validation |
| `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/FilterTextGenerator/Segment.php:73` | Segment IDs requested for rule filtering |
| `app/code/Magento/SalesRule/Model/Validator.php:270` | Sales-rule evaluation path |

| Measurement | Observed result | Source |
|---|---:|---|
| HTTP status | 200 OK | User's PhpStorm result |
| HTTP duration | 408 ms | User's PhpStorm result |
| Response size | 1,732 bytes | User's PhpStorm result |
| Newsletter query duration | 346.5 ms | `db.log` |
| Driver-reported returned rows | 3,000,001 | `db.log` |
| Query duration / HTTP duration | Approximately 85% | Arithmetic comparison of supplied measurements |

Discount application was part of the manual instructions but was not separately confirmed in the
final supplied response. No warm-cache duration has been recorded.

## 6. Expected result, interpretation, and limitations

**Expected:** checking one customer's newsletter eligibility does not return all customer IDs, and
its cost does not materially grow with the total number of customers.

**Confirmed:** the subscription subquery is independent of `root`. For this subscribed customer it
qualifies all 3,000,001 outer rows. The outer select has neither a customer predicate nor an outer limit.
The returned-row count and measured duration establish the reported inefficient query behavior locally.

The ticket separately reports these measurements; do not substitute them for local evidence:

| Customer rows | Ticket totals time | Ticket newsletter query time |
|---|---:|---:|
| 4 | Approximately 510 ms | Approximately 0 ms |
| 1,000,000 | Approximately 750 ms | Approximately 248 ms |
| 3,000,000 | Approximately 1,260 ms | Approximately 745 ms |

The ticket reports approximately 465 ms for cached totals at three million customers and 381 ms for
the query on a copy of the merchant's 2.4.8-p5 environment with 2.9 million customers.
Different environments and instrumentation preclude direct absolute-time equivalence.

Limitations:

- The local 408 ms result includes SQL/call-stack logging overhead.
- A single captured cold-cache request is not a latency distribution or an independent scaling benchmark.
- A cached request avoiding validation would not disprove cold-cache query amplification.
- Warm-cache masking and hardware differences remain possible explanations for the original review,
  not established facts. The local empty-cart blocker must not be attributed to that reviewer.
- No local fix comparison, unsubscribe behavior check, or release-matrix validation has been run.

## 7. Lessons and next gate

1. Establish a successful small-data execution path before loading millions of rows.
   In this session, segment membership was confirmed before the second load, but the target SQL was
   only confirmed afterward; checking the nonempty cart and HTTP response earlier would have saved work.
2. Separate customer-grid indexing, segment membership refresh, and result-cache invalidation.
   A missing matched customer is not by itself proof of a customer-grid index problem.
3. Inspect the exact query and stack, not just request duration or any query mentioning newsletters.
   The normal 0.4 ms subscription lookup and the 346.5 ms segment query are different operations.
4. Preserve manual configuration during dataset resets. Targeted fixture deletion avoided another
   full install and retained the test customer's subscription, rule, segment, and cart.
5. Keep data loading minimal: synthetic customer rows suffice; three million subscriptions or grid
   rows are not required to expose the outer-query behavior.

**Paused before the fix.** This documentation request does not authorize implementation.
Remaining measurement work is a warm-cache comparison, logging-disabled timings, and a controlled
small-data baseline if scaling needs independent quantification. Before any shared-code change,
complete the required impact analysis and approval gates, then author an invariant-focused failing
test and validate the chosen fix through the repository's normal workflow.

Filtering `root.entity_id` or adding an outer limit were suggestions in the ticket, not an accepted
design here. Their correctness, edge cases, and affected callers still require evaluation.

## 8. BDAD Swarm retrospective (dry run, Stages 1–4b)

*Added after STOP 4. Sections 0–7 above are the manual triage record and are unchanged. This section is Scribe's
Stage 4b egress (`docs/bdad-workshop/stage4-dryrun/reference-4b/egress/retrospective.md`) with its headings moved down
one level, the measured usage filled in, and the final STOP 4 decisions added. The production fix and the test are in the
working tree only: nothing is staged or committed.*

### STOP 4 decisions (final, human, 2026-11-19)

| # | Decision | Outcome |
|---|---|---|
| 1 | Accept the verdict and the egress | **Accepted:** GREEN with deferrals; dev summary, release notes and this retrospective |
| 2 | Who runs the deferred suites (Cart & Checkout, Order Management & Delivery, B2B Commerce) | **The developer**, through the PR's Jenkins build |
| 3 | Retrospective | Added here as §8; the triage record (§0–§7) is unchanged |
| 4 | Commit | **Not committed.** The dry run ends at STOP 4 |

Egress files: `docs/bdad-workshop/stage4-dryrun/reference-4b/egress/` (`dev-summary.md`, `release-notes.md`,
`retrospective.md`).

### Summary
The "Newsletter Subscription" segment condition's `isSatisfiedBy()` issued an outer `SELECT root.entity_id FROM customer_entity AS root WHERE (<uncorrelated subquery>)` with no customer filter. A subscribed customer therefore returned every `customer_entity` row (3M in the report) on each cart totals call. The fix adds the customer filter to the outer select and passes the full select to `matchParameters()`. File: `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php`.

Domain: Customer Segments (primary); touches Cart Price Rules (sales rule) and Quote/Cart totals. Repro class: single-session.

### Manual Steps to Reproduce
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

### Behavior contract (approved at STOP 1, amended at STOP 3)
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

### Timeline / stages
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

### Validation results
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

### Behavior change
A customer id with no `customer_entity` row now makes the condition false (it could previously be true because other customers existed). The bulk path (`getSatisfiedIds()` / `LinksMatcher`) is unchanged.

### Not run locally
- **Cart & Checkout suite** — DEFERRED (human, STOP 4), to CI.
- **Order Management & Delivery suite** — DEFERRED (human, STOP 4), to CI.
- **B2B Commerce suite** — DEFERRED (human, STOP 4), to CI.
- **MFTF §C** — N/A (no `Test/Mftf/*.xml` changed; scope decision).
- **No bisect** of the cause of the test order-dependence; `_resetState()` isolation accepted as is.
- **Red not re-run** after the docblock-only change.
- **PHPMD** not run.
- **Scout `review_pr`** not run.

### Usage
Usage for each dry-run stage comes from that run's `usage.json`. Credits are `totalNanoAiu / 1e9`.

| Stage | Reference run | Wall time | AI credits |
|---|---|---|---|
| 1: intake → STOP 1 | `docs/bdad-workshop/stage1-dryrun/reference/` | 5 min 54 s | 113.27 |
| 2: Phase 0 + Red → STOP 2 | `docs/bdad-workshop/stage2-dryrun/reference/` | 11 min 30 s | 294.89 |
| 3: Green → STOP 3 | `docs/bdad-workshop/stage3-dryrun/reference/` | 6 min 10 s | 162.55 |
| 4: loop-back + Gateway → STOP 4 (NOT-GREEN) | `docs/bdad-workshop/stage4-dryrun/reference/` | 36 min 16 s | 350.34 |
| 4b: Gateway re-run + egress → STOP 4 (GREEN with deferrals) | `docs/bdad-workshop/stage4-dryrun/reference-4b/` | 18 min 7 s | 179.28 |
| **Total** | | **77 min 57 s** | **1100.33** |

Per-agent breakdowns are in each stage's report: `swarmd-dryrun-stage<N>-report.md`.

### Lessons / follow-ups
- A correlated customer filter belongs in every per-customer segment condition. Other conditions may be worth auditing (not done; out of scope).
- Order-dependence from memoized segment state was masked rather than root-caused.
- Reviewers should expect the 2-line production change plus the docblock hunk, and one new test class.
- The deferred dependent-domain suites must pass in the PR's Jenkins build before merge (owner: developer).
