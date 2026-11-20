# Retrospective: GraphQL `addProductsToCart` UNDEFINED error codes — ACP2E-5537 (backport)

*BDAD Swarm run record. Objective: port the ACP2E-5536 behavior to the 2.4.7-p10 patch line without importing an architecture that branch does not have.*

| | |
|---|---|
| **Current version** | v1.0 |
| **Last updated** | 2026-11-11 |
| **Ticket** | [ACP2E-5537](https://jira.corp.adobe.com/browse/ACP2E-5537) — Backport Request, `p2e_commerce_escalation` |
| **Parent** | [ACP2E-5536](https://jira.corp.adobe.com/browse/ACP2E-5536) (2.4-develop, tier4 PR #914, commit `be08a378368`) |
| **Related** | ACSD-72488, ACSD-72321, MSRCH-5731 (Live Search PLP widget) |
| **Target branch** | `magento-sparta/magento2ce:v2.4.7-p10` (base `72561bf8065`, "Magento Release 2.4.7-p10") |
| **Work branch** | `v2.4.7-p10-ACP2E-5537-fix` |
| **Commit** | `1ab380e9d09` (production-only) |
| **Superseded** | `ACP2E-5537-2.4.7-p10` @ `430fc5fbd29` (wrong base, deleted) · `v2.4.7-p10-ACP2E-5537` @ `eeaf4ce7d34` (PR #3787, failed hygiene eval) |
| **Status legend** | ✅ done · 🟡 partial · ⬜ not started · 🔎 resolved by investigation |

### Revision history
| Version | Date | Change |
|---|---|---|
| v1.0 | 2026-11-11 | Initial record: manual backport of ACP2E-5536 to 2.4.7-p10 after the automated backport tool failed. Documents the DI-vs-constant architectural divergence that defeated the tool, and one latent GraphQL enum regression found and closed before Green was accepted. |
| v1.1 | 2026-11-12 | Corrected the delivery target twice. (a) The fix was first based on `origin/2.4.7-p10-develop`, which is **772 commits ahead** of sparta's released `v2.4.7-p10` line, producing an unreviewable PR diff; rebased onto the sparta release commit. (b) The resulting PR #3787 then failed the backport hygiene eval for including a test file; release-line backports on sparta carry production files only. Test archived alongside this document. See §9. |

> **How to read this doc:** §0–§3 are the fixed record of the backport. §4 is the most valuable part — three defects that a mechanical cherry-pick would have shipped. §5–§7 cover verification, deliberate divergences from upstream, and follow-ups.

---

## 0. Executive summary

`addProductsToCart` returns `code: UNDEFINED` for validation messages that have no entry in the error-code mapping, most visibly *"The requested qty exceeds the maximum qty allowed in shopping cart"*. Clients that branch on the code — notably the Live Search PLP "Add to Cart" widget — cannot distinguish an actionable validation failure from an unknown one, so shoppers see a generic *"Something went wrong"* instead of the real reason.

ACP2E-5536 fixed this on 2.4-develop by editing the DI-configured `errorMessageCodesMapper` argument in `Magento_Quote/etc/di.xml`. **That file shape does not exist on 2.4.7-p10**, which is why the automated backport tool failed and the ticket was moved for manual backport.

On 2.4.7-p10 the same mapping lives in a hardcoded `AddProductsToCartError::MESSAGE_CODES` constant. This backport ports the *behavior* into that constant, and additionally extends two GraphQL enums that would otherwise have made the new codes unserializable.

**Net result:** 1 production class + 2 GraphQL schemas changed, 1 new unit test file, 13 insertions / 1 deletion of production code.

## 1. The execution journey (Red → Green → Consensus)

| Phase | Action | Outcome |
|---|---|---|
| Intake | Glean-retrieved ACP2E-5537; identified it as a backport of ACP2E-5536, fetched tier4 PR #914 as `pr914-acp2e-5536` (commit `be08a378368`, 3 files). | ✅ Parent fix located and read |
| Setup | Found a pre-existing local `ACP2E-5537` branch — based on a 2.4-develop merge train, containing **no** ticket commits. Left untouched; branched `ACP2E-5537-2.4.7-p10` from `origin/2.4.7-p10-develop` per the repo's backport naming precedent (`ACP2E-4853-2.4.7-p2`). | ✅ No destructive action on a stale branch |
| P0 — Reproduce the tool failure | `git cherry-pick -x be08a378368` → 3 conflicts: `di.xml` content conflict, plus **modify/delete** on both test files. | 🔎 Confirmed the tool's failure mode first-hand rather than trusting the ticket prose |
| P0 — Divergence analysis | `AddProductsToCartError` exists on p10 but with **no** `errorMessageCodesMapper` DI argument; the mapping is a `private const MESSAGE_CODES`. Cherry-pick aborted; switched to a manual behavior port. | 🔎 Root cause of the tool failure |
| R1 — Red | New `AddProductsToCartErrorTest` written against p10's actual API (`new AddProductsToCartError()`, `create(string, int)`), covering the 5 ACP2E-5536 mappings, 2 added gap mappings, 4 pre-existing mappings as regression guards, the `UNDEFINED` fallback, and message/position pass-through. | ❌→ Failed exactly on the ticket's symptom: `"max sale qty exceeded"` expected `INSUFFICIENT_STOCK`, got `UNDEFINED` (3 tests, 4 assertions, 1 failure) |
| G1 — Green | Ported the mapping into `MESSAGE_CODES`; added `ERROR_REQUIRED_PARAMETER_MISSING` and `ERROR_INVALID_PARAMETER_VALUE` constants. | ✅ `OK (3 tests, 17 assertions)` |
| G2 — Cross-domain gate | Traced consumers of `AddProductsToCartError` → `Quote\Model\Cart\AddProductsToCart` → **two** GraphQL resolvers (`QuoteGraphQl\...\AddProductsToCart` and `WishlistGraphQl\...\Wishlist\AddToCart`). Extended both enums. | 🔎 Caught a regression the parent fix does not fully guard against (see §4.2) |
| V1 — Validate | `php -l` ✅ both PHP files · PHPCS `Magento2` ✅ clean on all 4 files (incl. `.graphqls`) · pre-commit diff-only lint ✅ | ✅ |
| V2 — Regression baseline | Full `Magento/Quote/Test/Unit/` suite: baseline **322 tests / 319 errors**, post-change **325 tests / 319 errors**. | ✅ Error count unchanged; delta is exactly the 3 new passing tests |

## 2. Manual steps to reproduce

**Preconditions:** a simple product with sufficient stock; in *Advanced Inventory*, disable *Use Config Settings* for **Maximum Qty Allowed in Shopping Cart** and set it to `5`. Single request; no concurrency (`repro-class: single-session`).

1. Create a guest cart:
   ```graphql
   mutation { createEmptyCart }
   ```
2. Add a quantity above the configured maximum:
   ```graphql
   mutation {
     addProductsToCart(
       cartId: "YOUR_GUEST_CART_ID"
       cartItems: [{ sku: "YOUR_SKU", quantity: 6 }]
     ) { user_errors { code message } }
   }
   ```

**Expected:** `code` is `INSUFFICIENT_STOCK`, consistent with the existing classification of maximum-quantity restrictions.

**Actual (before this fix):**
```json
{ "code": "UNDEFINED", "message": "The requested qty exceeds the maximum qty allowed in shopping cart" }
```

Additional messages that returned `UNDEFINED` before this fix: decimal quantity, quantity increments, non-positive quantity, malformed custom-option data, missing required options, and *"Not enough items for sale"*.

## 3. The change

| File | Change |
|---|---|
| `app/code/Magento/Quote/Model/Cart/AddProductsToCartError.php` | +2 code constants; `MESSAGE_CODES` broadened by 1 entry and extended by 6 |
| `app/code/Magento/QuoteGraphQl/etc/schema.graphqls` | `CartUserInputErrorType` += `REQUIRED_PARAMETER_MISSING`, `INVALID_PARAMETER_VALUE` |
| `app/code/Magento/WishlistGraphQl/etc/schema.graphqls` | `WishlistCartUserInputErrorType` += `REQUIRED_PARAMETER_MISSING`, `INVALID_PARAMETER_VALUE` |
| ~~`app/code/Magento/Quote/Test/Unit/Model/Cart/AddProductsToCartErrorTest.php`~~ | 3 tests, 17 assertions — **not shipped in the backport PR** (§9); archived at [`AddProductsToCartErrorTest.php`](./AddProductsToCartErrorTest.php) |

The key behavioral edit is broadening `'The requested qty is not available'` to the prefix `'The requested qty'`, which is what makes the reported max-qty message match. This mirrors upstream exactly, and the broadened prefix is what the `updateCartItems` mapping already used — the inconsistency between the two mappings is the original defect.

Full diff: [`ACP2E-5537.diff`](./ACP2E-5537.diff).

## 4. What a mechanical cherry-pick would have shipped

This is the part worth keeping. Three independent defects were caught, none of which the diff itself reveals.

### 4.1 The DI-vs-constant divergence (the tool's actual failure)

2.4-develop made the mapping DI-configurable; 2.4.7-p10 has not received that refactor.

| | 2.4-develop | 2.4.7-p10 |
|---|---|---|
| Mapping location | `errorMessageCodesMapper` argument in `Quote/etc/di.xml` | `private const MESSAGE_CODES` |
| `create()` signature | `(string\|Phrase $msg, int $pos = 0, ?float $qty = null)` | `(string $msg, int $pos = 0)` |
| Untranslated-`Phrase` code resolution | yes | no |
| Rendered-number normalization | yes | no |

Porting the `di.xml` hunk verbatim would have produced a DI argument for a constructor parameter that does not exist. **Lesson:** for a backport, verify the *mechanism* exists on the target branch, not just the file.

### 4.2 Missing GraphQL enum values — a latent runtime break

`user_errors.code` is typed `CartUserInputErrorType!`. On p10 that enum contained only `PRODUCT_NOT_FOUND`, `NOT_SALABLE`, `INSUFFICIENT_STOCK`, `UNDEFINED`. Emitting `INVALID_PARAMETER_VALUE` from a non-nullable enum field fails GraphQL serialization — so the mapping change alone would have converted a *wrong error code* into a *failed mutation*, a strictly worse outcome.

Worse, the blast radius is wider than the ticket implies: `WishlistGraphQl\Model\Resolver\Wishlist\AddToCart` consumes the **same** `AddProductsToCartError` through `Quote\Model\Cart\AddProductsToCart`, but serializes into a *separate* enum, `WishlistCartUserInputErrorType`. Adding products to the cart from a wishlist with a decimal quantity would have broken `addWishlistItemsToCart`.

**This gap is only partly closed upstream.** 2.4-develop's `WishlistCartUserInputErrorType` has `REQUIRED_PARAMETER_MISSING` but **not** `INVALID_PARAMETER_VALUE` — so 2.4-develop still carries the wishlist-path exposure today. See §7.

### 4.3 The upstream test is unrunnable on this branch

The parent's `AddProductsToCartErrorTest` cannot be ported as-is:

- it constructs `new AddProductsToCartError($mapper)` — p10's constructor takes no arguments;
- it asserts on `Phrase` handling and rendered-number normalization — neither exists on p10;
- it reads the mapping back out of `di.xml` by XPath — there is no such configuration on p10;
- it uses `#[DataProvider]`, a **PHPUnit 10+** attribute, while p10 pins `phpunit/phpunit: ^9.5`.

The replacement test was therefore written fresh against p10's real API, and deliberately **avoids data providers entirely** (asserting over an inline array in a loop). Annotation-based providers were removed in PHPUnit 12 and attribute providers do not exist in 9.5; a loop is the only form valid under both, which matters because the local toolchain and the branch's pinned toolchain disagree (see §5).

## 5. Verification

| Check | Command | Result |
|---|---|---|
| Syntax | `php -l` on both PHP files | ✅ No syntax errors |
| Standards | `vendor/bin/phpcs --standard=Magento2` on all 4 changed files | ✅ Clean (only the standard's own deprecated-sniff warning) |
| Unit (SUT) | `vendor/bin/phpunit -c dev/tests/unit/phpunit.xml app/code/Magento/Quote/Test/Unit/Model/Cart/AddProductsToCartErrorTest.php` | ✅ `OK (3 tests, 17 assertions)` |
| Red proof | same command, before the fix | ✅ Failed on `"max sale qty exceeded"`: expected `INSUFFICIENT_STOCK`, got `UNDEFINED` |
| Regression | `vendor/bin/phpunit -c dev/tests/unit/phpunit.xml app/code/Magento/Quote/Test/Unit/` | ✅ 319 errors before **and** after; `322 → 325` tests |
| Pre-commit | repo diff-only lint hook | ✅ Passed |

### Environment caveat (must be read before trusting the regression numbers)

The local `vendor/` is installed from **2.4-develop** (PHPUnit 12.5.14 / PHP 8.5.12), while 2.4.7-p10 pins **PHPUnit ^9.5 / PHP ~8.1–8.3**. The 319 baseline errors are overwhelmingly `Call to undefined method MockBuilder::getMockForAbstractClass()` — an API removed in PHPUnit 12 — i.e. **toolchain mismatch, not branch breakage**. This was proven by stashing the change and re-running: the error count is byte-identical at 319 before and after.

Consequences:
- The regression evidence is a *delta* argument, not an absolute green suite. ⚠️
- The new test is written to be valid under both 9.5 and 12, so CI on p10 should run it unmodified — but it has **not** been executed against a real PHPUnit 9.5 install locally. 🟡
- `./setup.sh ACP2E-5537` would rebuild a branch-correct environment; not run here because the fix is unit-level and the toolchain delta was provably neutral.

**Not run:** integration and API-functional tests. Upstream's api-functional `GraphQl/Quote/AddProductsToCartTest.php` **does not exist on 2.4.7-p10** and was scoped out by decision (§6).

## 6. Deliberate divergences from the parent fix

| Decision | Rationale |
|---|---|
| Port into `MESSAGE_CODES`, not `di.xml` | The DI mechanism does not exist on p10 (§4.1). Behavior parity was chosen over architectural parity to keep a patch-release change small. |
| **Added** `'Not enough items for sale'` and `'You need to choose options for your item.'` | Present upstream before ACP2E-5536 but absent on p10, so the escalated customer would otherwise still get `UNDEFINED` for them. Both match literally under `stripos`, needing no new machinery. |
| **Excluded** `'Only %s of %s available'` | Upstream matches this only because ACP2E-5536's branch normalizes rendered numbers before lookup. p10's plain `stripos()` can never match the literal `%s` placeholder against a rendered `"Only 5 of 6 available"`. Mapping it here would be **dead configuration that falsely signals coverage**. Requires the normalization refactor first. |
| **Added** `INVALID_PARAMETER_VALUE` to `WishlistCartUserInputErrorType`, which upstream lacks | Refusing to port a known runtime break into a patch release (§4.2). Enum additions are backward-compatible for clients. |
| Unit test only; no api-functional test | Per scope decision. The api-functional file does not exist on p10 and creating it from scratch exceeds backport scope. |
| Stale local `ACP2E-5537` branch left untouched | It contained no ticket work and sat on the wrong base; renaming/deleting another session's branch is out of scope. |

## 7. Follow-ups

1. **Upstream gap (2.4-develop):** `WishlistCartUserInputErrorType` is missing `INVALID_PARAMETER_VALUE` while the shared `AddProductsToCartError` can emit it. `addWishlistItemsToCart` can therefore fail serialization on 2.4-develop for decimal-quantity / qty-increment / malformed-option errors. Worth raising as its own ticket against the parent. ⬜
2. **Verify on the branch-correct toolchain:** run the new test under PHPUnit 9.5 / PHP 8.1–8.3 (`./setup.sh ACP2E-5537`) before merge, to close the §5 caveat. 🟡
3. **`'Only %s of %s available'` remains unmapped on p10** — revisit if the rendered-number normalization is ever backported. ⬜
4. **QE (Kateryna Muntianu):** the manual repro in §2 is the acceptance path; also smoke `addWishlistItemsToCart` with a decimal quantity, since that path is newly reachable with specific codes. ⬜
5. **MSRCH / Live Search PLP widget:** the codes the widget plans to handle (`PRODUCT_NOT_FOUND`, `NOT_SALABLE`, `REQUIRED_PARAMETER_MISSING`, `INVALID_PARAMETER_VALUE`) are now emitted on 2.4.7-p10, except `COULD_NOT_FIND_CART_ITEM`, which has no mapping on this branch and is out of this ticket's scope. ⬜

## 8. BDAD process notes

- **The cherry-pick was worth running even though it was known to fail.** It produced the precise conflict set (`modify/delete` on both tests, content conflict on `di.xml`) in one command, which located the divergence faster than reading either branch cold.
- **The highest-value finding came from the consumer trace, not the diff.** Following `AddProductsToCartError` → `AddProductsToCart` → resolvers → schema enums is what surfaced §4.2. A backport review that stops at "do the changed files apply?" would have missed it entirely, because the breaking file (`WishlistGraphQl/etc/schema.graphqls`) is not in the parent commit at all.
- **Toolchain drift between `vendor/` and the target branch is a standing hazard for backports.** It silently invalidates absolute test results; only the stash/re-run delta made the numbers trustworthy. A branch-correct `./setup.sh` before Red would have avoided the caveat in §5.
- **Mandatory stop points honored:** intake ✅, Red ✅, Green ✅, egress ✅ — all four surfaced for human approval before proceeding.

## 9. Delivery corrections (v1.1) — two wrong targets before the right one

The fix itself never changed. Both corrections were about *where* and *in what shape* it was delivered, and both were caught after a push.

### 9.1 Wrong base line: `-develop` is not the release line

`magento-commerce/magento2ce:2.4.7-p10-develop` (the ongoing development line) is **772 commits ahead** of `magento-sparta/magento2ce:v2.4.7-p10` (the frozen released patch line). Branching from the former produced a PR whose diff was dominated by 772 unrelated commits.

| Line | Ref | Role |
|---|---|---|
| magento-commerce | `2.4.7-p10-develop` @ `c145e7d7585` | ongoing development |
| sparta | `v2.4.7-p10` @ `72561bf8065` ("Magento Release 2.4.7-p10") | **released patch line — the backport target** |

Note the naming differs across remotes (`2.4.7-p10-develop` vs `v2.4.7-p10`), which is exactly what makes this easy to get wrong. Sparta ticket branches follow `v2.4.7-p10-<TICKET>` (precedent: `v2.4.7-p10-ACP2E-4492`).

**Re-verified on the sparta base rather than assumed** — all preconditions held identically: `MESSAGE_CODES` constant shape, both enums missing the new codes, `phpunit ^9.5` / `php ~8.1–8.3`, no pre-existing test file. The cherry-pick was byte-identical and the regression delta re-measured against `72561bf8065` (322 → 325 tests, 319 errors unchanged).

### 9.2 Backport hygiene: release-line PRs carry production files only

The backport eval (`shadow v1`, execution `91908eb9-8bca-48eb-9873-98032b583e44`) rejected PR #3787:

> Backport hygiene check failed for `app/code/Magento/Quote/Test/Unit/Model/Cart/AddProductsToCartErrorTest.php` (test file included).

Confirmed against precedent before acting: `v2.4.7-p10-ACP2E-4492` ships **one production file, no tests**. The test was removed from the commit and archived next to this document; the production hunks were verified byte-identical afterwards, and the archived test was re-run against the production-only commit (`OK (3 tests, 17 assertions)`) to prove the fix still stands without it.

**This does not weaken the TDD record.** Red was proven on this exact production change (§1), and the parent ACP2E-5536 carries its own test suite on 2.4-develop. What the release line declines to carry is the *test file*, not the *evidence*.

### 9.3 Sparta branch protection forbids force-push

Rewriting `v2.4.7-p10-ACP2E-5537` in place was rejected (`Cannot force-push to this branch`). A follow-up delete-commit would have left an add-then-delete history that could still fail a per-commit hygiene check, so a clean single-commit branch (`v2.4.7-p10-ACP2E-5537-fix`) was pushed instead and the old PR retired.

### 9.4 Process lessons

- **Resolve the target remote and branch *before* branching, not at push time.** Two of this ticket's three pushes were wasted on target selection, not on the fix. For a backport, the first question is "which remote's which line?", and the answer is a `ls-remote` away.
- **Check the destination's conventions against a recent precedent branch.** One `git diff --stat` against `v2.4.7-p10-ACP2E-4492` would have revealed the production-only rule before the eval did.
- **Re-verify preconditions after a rebase onto a materially different base.** A 772-commit gap is more than enough to invalidate a divergence analysis; here it happened to hold, but that was confirmed, not assumed.
