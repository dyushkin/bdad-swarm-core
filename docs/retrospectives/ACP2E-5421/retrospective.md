# Retrospective: Bundle Cart Price Rule Discount Cap - ACP2E-5421

*Living record of the BDAD intake dry run and subsequent read-only clarification.
This is not a completed-fix retrospective: no automated Red, implementation, Green, or consensus verdict exists yet.*

| Field | Value |
|---|---|
| Current version | v0.2 |
| Last updated | 2026-10-28 |
| Ticket | [ACP2E-5421](https://jira.corp.adobe.com/browse/ACP2E-5421) |
| Title | Bundle product cart price rule discount caps out beyond a certain cart quantity |
| Branch at documentation creation | `ACP2E-5421` |
| Current stage | Post-intake continuation attempted; partial MIG evidence; blocked before Red |
| Artifact policy | Local-only; not staged or committed |

## Revision history

| Version | Date | Change |
|---|---|---|
| v0.1 | 2026-10-28 | Persisted the 2026-10-07 Scribe intake dry run and 2026-10-28 parent/child eligibility and discount-cap clarification. |
| v0.2 | 2026-10-28 | Recorded approved continuation, actual Phase 0 command results, and tooling blocker before test authoring. |

## 0. Executive summary

**The issue remains valid under the reported reproduction steps, with setup qualifications still to confirm.**
The apparent contradiction between `tag does not contain bundle` and a discount applying is explained by
parent/child action-condition scope. The bundle parent has `tag=bundle`; the simple child does not inherit
that value. An unscoped negative condition can qualify the untagged child.

The source investigation found a separate quantity inconsistency in the children-calculated bundle path:
the percentage calculator uses total child quantity, including the parent multiplier, while `minFix`
caps the discount using only the child's own quantity. For ten bundles containing ten units at 1.55,
that explains a discount of 15.50 instead of 23.25.

This is a source-backed explanation consistent with the ticket and the user's observations, **not an
independently executed reproduction or a completed root-cause/impact report**. Bundle price type, exact
builds, and full rule configuration remain to be confirmed.

## 1. Evidence and execution boundaries

### Ticket evidence

Glean search and document retrieval supplied the ticket for the Scribe dry run.
The indexed update was **2026-09-30T16:06:17Z**; this snapshot is not a guarantee of live Jira state.

| Snapshot field | Value |
|---|---|
| Status / resolution | In Development / Unresolved |
| Priority / severity | Normal / S3, according to QE triage |
| Developer | Dmytro Yushkin |
| QE | Kateryna Muntianu |
| Reporter | Tadeusz Grudzien |
| Due date | 2026-10-29 |
| Customer case | E-002480313 |

QE's comment dated 2026-09-29 reports updated steps, successful reproduction, passed triage, and possible
core-code changes. The ticket names `Magento\SalesRule\Model\Utility::minFix` as an investigation lead.
Neither statement was treated as independent runtime verification by the assistant.

### Work actually performed

- **2026-10-07:** a Scribe subagent performed an intake-only dry run using the conductor's Glean handoff
  and repository guidance, then returned its structured brief at STOP 1.
- **2026-10-28:** the user supplied manual observations about parent/child attribute scopes and the
  ten-bundle result. The conductor performed a read-only source trace and documentation search.
- No production code, tests, rule settings, product data, application/DB state, or Jira records were changed.
  No staging storefront was accessed by the assistant. No tests were run.
- No formal Dependency Tracer/MIG Phase 0 report or Consensus Gateway execution has occurred.
- Model-token/cost totals were not available and are not reconstructed or estimated here.

Scout reported incomplete indexing; refresh timed out twice. Its range reader also refused some files
that its symbol outline could identify. The investigation used narrow native read-only fallback for the
quote-item quantity and base condition-comparison methods; those citations are marked **UNINDEXED** below.

## 2. Scribe intake dry-run result

### Scenario

An administrator configures a bundle containing ten units of a simple product priced at 1.55 and a 15%
cart price rule. A shopper adds one bundle, then increases the **bundle parent's cart quantity** to ten.
The discount reportedly stops scaling and is capped at 15.50.

### Preconditions

Ticket-specified setup:

- Simple product price: **1.55**.
- Bundle selection quantity: **10**, with **User defined qty** enabled.
- Text product attribute, for example `tag`, with **Use for Promo Rule Conditions = Yes**.
- Bundle parent attribute value: **`bundle`**.
- Rule action: **Percent of product price discount**, **Discount Amount = 15**.
- For the ticket's `24dev` scenario, action condition: **`tag does not contain bundle`**.
- One bundle must cost **15.50** before the parent quantity is increased.

Proposed reproduction controls, not additional ticket facts: use an otherwise empty cart, no competing
discounts, an applicable active rule, and enabled/salable products with sufficient stock. Ten bundles
containing ten simple units imply 100 simple-product units.

### Manual Steps to Reproduce

These are the ticket steps as captured during intake, with annotations; **the assistant did not execute them**.

1. Create a product attribute such as `tag`: Catalog Input Type for Store Owner = **Text**;
   Values Required = **Yes/No**; Use for Promo Rule Conditions = **Yes**.
   The ticket does not choose the Values Required setting; record the actual choice.
2. Create and save Simple Product #1 with price **1.55**.
3. Create Bundle Product #2, add Simple Product #1, set selection **Qty = 10**, and check
   **User defined qty**. Set the bundle parent's `tag` value to **`bundle`**, then save.
   Ensure the attribute is available in the bundle's attribute set; record price type and option-required setting.
4. Create a Cart Price Rule. In **Actions**, select **Percent of product price discount**, set
   **Discount Amount = 15**, and use **If ALL of these conditions are TRUE: tag does not contain bundle**.
   Record website/customer-group/coupon applicability and any quantity limits.
5. Run `bin/magento indexer:reindex` as a manual setup instruction only.
6. Add one bundle to the storefront cart, retaining selection quantity ten.
   Confirm that one bundle costs **15.50 = 10 x 1.55**.
7. Open `checkout/cart` and confirm that the discount applies at **15.50 x 0.15**.
   This is 2.325 before currency rounding; the ticket does not prescribe a displayed rounded value.
8. Increase the **bundle parent cart quantity** to **10**, not the selection quantity, and update the cart.

### Expected versus reported actual

| Measurement at ten bundles | Expected | Reported actual |
|---|---:|---:|
| Subtotal | 155.00 | No differing subtotal reported |
| 15% discount | 23.25 | 15.50 |

Invariant: with unchanged eligible selections and no configured limit, increasing bundle parent quantity
should proportionally increase the eligible percentage-discount basis.

### Domain and reproduction classification

| Classification | Intake result |
|---|---|
| Primary domain | Merchandising & Promotions (`Magento_SalesRule`, Cart Price Rules) |
| Touched domains | Cart & Checkout; Catalog & Pricing Management |
| Mapped dependent domain | Order Management & Delivery; no order-stage defect established |
| `repro-class` | `single-session`; no reported concurrent requests or stale cross-session interaction |
| Proposed automated vehicle | Integration is an initial candidate for the quantity/totals invariant, not a validated selection |

Domain evidence: `.github/agents/reference/domains.md:46,55,60,79`, as read during intake.
Before new test authoring, search existing domain/edition-appropriate coverage and apply the current Test Author
selection rules. MFTF remains appropriate if the defect requires browser-specific behavior.

## 3. Later clarification: eligibility is not attribute inheritance

The user questioned the negative condition because only the bundle parent has `tag=bundle`, then reported:

| Action condition | User observation / source interpretation |
|---|---|
| `tag does not contain bundle` | Discount applies; the untagged child can qualify |
| `tag (Children Only) does not contain bundle` | Discount applies through child evaluation |
| `tag (Parent Only) does not contain bundle` | No discount; the parent contains `bundle` |
| `tag (Parent Only) contains bundle` | Discount applies, but ten bundles still show the 15.50 cap |

These outcomes are consistent with the usual `ALL ... TRUE` action combination. They are not a claim about
every possible nested aggregator or rule configuration.

The scope selector explicitly chooses entities for condition validation:
parent scope uses the parent when present; children scope uses children when present; unscoped validation
considers children plus the current item. A matching entity can satisfy the condition.
See `app/code/Magento/SalesRule/Model/Rule/Condition/Product/Combine.php:125-158`.

The scopes are registered as separate attribute choices in
`app/code/Magento/SalesRule/Model/Rule/Condition/Product.php:61-75`.
Product validation reads the selected product's own attribute value, not an inherited bundle value:
`app/code/Magento/SalesRule/Model/Rule/Condition/Product.php:164-215`.

An unset text value can pass the negative substring comparison: no `bundle` substring matches, then the
`does not contain` operator negates that result.
**UNINDEXED:** `app/code/Magento/Rule/Model/Condition/AbstractCondition.php:830-905`.

**Conclusion:** the original steps are not inherently contradictory. They can qualify the untagged simple
child. The user's **`tag (Parent Only) contains bundle`** variant expresses parent-targeted eligibility
more clearly and should be retained as an additional reproduction, not silently substituted for the original.

## 4. Source finding: calculation quantity differs from the cap quantity

Parent-only **eligibility** does not imply parent-only **price calculation**.
In the children-calculated bundle branch, each child is validated independently. A parent-scoped condition
can qualify the child by inspecting its parent, after which the child's discount is calculated and stored.
See `app/code/Magento/SalesRule/Model/RulesApplier.php:246-274`.

For one eligible selection, no previous discount, and no configured discount-quantity limit:

| Stage | Expression | Result |
|---|---|---:|
| Effective child quantity | Selection quantity 10 x parent quantity 10 | 100 |
| Percentage amount | 100 x 1.55 x 0.15 | 23.25 |
| Current `minFix` ceiling | Child's own quantity 10 x 1.55 | 15.50 |
| Capped discount | min(23.25, 15.50) | 15.50 |

Connecting source evidence:

- `Utility::getItemQty()` obtains `getTotalQty()` and applies any rule quantity limit:
  `app/code/Magento/SalesRule/Model/Utility.php:380-384`.
- `getTotalQty()` multiplies child quantity by parent quantity, while `getQty()` returns the item's own quantity.
  **UNINDEXED:** `app/code/Magento/Quote/Model/Quote/Item/AbstractItem.php:392-407`.
- `RulesApplier::getDiscountData()` supplies the calculated quantity to the action calculator and later calls
  `minFix`: `app/code/Magento/SalesRule/Model/RulesApplier.php:349-365`.
- `ByPercent` calculates the percentage from the supplied quantity and item price:
  `app/code/Magento/SalesRule/Model/Rule/Action/Discount/ByPercent.php:57-79`.
- `minFix` caps accumulated discounts against item price multiplied by `getQty()`, not `getTotalQty()`:
  `app/code/Magento/SalesRule/Model/Utility.php:277-292`.
- The quote collector aggregates children's discounts for children-calculated items:
  `app/code/Magento/SalesRule/Model/Quote/Discount.php:223-242`.

This mechanism explains the reported result for that calculation branch. It does not establish that all
bundle price types or all reported versions follow the same path. No implementation was selected or changed.

## 5. Documentation search

The remembered old internal engineering article about discount application/distribution was **not located**.
Newer ACCS/OOPE and SaaS catalog-pricing articles were not treated as substitutes.

A relevant wiki lead was read:
[List of Updated Documents for Tier 4 Escalated Issues](https://wiki.corp.adobe.com/spaces/CENG/pages/2509841477/List+of+Updated+Documents+for+Tier+4+Escalated+Issues),
indexed update **2026-09-02T21:30:10Z**. Its ACP2E-3151 / ACP2E-3120 entry asks that documentation mention
Parent Only and Children Only conditions for multiple product attributes.

That entry links to
[Create a cart price rule](https://experienceleague.adobe.com/en/docs/commerce-admin/marketing/promotions/cart-rules/price-rules-cart-create),
which was read during the clarification. It distinguishes cart qualification under **Conditions** from
item selection under **Actions** and describes percentage discounts for qualifying items.

The public page also broadly states that an empty conditional attribute prevents application. That wording
does not describe the negative-comparison path found in the inspected source; it is not sufficient evidence
to dismiss this reproduction. The documentation/source discrepancy remains recorded rather than resolved
by assumption.

## 6. Open questions and next-phase inputs

| Question | Required confirmation |
|---|---|
| Bundle price type | Fixed/dynamic setting and actual children-calculated path |
| Bundle option configuration | Whether the option is required; selection quantity remains ten |
| Attribute setup | Values Required choice, attribute applicability, and child's actual unset/empty value |
| Rule limits and calculation context | Maximum discount quantity, step, other rules, tax/currency settings |
| Version shorthand | Exact builds behind `24dev` and `248p5` |
| Version-specific rule setup | Whether "no actions" means no action conditions, not omission of the percentage action |
| Existing automated coverage | Suitable tests to extend before introducing new ones |
| Blast radius | Formal MIG Phase 0 report and dependent-domain verification plan |
| Old wiki article | Exact title/location remains unknown |

Sherpa's related-ticket suggestions remain unverified investigation leads:
ACSD-58705, ACP2E-3017, ACP2E-3346, AC-8853, MDVA-44533, ACSD-52736, ACSD-54418.

## 7. Workflow state and approval boundaries

| Phase / gate | Recorded state |
|---|---|
| Scribe intake / STOP 1 | Dry-run brief delivered and discussed; findings persisted here at the user's request |
| Dependency Tracer / Phase 0 | Formal report not started; read-only clarification is input, not a substitute |
| Test Author / Red / STOP 2 | Not started; no automated reproducer or confirmed failure |
| Patch Generator / Green / STOP 3 | Not started; no fix or confirmed passing test |
| Consensus Gateway | Not started; no GREEN verdict |
| Final egress / STOP 4 | Pending; no final developer summary or release notes |

The original documentation request authorized creating this record, not advancing through the remaining phases.
Preserve all four mandatory human approval gates. Update this document with actual commands, results,
decisions, and limitations as later phases are explicitly undertaken; do not backfill unexecuted outcomes.

## 8. Approved continuation attempt: partial Phase 0, blocked before Red

The subsequent explicit **Proceed** approved advancing beyond STOP 1 only. No later gate was waived.
The workflow table above records the prior state; the results below supersede its Phase 0/Red status.

### Execution context and available evidence

- `git branch --show-current` returned `ACP2E-5421`.
- `git status --short` returned no entries before this documentation edit.
- Read the current orchestrator, Dependency Tracer, and Test Author profiles, domain reference,
  shared tooling guidance, and this persisted handoff.
- **UNINDEXED:** those exact-path local reads used native tools because this session exposes no
  Scout, `tool_search_tool`, `api_tool`, Glean, or specialist-delegation tools. No failed Scout
  calls or refresh retries are claimed; they could not be invoked.
- No source discovery, repeated mechanism archaeology, environment reset, shared-state mutation,
  PHPUnit execution, production implementation, commit, staging, or Jira update was performed.
- No specialist was dispatched; therefore there are no returned `<usage>` blocks to log.

### Actual MIG Phase 0 commands and results

All commands ran with host-native PHP from the repository root and exited **0**:

```text
php dev/tools/impact-graph/bin/impact-graph analyze --diff HEAD --format=json
{"diff":"HEAD","changed":[]}

php dev/tools/impact-graph/bin/impact-graph analyze --mock-impact HEAD --format=json
{"diff":"HEAD","mock_impact":[]}

php dev/tools/impact-graph/bin/impact-graph analyze --plugins-subject 'Magento\SalesRule\Model\Utility' --format=json
{"subject":"Magento\\SalesRule\\Model\\Utility","plugins":[]}

php dev/tools/impact-graph/bin/impact-graph analyze --dispatches app/code/Magento/SalesRule/Model/Utility.php --format=json
{"target":"app/code/Magento/SalesRule/Model/Utility.php","dispatches":[]}
```

JSON above is whitespace-compacted only. The first two results describe a clean worktree against HEAD;
they do **not** establish that a future change has no impact or stale mocks. The targeted results
report only MIG's Utility wiring findings, not caller closure, downstream safety, or a full impact report.

### Phase 0 domain classification and incomplete report

| Criterion | Status | Strongest exact evidence | Confidence | Gap |
|---|---|---|---|---|
| Owning domain | Classified | `.github/agents/reference/domains.md`, Domain Catalog: `Magento_SalesRule` belongs to Merchandising & Promotions | High | No production change proposed |
| Required dependent domains | Mapped | Same file, Cross-Domain Adjacency Map: Merchandising & Promotions → Cart & Checkout, Order Management & Delivery, Catalog & Pricing Management | High | Concrete dependent test selectors not retrieved |
| Utility plugin/event wiring | Partial MIG result | Commands above returned `plugins: []` and `dispatches: []` | High for reported output only | Does not cover callers or one-hop downstream wiring |
| Full formal blast radius | Blocked | Current Dependency Tracer profile requires MIG plus caller, symlink, repetition, and mock-surface supplementation | High | Scout/conductor evidence and specialist dispatch unavailable |
| Root-cause mechanism | Prior hypothesis retained | Section 4 source trace supplied in persisted handoff | Not independently reproduced | No new source or runtime validation |

MIG Phase 0's lack of CALLS closure remains material. No complete symlink-aware consumer inventory,
anti-pattern repetition audit, affected-test set, or mock-surface verdict is asserted.

### Test Author preparation and blocker

The current Test Author profile requires **reuse-first discovery in MFTF → Integration → Unit order**,
scoped to the owning domain and edition, before authoring a test. It also requires integration coverage
for this money/quantity defect; a mocked unit-only cap assertion would not prove quote totals.

**PROPOSED, not selected or executed:** for this single-session calculation defect, extend suitable CE
SalesRule/bundle coverage if found; otherwise use a self-cleaning integration scenario as the cheapest
faithful new vehicle. Existing MFTF coverage must be checked first. No existing-test names or absence
claims are fabricated.

Required coverage retains the original unscoped `tag does not contain bundle` case and considers
`tag (Parent Only) contains bundle` as additional focused coverage, not a replacement.
Before authoring, confirm the children-calculated price type and rule quantity limits through actual
fixtures/source or authorized read-only environment evidence. Assert subtotal **155.00**, expected
percentage discount **23.25**, and cross-field totals/quantity invariants with an unset child tag,
selection quantity ten, and parent quantity ten. These are proposed test controls, not newly verified
ticket configuration facts.

**Precise blocker:** the exposed tool schemas have no Scout discovery/read tools, tool-schema discovery,
or specialist dispatch. The targeted capability check was the session's exposed tool inventory; no
repository search was attempted or misreported as empty. Native guidance reads and mandatory MIG
execution above were possible, but index-specific impact/affected-test evidence remains blocked.
The shared guidance directs agents lacking Scout to obtain evidence from the top-level conductor.

**Conductor action needed:** provide Scout-retrieved, domain/edition-scoped existing-test and fixture
evidence (MFTF first, then Integration/Unit as needed), targeted caller/repetition/symlink evidence,
and specialist execution access or the required specialist handoffs. This is a tooling/evidence
blocker, not a request for permission to mutate shared application state.

### Gate status

- STOP 1: explicitly released by the user's Proceed.
- Phase 0: mandatory MIG commands executed; formal report incomplete.
- Red: **not prepared or executed**. No assertion failure, test path, or PHPUnit result exists.
- STOP 2: **not reached**; continuation returned blocked before confirmed Red.
- No Green, final validation, egress, or release notes prepared.
- Only this retrospective was edited. The runbook and production/test source were left unchanged.

## 9. Test Author attempt: harness verified; regression authoring blocked

### Actual selection and source verification

- Verified branch `ACP2E-5421`; `git status --short` initially returned no entries.
- Read the current `.github/agents/test-author.agent.md` and this retrospective, including section 8.
- Used the conductor's CE SalesRule discovery; no duplicate repository-wide discovery was performed.
  The existing highest-layer match is
  `app/code/Magento/SalesRule/Test/Mftf/Test/StorefrontApplyCartPriceRuleToBundleChildProductTest.xml`.
  It covers dynamic bundle child SKU discounts but not the reported parent quantity update or tag scopes.
  Its supplied setup/cleanup includes deleting all cart rules and reindexing. These operations are not
  authorized, so the test was neither edited nor executed. No MFTF generation was necessary.
- Selected extension of the existing
  `dev/tests/integration/testsuite/Magento/SalesRule/Model/Quote/DiscountTest.php`
  as the intended executable vehicle, rather than a new test file or a mocked unit test.
  This is an explicit execution-limitation exception to highest-layer reuse: real quote computation can
  faithfully exercise this single-session calculation defect without the MFTF shared-state operations.
  The supplied related methods are
  `testBundleProductDynamicPriceWithChildDiscountAndBundleDiscount` and
  `testFixedDiscountWithCategoryConditionOnBundleAppliesToMatchingChildOnly`.
  Neither existing method was changed.
- Verified the conductor-returned integration ranges before any prospective edit.
  **UNINDEXED:** read the supplied exact bundle fixture files `Product.php`, `Link.php`, and `Option.php`
  under `app/code/Magento/Bundle/Test/Fixture/`. Product defaults explicitly use dynamic `price_type=0`.
  Link supports `qty` and `can_change_quantity`; the regression must explicitly set these to ten and one.
  These reads do not establish the reported customer's actual configuration.
- Scout and tool-schema discovery are not exposed in this worker. No Scout call, retry, or empty
  index result is claimed. Domain/edition-aware MiG test discovery remains a tooling capability gap;
  the supplied conductor discovery, not the earlier empty MiG outputs, supplied the reuse candidates.

### Commands actually executed

The initial guidance read used `python` and failed with `python: command not found` (exit 127).
It was retried successfully with `python3`; no application state was changed by those reads.
Native tool versions returned PHP **8.5.12**, PHPUnit **12.5.14**, and PHPCS **3.13.5**.

Read only the integration runtime configuration's bootstrap and relevant constants. Its actual
`TESTS_CLEANUP` value is **`disabled`**; no configuration was changed or database reset requested.

Ran this existing fixture-backed baseline with native PHP/PHPUnit:

```text
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$PWD/dev/tests/integration/testsuite/Magento/SalesRule/Model/Quote/DiscountTest.php" --filter testBundleProductDynamicPriceWithChildDiscountAndBundleDiscount
```

Result: **exit 0; OK (1 test, 4 assertions)**, time **00:04.340**, memory **181.00 MB**.
The runner reported `Integration cache backend: not configured` but completed successfully.
This verifies the existing bundle fixture flow and harness only. It is **not** the regression test,
does not assert 23.25 versus 15.50, and is **not Red**.
Only fixture-managed test activity was run; no ad-hoc DB cleanup, CLI priming, or shared-state repair
was performed.

### Precise remaining source-evidence blocker

The supplied evidence is sufficient to choose reuse and validate the harness, but does not establish
the fixture contracts needed to encode the original tag scenario without guessing.
The targeted closure capability check found no callable Scout/schema-discovery tool; therefore the
following exact missing targets are handed back to the conductor, not replaced by a native scan:

1. The existing reversible **catalog product attribute fixture**: exact indexed path and its
   apply/revert contracts, including promo-rule eligibility and optional text attribute configuration.
   Also supply `Magento\Catalog\Test\Fixture\Product::apply` / custom-attribute preparation evidence
   showing how to set only the parent's fixture-created tag and leave the child's value unset.
2. `app/code/Magento/Bundle/Test/Fixture/AddProductToCart.php`: the selection quantity/buy-request
   construction; and `app/code/Magento/Quote/Model/Quote.php`, `updateItem`: the supported quantity
   update preserving the configured selection. The regression must update a real existing quote item
   from parent quantity one to ten, not manufacture discount-calculator input.
3. `app/code/Magento/SalesRule/Test/Fixture/Rule.php`: defaults and supported overrides for an uncapped
   percentage rule; and `app/code/Magento/SalesRule/Model/Rule/Condition/Product/Combine.php` plus
   `app/code/Magento/SalesRule/Model/Rule/Condition/Product.php`: executable scope tokens/dispatch for
   unscoped, parent-only, and children-only action conditions.

**PROPOSED after that evidence:** extend the existing integration class with modern method attributes,
fixture rollback, explicit dynamic pricing, selection quantity ten with user-defined quantity enabled,
15% discount without quantity caps or competing rules, and a real parent quantity update one → ten.
Retain the original unscoped negative tag case, add parent-only positive coverage and exclusion controls,
and assert quantity, eligible percentage, discount bounds, and reconciled quote totals alongside
subtotal 155.00 / expected discount 23.25. No such test has been authored or claimed executed yet.

### Current gate and changed-file manifest

| Criterion | Status | Strongest exact evidence | Confidence | Gap |
|---|---|---|---|---|
| Existing-test reuse | Selected, not authored | Supplied MFTF match and verified integration methods above | High | MFTF setup is not authorized |
| Integration harness | Passed baseline | Native filtered PHPUnit: 1 test, 4 assertions | High | Not the reported quantity/tag scenario |
| Regression Red | Blocked before authoring | Missing fixture/update/scope contracts listed above | High | No 23.25-versus-15.50 assertion executed |
| STOP 2 | Not reached | No confirmed assertion Red | High | Return to conductor for targeted evidence |
| Production implementation | Not started | No production or test source edits | High | Formal Phase 0 remains incomplete |

This attempt changes **only** `docs/retrospectives/ACP2E-5421/retrospective.md`.
No PHP was touched, so PHP syntax/PHPCS checks have no changed PHP target.
No agents, production fix, Green, commits, staging, Jira changes, release notes, or dependent-suite
validation were performed. Stop here; this is a blocker handoff, not permission to implement.

## 10. Conductor continuation: original scenario reproduced; STOP 2 reached

This section supersedes the earlier blocked Red statuses. No production implementation was made.
The conductor supplied the missing fixture contracts and completed the test directly after the runtime
rejected a same-task message to the synchronous Test Author (`write_agent` supports background agents only).
No replacement agent was launched.

### Persisted regression and verified behavior

Extended the existing integration class:

`dev/tests/integration/testsuite/Magento/SalesRule/Model/Quote/DiscountTest.php`

New method: `testIncreaseBundleQuantityPreservesPercentageDiscount`.
The MFTF discovery and execution-limitation rationale in section 9 still applies. No new test file,
MFTF edit, broad rule deletion, reindex, database reset, or ad-hoc application-state repair was needed.

The test uses reversible fixtures for a promo-enabled optional text attribute, simple priced **1.55**,
dynamic-price bundle, selection quantity **10**, user-defined selection quantity enabled, uncapped
**15%** rule, and a guest quote. Only the bundle is assigned the attribute value `bundle`.
The action condition preserves the ticket's original **unscoped `does not contain bundle`** configuration.
The fixture's generated unique attribute code replaces the literal name `tag`, avoiding shared attribute
collisions; its text type, value and comparison semantics are retained.
Simple fixture stock is **1000**, preventing an unrelated stock-availability exception during the update.

At parent quantity **1**, subtotal **15.50** and discount **2.33** pass. The test updates the existing item
to parent quantity **10** through `Quote::updateItem`, saves and reloads the quote, then collects totals.
Parent quantity **10**, child quantity **10**, effective child quantity **100**, subtotal **155.00**,
child/address discount reconciliation and the grand-total accounting invariant all pass.
The final percentage assertion fails with **actual 15.50 versus expected 23.25**.
This is an executed assertion Red for the original scenario, not merely a static hypothesis or harness error.

### Actual verification commands and results

```text
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$PWD/dev/tests/integration/testsuite/Magento/SalesRule/Model/Quote/DiscountTest.php" --filter 'testIncreaseBundleQuantityPreservesPercentageDiscount|testBundleProductDynamicPriceWithChildDiscountAndBundleDiscount'
```

Result: **exit 1; 2 tests, 16 assertions, 1 failure**, time **00:06.818**, memory **161.00 MB**.
The existing baseline passes; only the new regression fails:

```text
The percentage discount must use the child quantity multiplied by the parent quantity.
Failed asserting that 15.5 matches expected 23.25.
```

```text
php -l dev/tests/integration/testsuite/Magento/SalesRule/Model/Quote/DiscountTest.php
vendor/bin/phpcs --standard=Magento2 --severity=8 dev/tests/integration/testsuite/Magento/SalesRule/Model/Quote/DiscountTest.php
git --no-pager diff --check
```

All three checks **passed, exit 0**. PHPCS emitted existing standard/tokenizer deprecation notices,
not target-file violations at the requested severity. The full integration suite and dependent-domain
suites were **not run** at this Red gate.

### Authoring attempts, evidence limitations and retained scope

Earlier iterations failed fixture/setup assertions and once raised `Not enough items for sale`;
these were not counted as Red. Parent attribute persistence, a correctly typed condition and sufficient
fixture stock were established before the final assertion failure above.

An exploratory three-case provider also tried parent-only positive and negative conditions.
Initial quantity-one eligibility matched the clarification, but after the update the positive case
produced zero discount and the negative case produced 15.50. Those results do **not** independently
confirm the reported parent-only-positive 15.50 cap. Their eligibility/loading difference remains
unresolved; these exploratory cases were removed rather than conflating them with the retained
original-scenario regression or expanding the fix scope. No automated parent-only control is claimed.
The user's parent-only observations remain recorded in the intake clarification.

Scout supplied the attribute, rule-fixture and scope-serialization ranges. Some known fixture/model
files were refused by its range reader as `not_indexed`, despite symbol outlines being available.
**UNINDEXED:** narrowly scoped native reads supplied the unavailable bundle/cart/product and abstract
rule contracts. They do not establish complete Scout indexing or a complete impact graph.

### Current gate

| Phase / gate | Current state |
|---|---|
| STOP 1 | Released by the user's Proceed |
| Formal Phase 0 | Still incomplete; partial MIG results in section 8 are not a complete blast-radius verdict |
| Regression Red | Confirmed for the original unscoped negative-condition scenario |
| STOP 2 | Reached; awaiting explicit human approval |
| Production fix / STOP 3 | Not started |
| Consensus / final egress / STOP 4 | Not started |

This continuation changed the existing integration test and this retrospective only. Nothing was staged
or committed. **Do not implement the fix before STOP 2 approval and completion of the mandatory Phase 0
consumer/symlink/repetition and dependent-test report.**

## 11. STOP 2 approved; pre-implementation Phase 0 report

The user's subsequent **Proceed** releases STOP 2 only. STOP 3 and the consensus/egress gate remain mandatory.
This report was completed before editing production code.

### Source state, indexing and topology

Branch remains `ACP2E-5421`; the integration regression is the only tracked modification at this point.
Scout lists this attachment on the correct branch with **42075/42972 files indexed (97.9%)**.
Two `ensure_fresh` calls both timed out. No fresh-index or graph-completeness claim is made.
**UNINDEXED:** the following closure uses narrow native source reads and local, symlink-following searches.
No index-specific affected-test result is claimed.

Resolved **185 module-root symlinks** under `app/code/Magento`: **125 EE**, **60 B2B**, no broken links.
Their targets remain inside this repository under `magento2ee/` and `magento2b2b/`.
`ext/magento/inventory` exists and was explicitly included alongside CE and both edition roots.
The caller/override search followed symlinks and included all four roots.

### Mandatory MIG receipts

All commands below exited **0**:

```text
php dev/tools/impact-graph/bin/impact-graph analyze --diff HEAD --format=json
php dev/tools/impact-graph/bin/impact-graph analyze --mock-impact HEAD --format=json
php dev/tools/impact-graph/bin/impact-graph analyze --plugins-subject 'Magento\SalesRule\Model\Utility' --format=json
php dev/tools/impact-graph/bin/impact-graph analyze --dispatches app/code/Magento/SalesRule/Model/Utility.php --format=json
php dev/tools/impact-graph/bin/impact-graph analyze --event salesrule_validator_process --format=json
php dev/tools/impact-graph/bin/impact-graph analyze --plugins-subject 'Magento\SalesRule\Model\RulesApplier' --format=json
```

MIG reported empty changed/mock-impact/plugin/dispatch/observer lists at this pre-production-edit stage.
The diff result does not enumerate the changed integration test, so it is not treated as an exhaustive
worktree inventory. Native diff/caller/mock checks supply that limitation; post-edit MIG must be rerun.

### Blast radius and repeated-pattern assessment

| Criterion | Evidence and verdict |
|---|---|
| Owning domain | Merchandising & Promotions: `Magento_SalesRule`, per `.github/agents/reference/domains.md` |
| Required dependent domains | Cart & Checkout; Order Management & Delivery; Catalog & Pricing Management, from the same adjacency map |
| Production call site | `app/code/Magento/SalesRule/Model/RulesApplier.php:365` is the only `minFix` production call found across CE, MSI and linked editions; no override or before/after/around-minFix implementation found |
| Caller branches | `RulesApplier.php:255,267,273` route child-calculated, parent-distributed and ordinary item discounts through `getDiscountData` |
| Quantity producer | `Utility.php:380-384` starts calculator quantity from `getTotalQty`, optionally applying the rule's discount quantity limit |
| Quantity contract | `app/code/Magento/Quote/Model/Quote/Item/AbstractItem.php:402-407`: own quantity multiplied by parent quantity, or unchanged own quantity without a parent |
| One-hop event | `RulesApplier.php:524-525` dispatches `salesrule_validator_process` before rounding and the cap; targeted MIG returned no observer/plugin edges |
| Proposed body-only change | Both display/base ceilings in `Utility::minFix` use effective item quantity. Preserve price helpers, accumulated discounts and signatures; do not substitute tax-exclusive row totals |
| Mock surface | `UtilityTest.php:1032-1058` executes the real method, while its item mock already mocks `getTotalQty` and therefore needs an explicit return. `RulesApplierTest.php:110` mocks `minFix` itself; the new internal call is not executed there |
| Repetition within Utility | Both currency ceilings repeat the same erroneous own-quantity cap and must change together |
| Repetition elsewhere | `Rule/Action/Discount/ByFixed.php:30-32` has a similar earlier fixed-action cap. It is an in-scope candidate assessed here, but is not executed by the percentage action and its own earlier ceiling continues to limit fixed results. No fixed-calculator change is required to correct this ticket; retain it as a separate uncovered scenario, not a claim that all bundle-discount modes are fixed |
| Other quantity reads | `ByPercent.php:64-70` prorates previous discounts for rule quantity limits; `BuyXGetY` uses quantities for its own step semantics. Neither is the final price ceiling, and neither is changed |

### Deterministic dependent-test selection

These MIG commands all exited **0** and selected actual repository tests:

```text
php dev/tools/impact-graph/bin/impact-graph analyze --mftf-module Magento_Checkout --format=json
php dev/tools/impact-graph/bin/impact-graph analyze --mftf-module Magento_Sales --format=json
php dev/tools/impact-graph/bin/impact-graph analyze --mftf-module Magento_Catalog --format=json
```

They returned **133**, **148**, and **326** tests respectively. Full JSON receipts are session artifacts,
not new repository files. Select these relevant acceptance representatives for the dependent-domain gate:

| Domain | Selected existing MFTF test |
|---|---|
| Cart & Checkout | `StorefrontAddBundleDynamicProductToShoppingCartTest` |
| Order Management & Delivery | `AdminCreateOrderWithBundleProductTest` |
| Catalog & Pricing Management | `AdminChecksIfOnlyOneQuantityConfigurationIsDisplayedForBundleProductWhileCreatingAnOrderTest` |

These are **selected, not executed**. MFTF requires the documented application priming and shared-state
operations; those remain approval-gated. MSI/B2B/EE have no direct minFix override/caller from the local
inventory above; that does not remove any dependent-suite obligations at consensus.

**Phase 0 outcome:** sufficient bounded caller, topology, repetition, mock and dependent-test evidence to
make the body-only percentage-cap correction. Verification remains incomplete until targeted Green and,
after STOP 3 approval, the independent consensus/static/dependent-domain gate. This is not a merge verdict.

## 12. Green implementation and verification; STOP 3 reached

### Persisted change

`app/code/Magento/SalesRule/Model/Utility.php`, `minFix`, now obtains `getTotalQty()` once and uses that
quantity for both display-currency and base-currency ceilings. This aligns the final ceiling with the
effective child quantity already used by the percentage calculator. Standalone quantities are unchanged.
Existing price helpers, tax-sensitive discount calculation prices, accumulated discounts and method
signatures are preserved. No new dependencies, plugins, configuration, translated phrases or filesystem
writes are introduced.

Extended the existing `UtilityTest::testMinFix` with five datasets: standalone discounts below/at the cap,
child discounts below/at the effective cap, and a fractional parent quantity. The test uses different
display/base prices and explicitly stubs the newly called `getTotalQty` collaborator.
The existing integration reproducer remains unchanged behaviorally; its trailing blank line was removed
to satisfy the full coding standard.

### Red before the production edit

```text
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml app/code/Magento/SalesRule/Test/Unit/Model/UtilityTest.php --filter testMinFix
```

Result: **exit 1; 5 tests, 11 assertions, 3 failures, 2 PHPUnit notices**.
The two standalone datasets passed. The child/fractional datasets demonstrated the incorrect own-quantity
ceiling before implementation. This supplements, rather than replaces, the integration Red in section 10.

### Green receipts

```text
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$PWD/dev/tests/integration/testsuite/Magento/SalesRule/Model/Quote/DiscountTest.php" --filter 'testIncreaseBundleQuantityPreservesPercentageDiscount|testBundleProductDynamicPriceWithChildDiscountAndBundleDiscount|testFixedDiscountWithCategoryConditionOnBundleAppliesToMatchingChildOnly'
```

Result: **exit 0; OK (3 tests, 19 assertions)**, time **00:09.173**, memory **175.00 MB**.
The original scenario now produces **23.25** discount on **155.00** subtotal. The existing mixed-discount
dynamic-bundle baseline and fixed-discount/category bundle test also pass.

```text
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml app/code/Magento/SalesRule/Test/Unit/Model --filter '/(UtilityTest|RulesApplierTest|ByFixedTest)::/' --display-phpunit-notices
```

Result: **exit 0; 51 tests, 214 assertions, no failures or errors**, time **00:00.125**, memory **32.00 MB**.
All three named classes were discovered and executed. PHPUnit reported notices for mocks without
expectations throughout the existing setups, including unchanged ByFixed and RulesApplier coverage:
**51 tests triggered 583 individual notice messages**, summarized by the runner as **51 PHPUnit notices**.
These notices were inspected and not suppressed or treated as a clean-notice result.

```text
php -l app/code/Magento/SalesRule/Model/Utility.php
php -l app/code/Magento/SalesRule/Test/Unit/Model/UtilityTest.php
php -l dev/tests/integration/testsuite/Magento/SalesRule/Model/Quote/DiscountTest.php
vendor/bin/phpcs --standard=Magento2 app/code/Magento/SalesRule/Model/Utility.php app/code/Magento/SalesRule/Test/Unit/Model/UtilityTest.php dev/tests/integration/testsuite/Magento/SalesRule/Model/Quote/DiscountTest.php
git --no-pager diff --check
```

All commands **passed, exit 0**. Unlike the earlier severity-8-only Red check, this PHPCS run used the full
Magento2 standard. Its first run found the integration class's trailing blank-line warning, which was
fixed; the replacement run has no file violations. Standard/tokenizer deprecation notices remain.
An added-line check also found **zero PHP lines over 120 characters**.

### Post-edit impact and remaining verification

Post-edit `analyze --diff HEAD --format=json` identifies Utility and UtilityTest, with no reported
plugin/dispatch/observer edges. The tool still does not enumerate the integration test.
`analyze --mock-impact HEAD --format=json` flags `testMinFix` for the newly introduced `getTotalQty` call.
The flag is manually resolved for the current worktree by the explicit
`expects(...)->method('getTotalQty')->willReturn($totalQty)` stub and passing dataset execution; the
tool's reported flag is retained here rather than misrepresented as an empty result.

The full static suite, full integration suite and selected dependent-domain MFTF tests have **not run**.
They belong to the subsequent consensus gate, not to an asserted final acceptance result.
The parent-only exploratory eligibility/loading discrepancy and the separate fixed-calculator pre-cap
candidate from sections 10–11 remain limitations; this change proves the original percentage scenario,
not every bundle rule mode.

| Phase / gate | Current state |
|---|---|
| STOP 2 | Released by explicit Proceed |
| Phase 0 | Report completed before production edit, with disclosed Scout/MIG limitations |
| Targeted Green | Confirmed: original percentage regression and relevant existing coverage pass |
| STOP 3 | Reached; awaiting explicit human approval |
| Consensus Gateway / dependent-domain verification | Not started |
| Final egress / STOP 4 | Not started |

No commit, staging, Jira update, environment reset or runbook edit was performed.
**Stop here: do not advance to consensus or final egress without STOP 3 approval.**

## 13. STOP 3 approved; consensus NOT-GREEN / pending acceptance

The user's subsequent **Proceed** releases STOP 3. The conductor dispatched the Consensus Gateway
for independent validation, with explicit boundaries against shared application mutations, resets,
daemon lifecycle changes, commits and source edits. The worker returned **NOT-GREEN / PENDING**.
No runtime usage block was returned; token/tool accounting is unavailable rather than reconstructed.

### Independent gateway receipts

| Check | Result |
|---|---|
| Syntax of all three changed PHP files | Passed, exit 0 |
| Full Magento2 PHPCS on each changed PHP file | Passed, exit 0; standard deprecation advisories only |
| `git --no-pager diff --check` | Passed, exit 0 |
| `vendor/bin/phpunit -c dev/tests/static/phpunit.php.xml` | Exit 0; 6 tests, 5 assertions, 1 skipped |
| Full existing `DiscountTest.php` integration class | Exit 0; 17 tests, 127 assertions |
| Required MIG diff/mock-impact commands | Exit 0; getTotalQty mock flag manually reconciled with the explicit current stub |
| Multi-root MFTF XML working-tree change check | No changed XML reported; generation trigger not met |
| Selected dependent-domain MFTF execution | Not run; blocking |

Full integration command:

```text
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$PWD/dev/tests/integration/testsuite/Magento/SalesRule/Model/Quote/DiscountTest.php"
```

The gateway inspected the existing static configuration and reported it matches the supplied template
without the Allure extension. It did not overwrite that file or create temporary configuration.
No separate validation log files were created.

### Conductor spot-check and acceptance blockers

Independently reran a concrete gateway claim:

```text
vendor/bin/phpunit -c dev/tests/static/phpunit.php.xml --display-skipped
```

Result: **exit 0; 6 tests, 5 assertions, 1 skipped**, time **00:06.943**, memory **68.50 MB**.
The skipped check is `Magento\Test\Php\LiveCodeTest::testCopyPaste`:
**`PHP Copy/Paste Detector is not available.`**
This confirms the command receipt but is not a claim that every static check executed.

Read-only readiness probe:

```text
curl --silent --show-error --max-time 5 http://127.0.0.1:4444/status
```

Result: **exit 0**, HTTP response body contains `ready: false`, `Selenium Grid not ready.`,
and `nodes: []`. No service was started or stopped.

**UNINDEXED:** narrow reads of the selected acceptance XML and helper implementation establish
additional shared-state risks:

- `StorefrontAddBundleDynamicProductToShoppingCartTest` changes shipping configuration, invokes
  `AdminCartPriceRuleDeleteAllActionGroup`, and reindexes stock during setup.
- `AdminCreateOrderWithBundleProductTest` changes shipping setup and creates an order.
- `AdminChecksIfOnlyOneQuantityConfigurationIsDisplayedForBundleProductWhileCreatingAnOrderTest`
  invokes `ProductApiHelper::deleteAllProductsApi` during teardown. The helper's default page size is
  50; `getAllProducts` requests the first product page without a fixture-SKU filter, and
  `processProductDeletion` deletes every returned SKU. Thus its cleanup can delete unrelated products,
  not just the test's own fixtures. It is not safe to treat this as fixture-scoped cleanup.

No selected MFTF test, reindex/cache priming command, unfiltered deletion or application reset was run.
Broad operational permission is not inferred from approval to begin consensus.
The parent-only exploratory discrepancy, fixed-calculator candidate, and copy/paste-check skip remain
disclosed limitations.

### Gate state

**Consensus remains NOT-GREEN / PENDING.** STOP 4 is not reached. Final developer-summary/release-note
egress is not prepared as an accepted result. A safe acceptance target and operational authorization
are required before the remaining browser tests can run; current code and tests remain intact and
uncommitted.

The user selected **an existing isolated/disposable MFTF environment**, not destructive execution on the
current instance. A non-secret configuration check found only the current acceptance `.env` target,
`http://magento1.com/`, with Chrome; no alternate profile was supplied. The conductor has not repointed
MFTF or started services. Await the isolated target/profile before any acceptance mutation.
