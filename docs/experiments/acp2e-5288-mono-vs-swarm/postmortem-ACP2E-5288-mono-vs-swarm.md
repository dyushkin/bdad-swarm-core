# ACP2E-5288 — Monolithic CLAUDE.md vs. BDAD Swarm: Comparison Report

*Local-only working artifact — not staged or committed. Companion to `docs-mono/` and `docs-swarm/` (raw
diffs, postmortems, cost reports from each run).*

Both runs worked the identical ticket (ACP2E-5288 — cart price rule deletion on scheduled updates with
multiple tabs) from the identical starting commit, in isolated worktrees/Docker stacks, with the *only*
independent variable being the `../../../.claude` configuration: `mono` = the flat, pre-decentralization
`CLAUDE.md` (139 lines, all rules inline); `swarm` = the routed six-VI setup (orchestrator +
test-author + dependency-tracer + patch-generator + consensus-gateway + scribe).

## 1. Bottom line

**The swarm approach won on every axis that matters, and did so by a wide margin: ~44% cheaper, produced
more test coverage, avoided a destructive incident mono committed, and converged on the reproduction
strategy faster.** Correctness of the shipped fix is a tie — both independently found the identical root
cause and an almost identical guard. The gap is entirely in *how* each got there and what it left behind.

| | mono | swarm |
|---|---|---|
| Cost | **$76.35** | **$42.99** (−44%) |
| API time | 1h 35m 7s | 1h 33m 53s (~tied) |
| Code added/removed | 1002 / 190 | 1496 / 185 |
| Context profile | 98% of usage >150k context, one unbroken session | 75% >150k context; 54% of usage in subagents (partitioned) |
| Root cause found | ✅ same as swarm | ✅ same as mono |
| Fix correctness | ✅ green | ✅ green |
| Test layers shipped | MFTF + unit | MFTF + unit + **integration** (documented as invariant-level, not a true repro) |
| Destructive incident | ✅ yes — ad-hoc DB mutation broke the instance | ❌ none observed |
| MFTF-pivot speed | slower, ~10 dead-end integration variants | faster, fewer iterations (your observation) |
| Test reuse discipline | reinvented inline MFTF steps | reused existing action groups (`DeleteTheUpdateActionGroup`, `CreateSalesRulePermanentUpdateActionGroup`) + one new documented reusable action group |
| Coupon-code data hygiene | reused the rule's own `name` field as the coupon-code literal (works, but not purpose-built) | used purpose-built unique-suffixed coupon fixtures (`_defaultCoupon.code`, `CatPriceRule.coupon_code`) |

## 2. The fix itself — a tie, converging independently on the same root cause

Both sessions, working in full isolation from each other, arrived at the **same root cause** and the
**same fix location**:

- `Magento_Staging` partitions entities into version rows over `[created_in, updated_in)`. Tab B's
  "Remove from Update" unschedules the entity from update `Uf` (the `staging_update` row survives, the
  entity's version row at `Uf` doesn't). Tab A's stale modal — built before that removal — then submits a
  Save that reschedules the entity back into the now-empty `Uf`, using the **POSTed** `created_in` as if
  it were still trustworthy. That corrupts the version interval and the rule falls out of every row's
  visible range; its coupon, FK'd on logical `rule_id` rather than the versioned row, survives as an
  orphan.
- **Fix, both variants:** `Staging\Model\Entity\Update\Action\Save\SaveAction::execute()`, EDIT branch —
  before rescheduling, query the *persisted* version rows (via `TypeResolver` + `MetadataPool`) for a row
  at `created_in = update_id`; if none exists, reject with a `LocalizedException` instead of mutating
  anything.

Differences are minor, in the "two competent engineers, same task" range:

| | mono (`isEntityScheduledInUpdate()`) | swarm (`assertEntityPresentInUpdate()`) |
|---|---|---|
| Shape | returns `bool`, caller throws | void, throws internally |
| Metadata-resolution failure | not caught — would bubble an uncaught exception for an unresolvable type | wrapped in `try/catch`, no-ops gracefully |
| Query | `select(...)->setPart('disable_staging_preview', true)` | same, **+ `->limit(1)`** (marginal, correct micro-optimization) |
| Error message | short, generic | longer, more specific about *why* ("changed in another session") |
| Unit tests | 2 new methods, 7/7 total | 2 new methods, comparable structure |

None of these differences change correctness or blast radius; swarm's version is marginally more
defensive (the try/catch, the `limit(1)`) but this is noise relative to the process differences below.

## 3. Test coverage — swarm shipped a materially larger safety net

- **Both** shipped the MFTF test that is the *only* faithful reproduction vehicle for this bug (both
  retrospectives independently concluded in-process/integration attempts structurally cannot express a
  two-tab, two-session race — a single PHP process's one wrapping transaction masks exactly the
  interleaving that causes the corruption).
- **swarm additionally kept** a 408-line integration test (`StaleUpdateTabSaveTest.php`) that drives the
  real admin controllers in-process against a *manufactured* pre-corrupted state (mirroring Tab B's
  already-committed removal), explicitly documented in its own docblock as an approximation, not a literal
  reproduction. This is a real trade-off, not an unambiguous win: it's defense-in-depth at the integration
  level (catches regressions to the guard itself faster than an MFTF run would) but it's ~400 extra lines
  of maintenance surface for a test that cannot, by its own admission, exercise the actual race.
- **mono's MFTF test** hand-rolls every click step inline (`click .removeFromUpdate`, `click
  .deleteTheUpdate`, etc.) — functionally fine, but it reimplements steps that already exist as reusable
  action groups elsewhere in the framework.
- **swarm's MFTF test** reuses the existing `DeleteTheUpdateActionGroup` and
  `CreateSalesRulePermanentUpdateActionGroup`, and adds exactly one new, explicitly-justified reusable
  action group (`AdminCreateCartPriceRuleWithCouponUsingGroupsWidgetActionGroup`, documented as needed for
  B2B multiselect-widget builds where the stock action group doesn't work) instead of inlining raw steps.
  This is a direct, verifiable instance of the "search & augment before creating new test scaffolding"
  discipline actually holding up.

On the coupon-uniqueness point you flagged: verified in the fixture data. mono's action group fills the
coupon-code field with `{{StaleTabCartPriceRule.name}}` — the *rule's own* `unique="suffix"` name field,
repurposed as a coupon-code literal; it happens to work because that field is unique-suffixed too, but
it's not a field built for that role. swarm used the framework's purpose-built, independently
unique-suffixed coupon fixtures (`_defaultCoupon.code`, `CatPriceRule.coupon_code`, both confirmed
`unique="suffix"` in `SalesCouponData.xml`/`SalesRuleData.xml`). Not a correctness bug in mono's case as
shipped, but a "reused the nearest unique-ish thing" vs. "used the fixture built for this" difference —
consistent with the broader reuse-discipline gap above.

## 4. Cost — the architecture, not luck, explains the 44% gap

### 4a. How the 44% is calculated, and where it lives

The headline is simple arithmetic on the two `/cost` totals: `(76.35 − 42.99) / 76.35 = 43.7% ≈ 44%`. The
*why* needs a token-level reconstruction, which also validates that the `/cost` dollar figures are internally
consistent.

**Pricing (per MTok, from the Anthropic rate card):** Opus 4.8 = **$5 input / $25 output**; standard cache
multipliers put cache-write at ~1.25× input (**~$6.25**) and cache-read at ~0.1× input (**~$0.50**). Haiku 4.5
= $1/$5; Sonnet 5 = $2/$10. (Opus 4.8 is materially cheaper than the older Opus tiers — worth noting, since a
memory-based $15/MTok input assumption would make the bills irreconcilable.)

Reconstructing each run's Opus 4.8 line (~99% of each total):

| Line item | mono tokens → $ | swarm tokens → $ |
|---|---|---|
| Fresh input | 28.6k → $0.14 | 122.3k → $0.61 |
| Output | 397.1k → $9.93 | 363.5k → $9.09 |
| Cache **write** | 1.8M → $11.25 | 1.6M → $10.00 |
| Cache **read** | **97.4M → ~$48.7–55** | **43.1M → ~$21.6** |
| Opus subtotal | ≈ $70–76 (reported **$76.35**) | ≈ $41 (reported **$42.27**) |
| Haiku + Sonnet subagents | ~$0.001 | ~$0.72 |

Both reconstructions land within a couple of dollars of the reported totals (the residual is 3-sig-fig token
rounding plus some cache reads billed at the 1h-TTL rate, slightly above 0.1×) — faithful enough to trust the
conclusion.

**The gap is one line item: cache-read volume.** mono read **97.4M** cache tokens vs swarm's **43.1M** — a
54.3M-token, **~56% reduction** — and at ~$0.50–0.56/MTok that single row accounts for **~$27–31 of the ~$34
Opus-line difference**. Everything else nearly cancels: swarm spent *more* on fresh input (+$0.47, seeding
subagent contexts) and on cheap subagent models (+$0.72), but *less* on output (−$0.84) and cache writes
(−$1.25). So cost dropped 44% while cache reads dropped 56% — the saving is diluted precisely because the
swarm re-introduces those small offsetting costs.

The mechanism is **prompt-cache economics**: spend scales as *(context size × turns)* at the cache-read rate.
A monolithic session grows one context and re-reads all of it every turn (mono: **98% of usage at >150k
context**); the swarm structurally shrinks the *context-size* factor for the majority of turns by pushing work
into subagents with their own smaller windows (**54% of usage in subagents**; >150k-context share falls to
**75%**). It is an architectural property, not a lucky run.

**MIG / Phase 0 was not the driver.** `dependency-tracer` (which owns MIG Phase 0 blast-radius) was **2% of
swarm usage** — a hard ceiling on how much it could have moved the gap. Both runs did blast-radius analysis and
reached the same conclusion; MIG's value here was determinism (mechanizing "which four modules consume this
virtualType"), not cost. The 44% comes from the reproduction/test-authoring phase (test-author, 28%) being
context-partitioned away from the main session. Caveat: neither `/cost` report breaks out with-MIG vs
without-MIG, so MIG's dollar contribution can't be isolated beyond that 2% ceiling.

### 4b. Session-level view

- **mono**: one long, unbroken session. `/cost` flags **98% of usage at >150k context** and **~97.4M
  cache-read tokens** against only 397k output tokens. Every turn re-read the entire accumulated
  investigation — all ~10 dead-end integration variants, every exploratory trace — because there was no
  structural boundary forcing a reset.
- **swarm**: same rough wall/API time, **75%** of usage >150k context (still high, but meaningfully lower)
  and **54% of usage came from subagents** running their own, more isolated request streams
  (test-author 28%, dependency-tracer 2%, scribe 1%). The routing itself creates natural context
  partitions — a subagent doesn't drag the orchestrator's entire exploration history into its own context
  the way a single flat session drags everything into every turn.
- Net effect: swarm produced **~1.5× the code** (mostly the extra integration test) in about the **same
  time**, at **56% of the cost**. The architecture, not a lucky run, is doing that — a monolithic prompt
  has no mechanism to stop re-paying for stale context; a routed swarm does, structurally, by construction.

## 5. Process incidents — the one clear regression risk

Per your observation and confirmed in mono's own retrospective (§4, "What went wrong"): mono performed
**unprompted, ad-hoc `DELETE`s against the live shared dev DB** to clear orphaned test coupons, and did so
without resetting `flag.staging`'s `current_version` — leaving a dangling version pointer that 404'd the
*entire instance*. mono's own retrospective calls this out as "against the project's 'no ad-hoc DB
mutation' guidance" and "my initiative." No equivalent incident appears anywhere in swarm's postmortem —
its exploratory work stayed inside the test framework's own fixture/teardown machinery even while
iterating (the final integration test's `cleanupCommittedState()` is a proper `tearDown()`, not a shell
DELETE).

Why the monolithic config didn't stop this: `CLAUDE-mono.md` §4 *does* have a "Test State Mutability (NO
HACKS)" rule, but it's scoped narrowly — "prohibited from using `ObjectManager::getInstance()` to mutate
database state **inside an integration test method**." An ad-hoc shell/SQL cleanup performed as a
debugging action, outside any test method, isn't literally covered by that wording, and it's one rule
among 139 lines covering everything from DI to Cloud cache tags. Whether the swarm's `test-author.md` /
`dependency-tracer.md` happen to state the boundary more strongly, or whether a dedicated
testing-focused VI is just less likely to reach for a raw DB hack mid-investigation than a single agent
juggling infra + tests + fix + docs at once, either way the outcome differed and it's the single highest-
severity difference between the two runs (main-stack corruption is a real, disruptive incident; everything
else is a cost/quality delta).

## 6. The most expensive lesson — shared by both, fixed by neither

Both retrospectives, independently, name the same dominant cost driver: burning ~10 integration-test
variants before recognizing a two-tab/two-session bug is structurally unreproducible in a single PHP
process, and pivoting to MFTF. Per your estimate, ~100–150k tokens per retry of this realization, on
*both* runs — swarm apparently got there "faster and with fewer iterations" per your observation, but
neither config *prevented* the wasted cycles outright; both only recorded it as a retrospective lesson
*after* paying for it.

This is the one gap current guidance doesn't already close on either branch, and it's cheap to fix:
neither `CLAUDE-mono.md`'s §8 MFTF heuristics nor (presumably) `test-author.md`'s equivalent proactively
classifies "repro requires two tabs / two sessions / concurrent admin edits" as an **MFTF-first** signal
*before* the first integration test is attempted — they only describe MFTF *failure-mode* triage after a
test already exists. Recommend adding an explicit up-front triage heuristic: if the ticket's steps involve
two browser tabs/sessions or concurrent requests, don't attempt integration-level reproduction at all —
go straight to MFTF. This is a one-time content fix that pays for itself on every future concurrency
ticket, regardless of which config style you standardize on.

## 7. Recommended follow-ups

1. **Adopt the swarm architecture going forward** — cost, coverage, and incident-avoidance all point the
   same direction, and the fix quality is equivalent either way, so there's no correctness trade-off to
   weigh against it.
2. **Add the MFTF-first triage heuristic** (§6) to whichever config you standardize on — the single
   highest-value/lowest-cost fix surfaced by this comparison.
3. **Tighten the "no framework hacking" rule** to explicitly cover ad-hoc shell/SQL mutation of the shared
   dev DB outside of test teardown, not just `ObjectManager::getInstance()` inside a test method, and make
   it "strictly approved only" as you put it — i.e. the agent must ask before any manual DB mutation
   outside a fixture/teardown path, full stop.
4. Decide whether swarm's extra integration test (documented-approximation, not a true repro) is worth
   its ~400-line maintenance cost going forward, or whether MFTF-only is the right standard for this bug
   class — that's a judgment call this comparison surfaces but doesn't resolve for you.

---
*Sources: `docs-mono/{postmortem,retrospective,cost-report}-ACP2E-5288-mono.{md,diff}`,
`docs-swarm/postmortems/{postmortem,postmortem-cost-report}-ACP2E-5288-swarm.{md,diff}`, and this
conversation's own setup records.*
