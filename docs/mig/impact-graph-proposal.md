# Proposal: Magento Impact Graph (MIG) — Test Impact Analysis for AI-Driven TDD

**Status:** Draft for review · **Owner:** L4 AI Tooling · **Addresses:** postmortem-ACP2E-5180 Gap #1 (impact-aware test selection) · **Plan item:** #1 / ledger L15

> **Architecture decision (2026-08-24): analytical-first, cache-not-graph.** The primary path is an on-demand,
> task-scoped trace derived directly from the wiring sources of truth — NOT a persistent whole-repo graph. See
> §3a. §3's "one cached graph" framing is superseded by §3a; the node/edge model below still stands as the
> *shape of what a trace resolves* and as the schema for the thin incremental cache.

---

## 1. Problem Statement

The ACP2E-5180 regression happened because the agent edited `Order\Item::getQtyToCancel()` and validated only the tests it could *see by grep*. It missed the MSI acceptance test because the dependency was **indirect** and invisible to text search:

```
Order\Item::getQtyToCancel()
   ├─(di.xml plugin)──▶ Bundle\...\Plugin\Item::afterGetQtyToCancel     ← grep-invisible
   └─(caller)─────────▶ Order::registerCancellation()
            └─(events.xml dispatch 'sales_order_item_cancel')
                     └─▶ InventorySales\...\CancelOrderItemObserver      ← grep-invisible
                              └─ module Magento_InventorySales
                                   └─ MFTF @group msi
                                        └─ AdminCancelOrderWith…AfterPartialShipmentTest  ← the test we broke
```

`grep getQtyToCancel` never surfaces the observer or the MFTF test. A second regression (ACP2E-5180 R5) was a unit test whose strict mock didn't cover a newly-added collaborator (`priceCurrency->round()`). **MIG's job is to make both chains mechanically derivable** so the agent (and CI) always know the true blast radius and the exact tests to run — including cross-module (MSI / EE / B2B), acceptance (MFTF), and the unit tests whose mocks a signature change invalidates.

## 2. Goals & Non-Goals

**Goals**
- Given a `git diff`, output the impacted **modules** and a ranked **run plan** (unit → integration → MFTF) with confidence scores.
- Resolve Magento's *indirect* wiring: DI plugins/preferences/virtualTypes, `events.xml` dispatch→observer, `di.xml` interceptors — the edges grep can't see.
- Flag **unit tests whose mocks must be updated** when a method's collaborator set changes (the R5 class of failure).
- Be **recall-first**: for `@api`/shared-model changes, over-include rather than miss an acceptance test.
- Feed directly into CLAUDE.md Rules A/B (Blast-Radius Report + Cross-Domain Gate) and Rule D (Mock Surface Sync).

**Non-Goals (v1)**
- Not a replacement for the full nightly suite; it *selects*, it doesn't *guarantee minimality*.
- Not a runtime profiler. Static + coverage-baseline only.
- No change to the test frameworks themselves.

## 3. Architecture

Three components, one cached graph.

```
                    ┌─────────────────────────────────────────┐
   (offline, cached)│  1. INDEXER                              │
   git hash keyed   │  PHP AST + di.xml + events.xml + MFTF +  │
                    │  module.xml  →  graph.sqlite             │
                    └───────────────────┬─────────────────────┘
                                        │  reverse-edge traversal
   (online, per-diff)┌──────────────────▼─────────────────────┐
                     │  2. RESOLVER                            │
   git diff  ───────▶│  changed symbols → impacted symbols →   │
                     │  modules → tests (+ confidence)         │
                     └──────────────────┬─────────────────────┘
                                        │
                     ┌──────────────────▼─────────────────────┐
                     │  3. ORCHESTRATOR                        │
                     │  emit phpunit --filter + mftf run:group │
                     │  ordered fast→slow, gate on acceptance  │
                     └─────────────────────────────────────────┘
```

### Graph data model

| Node types | Edge types (directed) |
|---|---|
| `Symbol` (class / method / interface) | `CALLS` (method → method) |
| `Module` (`Magento_*`) | `IMPLEMENTS` / `EXTENDS` |
| `Event` (dispatch name) | `INTERCEPTS` (plugin → subject method) *(di.xml)* |
| `Fixture` (DataFixture class / MFTF entity) | `PREFERS` (interface → concrete) *(di.xml)* |
| `Test` (unit / integration / MFTF) + `group[]` + `owning_module` | `DISPATCHES` (method → event) / `OBSERVES` (observer → event) *(events.xml)* |
| `Table` (e.g. `sales_order_item`) | `COVERS` (test → symbol) · `MOCKS` (unit test → mocked method) · `DECLARES_FIXTURE` · `IN_MODULE` · `DEPENDS_ON` (module.xml sequence) |

### Edge extraction sources (the Magento-specific value)

| Edge | Source | Catches |
|---|---|---|
| `CALLS`, `IMPLEMENTS` | `nikic/php-parser` AST, resolved through DI preferences | direct callers |
| `INTERCEPTS` | every `di.xml` `<type><plugin>` | **Bundle plugin (Blind Spot #1)** |
| `PREFERS` | `di.xml` `<preference>`, `<virtualType>` | interface→concrete |
| `DISPATCHES` | AST scan for `eventManager->dispatch('<name>')` | event fan-out |
| `OBSERVES` | every `events.xml` `<event><observer>` | **InventorySales observer (Blind Spot #1)** |
| `MOCKS` | AST scan of unit tests for `createMock/getMockBuilder` + `->method('x')` | **stale-mock detection (Blind Spot #2)** |
| `COVERS` | Phase 2: clover coverage from a baseline run (test→line→symbol) | ground-truth selection |
| MFTF `Test`+`group`+module | parse `**/Test/Mftf/Test/*.xml` (`<test name>`, `<group>`, path→module) | **msi group (Blind Spot #1)** |
| symlink resolution | honor `build-ee.php` map so EE/B2B/MSI paths resolve to real modules | monorepo layout |

## 3a. Architecture Decision: Analytical-First, Cache-Not-Graph (2026-08-24)

We evaluated **precomputed whole-repo graph** vs. **on-demand analytical trace** and chose analytical-first.
Rationale:

* **Strict wiring rules make edges deterministic and rule-encodable.** Blast-radius edges are grep-invisible
  but live in known, uniformly-used places (`di.xml` `<plugin>`/`<preference>`/`virtualType`, `events.xml`
  `<observer>`, `webapi.xml`/GraphQL schema, layout handles). Because our standards enforce uniform use of
  these mechanisms, per-task extraction is reliable enough that precomputation buys little.
* **Staleness is the graph's fatal flaw — and our known failure mode.** A monolithic graph must be rebuilt on
  every `di.xml`/`events.xml`/module change; a stale graph reproduces the ACP2E-5180 "trusted a green signal
  that was wrong" blind spot. An on-demand trace is fresh by construction.
* **Bounding-box economics.** A per-task trace computes only the edges relevant to the current change; the
  overall graph is mostly irrelevant noise for any single ticket and expensive to keep renewed.
* **Edition/symlink correctness for free.** Tracing the *currently enabled* `di.xml`/`events.xml`
  (EE/B2B/MSI relinked by `build-ee.php`) reflects the real module set with no per-edition rebuild.

**Two design constraints this imposes:**
1. **`generated/` is necessary-but-insufficient, and prefer sources of truth over it.**
   `generated/code/**/Interceptor.php` gives a clean compiled plugin chain, but observers, preferences,
   `webapi`/GraphQL, and layout edges are NOT in `generated/`. Worse, in `developer` mode interceptors
   regenerate lazily and we `rm -rf generated/code/*` on DI changes, so `generated/` can be stale exactly when
   trusted. **Derive plugin/preference edges from `di.xml` directly** (+ `events.xml` for observers); use
   `generated/` only as confirmation.
2. **Cache, don't graph.** Memoize only the edges an actual trace discovers into a lightweight incremental
   index (the `MOCKS`/edge index of §7 Phase 0), invalidated *precisely* by the mtime/blob-hash of the specific
   `di.xml`/`events.xml`/test file that produced each edge. This yields graph-like speed on repeat visits
   without whole-graph staleness — you only ever hold edges you've traced, invalidated surgically.

**When a true global graph IS warranted:** portfolio analytics only — hotspot/centrality ranking ("which
classes are most-plugged"), which is a cross-ticket question that doesn't need per-commit freshness. Do not
build it for per-task blast radius.

**Net revision to §3:** the Indexer becomes a *cache warmer for traced edges*, not a whole-repo pre-builder;
the Resolver (§4) reads sources of truth on demand and consults the cache opportunistically; the Orchestrator
is unchanged. The Phase 0 MVP (§7) already matches this — it is the analytical trace plus a thin edge cache.

## 4. Resolver Algorithm

```
input:  git diff (changed files + changed symbols via AST delta)
1. seed = symbols whose body/signature changed
2. impacted = reverse-BFS over {CALLS, INTERCEPTS, PREFERS, IMPLEMENTS} from seed
3. for each dispatch method in impacted:  follow DISPATCHES → OBSERVES  (event fan-out)
4. domain-expand: if seed touches a shared Table/@api symbol, add all modules with
   OBSERVES/INTERCEPTS/COVERS edges into that symbol or table
5. tests = COVERS edges  ∪  tests IN_MODULE(impacted modules) filtered by matching @group/domain
6. mock_impact = for each seed method with a changed collaborator set,
   MOCKS-linked unit tests whose stubbed set ⊉ new collaborator set
7. score: exact COVERS = HIGH · event/plugin/preference edge = MEDIUM · module-dep only = LOW
8. run plan = HIGH+MEDIUM by default; LOW included when seed is @api or shared-model
```

**Confidence gate policy:** a change to an `@api` class, or a method with an `INTERCEPTS`/`OBSERVES` edge, **forces** the acceptance (MFTF) tier into the plan — you cannot opt out. This is the mechanical enforcement of "see MSI before you write the fix."

## 5. Interface (CLI + JSON contract)

```bash
bin/impact-graph index            # build/refresh cached graph (incremental by file hash) → var/impact/graph.sqlite
bin/impact-graph analyze --diff --format=json
bin/impact-graph analyze --symbol 'Magento\Sales\Model\Order\Item::getQtyToCancel'
bin/impact-graph plan --diff --emit=commands   # executable phpunit + mftf run plan, fast→slow
```

**Output schema** (consumed by the agent as the Blast-Radius Report):

```json
{
  "seed": ["Magento\\Sales\\Model\\Order\\Item::getQtyToCancel"],
  "impacted_modules": ["Magento_Sales", "Magento_Bundle", "Magento_InventorySales"],
  "edges_of_note": [
    {"type": "INTERCEPTS", "from": "Magento\\Bundle\\...\\Plugin\\Item::afterGetQtyToCancel", "confidence": "HIGH"},
    {"type": "OBSERVES", "via_event": "sales_order_item_cancel",
     "observer": "Magento\\InventorySales\\...\\CancelOrderItemObserver", "confidence": "HIGH"}
  ],
  "mock_impact": [
    {"method": "Magento\\Sales\\Model\\Order::registerCancellation",
     "new_collaborators": ["Order\\Item::getQtyToShip", "PriceCurrencyInterface::round"],
     "tests_needing_mock_update": ["OrderTest::testRegisterDiscountCanceled"]}
  ],
  "run_plan": [
    {"tier": "unit",        "cmd": "phpunit ... --filter 'ItemTest|Bundle.*Plugin.*ItemTest'"},
    {"tier": "integration", "cmd": "phpunit ... Sales/Model/Order/ItemTest.php"},
    {"tier": "acceptance",  "group": "msi", "forced": true,
     "cmd": "mftf run:test AdminCancelOrderWithSimpleProductCustomStockAfterPartialShipmentTest"}
  ],
  "gate": {"acceptance_required": true, "reason": "seed is @api + has OBSERVES edge"}
}
```

Run against the ACP2E-5180 diff, this returns **both** the MSI acceptance test (Blind Spot #1) and the stale unit mock (Blind Spot #2) — before a line of the fix is written.

## 6. Integration Points

- **CLAUDE.md (Rules A/B/D enforcement):** the agent MUST run `bin/impact-graph analyze --diff` and paste its output as the Blast-Radius Report; the `run_plan` *is* the mandatory test set, and `mock_impact` *is* the Rule-D checklist. `"acceptance_required": true` blocks a "done" claim until that tier is green or explicitly flagged unverified.
- **Pre-push hook / CI:** `impact-graph plan --diff --emit=commands | sh` as a required status check; the full suite still runs nightly as a recall safety net.
- **`setup-swarm.sh`:** `setup-swarm.sh --index` after module (re)linking; emit the linked-module manifest so the resolver knows which domains (EE/B2B/MSI) are in scope this session.

## 7. Phased Rollout

| Phase | Scope | Value | Effort |
|---|---|---|---|
| **0 — MVP** | `di.xml` (plugin/preference) + `events.xml` (dispatch/observe) + MFTF group index + `MOCKS` edges + git-diff symbol extraction. No PHP call graph yet. | **Catches both ACP2E-5180 blind spots.** Highest ROI. | ~1–2 wks |
| **1 — Call graph** | Reverse `CALLS`/`IMPLEMENTS` via `php-parser`, incremental cache keyed by file hash. | Direct-caller impact (e.g. `registerCancellation`). | ~2–3 wks |
| **2 — Coverage TIA** | `COVERS` edges from a clover baseline (test→line→symbol). | Ground-truth selection; kills false negatives. | ~3 wks |
| **3 — Orchestrator + gate** | Policy gate, CI status check, CI-failure→Red feedback. | Closes the autonomy loop. | ~2 wks |

## 8. Validation: Regression-Replay Corpus (Definition of Done)

Build a corpus of historical regressions (seed: **ACP2E-5180**) as fixtures:

```
corpus/ACP2E-5180.json:
  changed_symbol: Magento\Sales\Model\Order\Item::getQtyToCancel
  must_include_test: AdminCancelOrderWithSimpleProductCustomStockAfterPartialShipmentTest
  must_flag_mock:   OrderTest::testRegisterDiscountCanceled  (via registerCancellation collaborator delta)
```

MIG's own CI test: for every corpus entry, assert the resolver's `run_plan` **contains** the historically-broken test and `mock_impact` **flags** the stale mock. **Target: 100% recall on acceptance-breaking changes** (a miss here is the failure mode we're eliminating). Track precision as a secondary metric.

## 9. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| **False negatives** (missing edge → silent gap) | Recall-first: `@api`/shared-model/`Table`-touching changes always include the acceptance tier; regression corpus guards recall. |
| Dynamic DI / `ObjectManager::get()` unresolvable statically | Treat unresolved interface calls as edges to *all* preferences; flag `ObjectManager` direct usage as "low-confidence, widen scope." |
| Graph staleness | Incremental index keyed by git blob hash; `setup-swarm.sh --index` after relink; CI rejects a stale graph. |
| Symlink/EE/B2B path confusion | Resolve through `build-ee.php` map before module attribution. |
| Coverage baseline drift (Phase 2) | Regenerate on a schedule; fall back to static edges if coverage missing. |

## 10. Success Metrics

1. **Recall on regression corpus = 100%** for acceptance-breaking changes (primary).
2. **Cross-domain + stale-mock breaks caught pre-push:** trend to zero.
3. **Loop time:** median change validated against the *right* set below the cost of the full suite; acceptance only when the gate requires it.
4. **Agent behavior:** % of shared-code edits producing a complete Blast-Radius Report before implementation → 100%.

---

**Recommendation:** fund **Phase 0** immediately — small, self-contained, and it would have prevented *both* regressions this project produced. Phases 1–3 convert MIG from a safety net into the backbone of the autonomous loop.

## Open questions to think on
- ~~**Graph store:** SQLite vs. flat JSON index?~~ **Resolved (§3a):** no monolithic store — a thin
  incremental *edge cache* keyed by source-file blob-hash. SQLite is fine as the cache backend; it is not a
  prebuilt graph.
- **Language:** implement as a PHP console command (reuses Magento's own di.xml/events.xml readers and `nikic/php-parser`, already in the tree) vs. a standalone Node/Python tool?
- **`MOCKS` precision:** AST-detect `->method('x')` stubs is straightforward, but partial mocks / `onlyMethods()` / trait-provided collaborators need care — how much fidelity is worth it for Phase 0?
- ~~**Where does the graph live** — committed vs. `var/`?~~ **Resolved (§3a):** generated in `var/` (always
  fresh); the cache is disposable and rebuilt on demand, so there is no committed graph artifact to review.
