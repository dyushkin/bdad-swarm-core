# Blast-Radius Report

## 1. MIG commands + results

| Command | Result |
|---|---|
| `php dev/tools/impact-graph/bin/impact-graph analyze --plugins-subject 'Magento\CustomerSegment\Model\Segment\Condition\Customer\Newsletter' --format=json` | `plugins: []` |
| `php dev/tools/impact-graph/bin/impact-graph analyze --plugins-method isSatisfiedBy --format=json` | `plugins: []` |
| `php dev/tools/impact-graph/bin/impact-graph analyze --mocks 'Magento\CustomerSegment\Model\Segment\Condition\Customer\Newsletter' --format=json` | `mocks: []`, `stubs: []` after `--help` confirmed FQCN syntax. |
| `php dev/tools/impact-graph/bin/impact-graph analyze --mftf-module Magento_CustomerSegment --format=json` | 29 tests. Newsletter-related: subscribed, not-subscribed, multi-website, and without-real-time-check variants. |
| `php dev/tools/impact-graph/bin/impact-graph analyze --diff HEAD --format=json` | `changed: []`; expected pre-fix empty diff. |
| Parent checks for `AbstractCondition` and `AbstractCombine` | Both `plugins: []`. |

Relevant MFTF tests include:

- `AdminCreateCustomerSegmentNewsletterSubscriptionTest.xml`
- `AdminCreateCustomerSegmentNotSubscribedNewsletterTest.xml`
- `AdminCreateCustomerSegmentNewsletterSubscriptionWithoutRealTimeCheckTest.xml`
- `AdminCreateCustomerSegmentWithSeveralWebsitesTest.xml`
- `AdminCreateCustomerSegmentWithSeveralWebsitesWithoutRealTimeCheckTest.xml`

The subscribed test creates a segment with `Newsletter Subscription` and verifies membership:
`magento2ee/app/code/Magento/CustomerSegment/Test/Mftf/Test/AdminCreateCustomerSegmentNewsletterSubscriptionTest.xml:22-84`.

## 2. Domains

- **Owning domain: Customer Management** — `Magento_CustomerSegment` is explicitly assigned here: `.github/agents/reference/domains.md:29`.
- **Dependent domains from the adjacency map:** **Cart & Checkout**, **Order Management & Delivery**, **B2B Commerce**: `.github/agents/reference/domains.md:82`.
- **Observed functional consumer:** Merchandising & Promotions/SalesRule filtering, because the SalesRule segment condition obtains segment IDs from `CustomerSegmentsProvider`:
  `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/FilterTextGenerator/Segment.php:23-87`.
- `Magento_Newsletter` is itself catalogued under Merchandising & Promotions:
  `.github/agents/reference/domains.md:55`.

## 3. Callers (confirmed/provisional)

**Provisional pending derived-view freshness:** Scout indexed through `90f60c1e8ae`, with zero gap files, but derived views were not verified.

- `Segment::validateCustomer()` passes the current customer, website, and bound parameters to the root condition’s `isSatisfiedBy()`:
  `magento2ee/app/code/Magento/CustomerSegment/Model/Segment.php:310-348`.
- Root combines delegate each child `isSatisfiedBy()` and implement requirement/aggregation inversion:
  `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Combine/Root.php:113-125`.
- `AbstractCombine::isSatisfiedBy()` dispatches recursively to each condition; Newsletter is reached when present as a leaf:
  `magento2ee/app/code/Magento/CustomerSegment/Model/Condition/Combine/AbstractCombine.php:250-262`.
- Customer event processing invokes `Segment::validateCustomer()`:
  `magento2ee/app/code/Magento/CustomerSegment/Model/Customer.php:393-427`.
- `CustomerSegmentsProvider` invokes `validateCustomer()` for registered and visitor segment membership, after any quote-matcher cached result:
  `magento2ee/app/code/Magento/CustomerSegment/Model/CustomerSegmentsProvider.php:80-132`.
- `QuoteMatcher` invokes `validateCustomer()` for quote-related event segments:
  `magento2ee/app/code/Magento/CustomerSegment/Model/QuoteMatcher.php:77-103`.
- SalesRule path: `FilterTextGenerator\Segment` obtains memberships from `CustomerSegmentsProvider`:
  `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/FilterTextGenerator/Segment.php:66-87`.
- SalesRule registration adds the Customer Segment condition through the observer:
  `magento2ee/app/code/Magento/CustomerSegment/Observer/AddSegmentsToSalesRuleCombineObserver.php:46-62`.
- No direct Newsletter-specific callers were confirmed in CE or B2B; dispatch is polymorphic through condition-tree callers. The Scout method-reference result is name-based and therefore provisional.

## 4. Bulk-path guard

**Protected: do not modify bulk behavior.**

- Leaf `AbstractCondition::getSatisfiedIds()` calls `getConditionsSql(null, $websiteId, false)`:
  `magento2ee/app/code/Magento/CustomerSegment/Model/Condition/AbstractCondition.php:164-177`.
- `AbstractCombine::getSatisfiedIds()` recursively uses each child’s `getSatisfiedIds()`:
  `magento2ee/app/code/Magento/CustomerSegment/Model/Condition/Combine/AbstractCombine.php:265-321`.
- `LinksMatcher` invokes root `getSatisfiedIds($websiteId)`:
  `magento2ee/app/code/Magento/CustomerSegment/Model/ResourceModel/Customer/LinksMatcher.php:131-140`.
- Newsletter’s `$isFiltered === false` branch remains the bulk-only SQL implementation:
  `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php:150-183`.
- Therefore the approved fix must remain inside `isSatisfiedBy()` and must not alter `getConditionsSql()`:
  `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php:187-206`.

## 5. Anti-pattern census

- Scoped Scout regex for `customer_entity` within 500 characters of `fetchOne` found exactly one match in `Magento_CustomerSegment/Model/Segment/Condition`:
  `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php:187-206`.
- Newsletter builds the outer `customer_entity AS root` select, embeds the filtered subquery, then calls `fetchOne()`:
  `Newsletter.php:198-204`.
- Comparison examples such as Customer Address execute `getConditionsSql()` directly rather than nesting it inside a separate uncorrelated customer select:
  `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Address.php:138-154`.
- Sales Combine likewise executes its condition select directly:
  `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Sales/Combine.php:154-163`.

**Result:** no repeated in-scope occurrence was found; Newsletter is the sole anti-pattern candidate.

## 6. Tests & mocks affected

- MIG `--mocks` returned no results; this is not exhaustive.
- Manual Scout evidence found a unit mock of the Newsletter condition:
  `magento2ee/app/code/Magento/CustomerSegment/Test/Unit/Model/Segment/Condition/CustomerTest.php:58-79,104-132`.
  This test only exercises child-option generation, not `Newsletter::isSatisfiedBy()`.
- Existing integration coverage exercises a real Newsletter condition and event-processing path:
  `magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/CustomerTest.php:338-396`.
- It creates a subscribed Newsletter condition plus a date condition:
  `CustomerTest.php:347-366`.
- Proposed fix introduces no collaborator under the approved `isSatisfiedBy()`-only scope, so no new mock synchronization is currently expected.

## 7. Test placement/fixtures

**Recommended placement:** add focused coverage beside:

`magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/CustomerTest.php:338-396`

This placement already verifies newsletter membership through `processEvent()`, real-time condition evaluation, and persisted customer-segment IDs.

Relevant fixture files:

- `dev/tests/integration/testsuite/Magento/Newsletter/_files/three_subscribers.php`
- `dev/tests/integration/testsuite/Magento/Newsletter/_files/subscribers.php`
- `dev/tests/integration/testsuite/Magento/Newsletter/_files/customer_with_subscription.php`
- `magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/_files/customer_segment_rules.php`
- `magento2ee/dev/tests/integration/testsuite/Magento/SalesRule/_files/cart_rule_20_percent_customer_segment.php`

Use the segment fixtures for regression coverage and the cart-rule fixture for dependent SalesRule validation.

## 8. Risks/gaps

- **High risk:** modifying Newsletter `getConditionsSql()` would alter bulk `getSatisfiedIds()`/`LinksMatcher` behavior; keep the fix inside `isSatisfiedBy()`.
- **SalesRule dependent path:** newsletter-driven segment IDs may feed cart-price-rule matching through `CustomerSegmentsProvider`.
- **Domain test requirement:** dependent suites for Cart & Checkout, Order Management & Delivery, and B2B Commerce remain required; no tests were executed in this read-only report.
- **MIG limitation:** no CALLS closure or newly introduced collaborator delta exists pre-fix; caller evidence is therefore provisional.
- **Index limitation:** Scout reported unverified derived views. Exact source ranges above are indexed evidence, but graph claims should be revalidated after patch generation.
- **Mock gap:** MIG `--mocks` did not find the manually confirmed option-generation mock; `--mock-impact <git-ref>` should still be run once a diff exists.
