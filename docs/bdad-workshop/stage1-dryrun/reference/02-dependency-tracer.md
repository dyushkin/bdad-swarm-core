## ACP2E-5597 — Pre-intake mechanism report

**Owning domain:** Customer Management (`Magento_CustomerSegment`); observed consumers: Merchandising & Promotions, Cart & Checkout, Commerce Web APIs. Domain-map dependents: Cart & Checkout, Order Management & Delivery, B2B Commerce (`.github/agents/reference/domains.md:29,82`).

### 1. Mechanism

**Ticket claim confirmed.**

- `Newsletter::isSatisfiedBy()` creates an outer `customer_entity AS root` select directly through `getResource()->createSelect()`, selecting every customer row without customer or website correlation, then attaches the condition as a `WHERE` subquery (`Newsletter.php:193-204`).
- `getConditionsSql(..., $isFiltered=true)` creates the inner `newsletter_subscriber` select and correctly filters `main.customer_id` using `_createCustomerFilter($customer, ...)`, subscription status, store/website, and `LIMIT 1` (`Newsletter.php:129-149`).
- That customer filter therefore constrains the inner subscription lookup, but it does **not** constrain `root`. The resulting condition is effectively an uncorrelated existence test: if the supplied customer is subscribed, some outer customer row matches and `fetchOne()` returns `> 0` (`Newsletter.php:198-205`).
- This is an override, not the inherited base behavior. `AbstractCondition::isSatisfiedBy()` fetches `getConditionsSql()` directly; it does not add a separate customer root select (`AbstractCondition.php:153-161`).
- `$isFiltered` is only bypassed for bulk matching; default per-customer evaluation enters the filtered branch at `Newsletter.php:134`.
- A `root.entity_id`/customer correlation or equivalent inner/outer query rewrite would address the immediate mechanism. `LIMIT 1` alone on the outer query may reduce scanning but does not correct which outer rows qualify; the inner select already has `LIMIT 1` (`Newsletter.php:141`).

### 2. Sibling-pattern census

**Same uncorrelated outer-root + `fetchOne()` pattern:**

- Only `Customer\Newsletter::isSatisfiedBy()` was found using this exact pattern (`Newsletter.php:193-205`).

**Customer conditions already correlated/filtering their per-customer select:**

- `Customer\Address`: filters `customer_address.parent_id` with `_createCustomerFilter()`, limits one, joins to customer; `isSatisfiedBy()` fetches that select directly (`Customer/Address.php:106-128,138-154`).
- `Customer\Attributes`: filtered mode selects the relevant EAV/backend entity and filters `main.entity_id`; `isSatisfiedBy()` uses the base direct-fetch implementation (`Customer/Attributes.php:341-370`; `AbstractCondition.php:153-161`).
- `Customer\Storecredit`: filtered mode filters `customer_id` and limits one (`Customer/Storecredit.php:91-110`).
- Product wishlist/cart conditions fetch their condition or cart select directly and explicitly constrain quote/customer when applicable (`Product/Combine/ListCombine.php:265-308`).

**Intentional outer-root scans that must not be mistaken for defects:**

- `Customer\Attributes::getCustomerSelect()` (`Customer/Attributes.php:507-513`).
- Product wishlist `getSatisfiedIds()` (`Product/Combine/ListCombine.php:376-396`).
- Newsletter’s own `$isFiltered=false` “not subscribed” branch (`Newsletter.php:150-183`).

These return multiple customer IDs for bulk processing and are not per-customer `fetchOne()` evaluation.

### 3. Caller and batch paths

- **Segment validation:** `Segment::validateCustomer()` builds parameters and delegates to the root condition’s `isSatisfiedBy()` (`Segment.php:310-348`).
- Root combine dispatches to child conditions, including `Newsletter` (`Condition/Combine/Root.php:113-136`; `Condition/Combine/AbstractCombine.php:250-263`).
- **Provider runtime path:** `CustomerSegmentsProvider` consumes `QuoteMatcher` results, then falls back to `segment->validateCustomer($customerId, $websiteId)` (`CustomerSegmentsProvider.php:96-109`).
- **SalesRule path:** `SalesRule\Validator::getRules()` invokes collection `setValidationFilter()` with the quote address (`Validator.php:264-281`). `AdvancedSalesRule`’s collection plugin builds filter-text generators (`Collection.php:63-120`).
- **Customer-segment generator:** it obtains segment IDs from `CustomerSegmentsProvider` (`FilterTextGenerator/Segment.php:66-86`). This can gate selection of sales rules, including rules carrying free-shipping actions.
- **Batch/indexing path:** `AbstractCombine::getSatisfiedIds()` evaluates child bulk queries (`AbstractCombine.php:271-286`); `LinksMatcher` calls that path for every website and stores all matched customer IDs (`LinksMatcher.php:131-140`). Newsletter uses `getConditionsSql(null, website, false)`, so a fix placed unconditionally in `getConditionsSql()` could break “match all customers.” Keeping correlation per-customer in `isSatisfiedBy()` is safer unless bulk semantics are explicitly branched.
- A separate event path uses persisted combined `condition_sql` and direct SQL parameter binding (`Model/Customer.php:251-275,329-365`); it bypasses the PHP `isSatisfiedBy()` traversal for matching events.

### 4. Existing tests

- Strongest integration coverage: `testProcessEventCustomerWithSubscription` configures a Newsletter condition and asserts customer/segment membership for three customers (`dev/tests/integration/testsuite/Magento/CustomerSegment/Model/CustomerTest.php:338-396`).
  - It does **not** cover a truly unsubscribed customer being assigned the segment; the negative case is caused by the additional `created_at` condition, so it can pass despite this defect.
- Unit `CustomerTest` only verifies the condition factory exposes `Newsletter`; it mocks the Newsletter object and never executes its SQL (`Test/Unit/.../Condition/CustomerTest.php:21-26,68-89,104-144`).
- Provider and filter-generator unit tests cover segment ID reuse/propagation, not SQL correctness (`CustomerSegmentsProviderTest.php:32-63`; `FilterTextGenerator/SegmentTest.php:127-142`).
- MFTF coverage exists for subscribed/not-subscribed newsletter segment administration, including:
  - `Test/Mftf/Test/AdminCreateCustomerSegmentNewsletterSubscriptionTest.xml:25`
  - `Test/Mftf/Test/AdminCreateCustomerSegmentNotSubscribedNewsletterTest.xml:25`
  - corresponding “without real-time check” variants.
- No direct unit test of `Newsletter::isSatisfiedBy()` was located.

### 5. Risks / open questions

- Correlation belongs in the per-customer query; changing `getConditionsSql(..., false)` risks bulk matching/index rebuilds.
- The fix should distinguish subscribed, not-subscribed, negated condition/combine behavior, and visitor handling (`Newsletter.php:147-178,195-197`).
- If correlation is added through the outer `root`, ensure the binding remains compatible with `_createCustomerFilter()` when `$customer` is an ID, object, expression, or select.
- Suggested regression: one subscribed and one unsubscribed customer in the same website; evaluate the unsubscribed customer’s segment while a subscription for another customer exists. Also exercise “not subscribed” and bulk `getSatisfiedIds()`/link matching.
- Scout graph freshness is unverified and derived symbol/vector views are not guaranteed; no MIG Phase 0 cache-writing analysis was run.
