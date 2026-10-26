# MIG Phase 0 — Concrete Implementation Spec (Analytical-First MVP)

| | |
|---|---|
| **Status** | Draft — ready to execute |
| **Last updated** | 2026-08-24 |
| **Parent** | `docs/mig/impact-graph-proposal.md` (§3a decision, §7 Phase 0 row) |
| **Addresses** | `bdad-changelog.md` PART VIII caveat + action items #5, #7 — mechanize the Dependency Tracer's Phase 0 so blast-radius is not manual grep |
| **Scope** | Static, analytical, **on-demand** trace + thin edge cache. **No PHP call graph, no coverage TIA** (those are Phases 1–2). |

---

## 1. Objective

Turn the Dependency Tracer's Phase 0 from *manual discipline* into *one deterministic command* that, given a
`git diff` (or a symbol), emits a Blast-Radius Report resolving Magento's grep-invisible edges — the exact two
classes of edge that caused both ACP2E-5180 regressions:

1. **Cross-domain wiring** — `di.xml` plugins + `events.xml` observers (Blind Spot #1).
2. **Stale unit mocks** — unit tests whose mocked collaborator set no longer covers a changed method (Blind
   Spot #2).

**Definition of Done:** run against the ACP2E-5180 diff, the tool returns *both* the MSI acceptance test
(`AdminCancelOrderWithSimpleProductCustomStockAfterPartialShipmentTest`) **and** flags
`OrderTest::testRegisterDiscountCanceled` for a stale mock — before any fix is written.

## 2. Environment facts (verified 2026-08-24)

| Fact | Value | Consequence |
|---|---|---|
| `nikic/php-parser` | present, `^5.0` | AST extraction available (no new dep) |
| `di.xml` sources | 438 (`app/code` + `ext`) | direct XML parse is source of truth for plugins/preferences |
| `events.xml` sources | 121 | direct XML parse for observers |
| MFTF tests | 2769 | index `<test name>`/`<group>`/path→module |
| Module→path | `registration.php` / `ComponentRegistrar` | symlink-safe module attribution (honors `build-ee.php`) |
| Dispatch pattern | `eventManager->dispatch('name', ...)` | AST call-scan for `DISPATCHES` |

## 3. Non-Goals (Phase 0)

- No reverse `CALLS` graph via full AST (Phase 1).
- No clover/coverage `COVERS` edges (Phase 2).
- No CI gate wiring beyond emitting a run plan (Phase 3) — Phase 0 just *produces the report*.
- Does not guarantee minimal test set; **recall-first** (over-include on `@api`/shared-model changes).

## 4. Deliverable & layout

Standalone dev tool — **not** shipped in `app/code` (keeps it out of product/PRs; local-only per policy):

```
dev/tools/impact-graph/
  bin/impact-graph                 # CLI entry (php, reuses root vendor/autoload.php)
  src/
    Cli.php                        # arg parsing → command dispatch
    Source/
      DiXmlReader.php              # INTERCEPTS (<plugin>), PREFERS (<preference>/<virtualType>)
      EventsXmlReader.php          # OBSERVES (<event><observer>)
      MftfIndex.php                # Test + group + owning_module from **/Test/Mftf/Test/*.xml
      DispatchScanner.php          # DISPATCHES via php-parser (eventManager->dispatch('x'))
      MockScanner.php              # MOCKS via php-parser (createMock/getMockBuilder + ->method('x'))
      ModuleMap.php                # path↔module via ComponentRegistrar (symlink-safe)
    Diff/GitDiff.php               # changed files → changed symbols (method-level via AST delta)
    Resolve/Resolver.php          # seed → impacted modules/tests/mock_impact + confidence
    Cache/EdgeCache.php           # blob-hash-keyed edge cache in var/impact/ (NOT a global graph)
    Report/JsonReport.php         # output schema (matches impact-graph-proposal §5)
  test/
    corpus/ACP2E-5180.json        # regression-replay fixture (DoD)
    ResolverCorpusTest.php        # asserts recall on the corpus
```

Invocation: `php dev/tools/impact-graph/bin/impact-graph analyze --diff --format=json`

## 5. Edge extractors (the Magento-specific value)

Each extractor reads a **source of truth** directly; `generated/` is used only as optional confirmation
(it is lazily rebuilt in developer mode and may be stale — see impact-graph-proposal §3a).

| Edge | Extractor | Source & rule |
|---|---|---|
| `INTERCEPTS` | `DiXmlReader` | every `di.xml` `<type name="Subject"><plugin type="PluginClass"/>`; also parse the plugin class for `before*/after*/around*<Method>` → subject method |
| `PREFERS` | `DiXmlReader` | `<preference for="Interface" type="Concrete">`, `<virtualType>` |
| `OBSERVES` | `EventsXmlReader` | `<event name="X"><observer instance="ObserverClass"/>` (per-area events.xml) |
| `DISPATCHES` | `DispatchScanner` | AST: `MethodCall` where name=`dispatch` and arg0 is a string literal |
| `MOCKS` | `MockScanner` | AST: `createMock(X::class)` / `getMockBuilder(X::class)` + subsequent `->method('m')` / `onlyMethods([...])` on that var, scoped per test method |
| `Test`/`group`/module | `MftfIndex` | parse `<test name>` + `<group>` + resolve path→module |
| module attribution | `ModuleMap` | `ComponentRegistrar::getPaths()` — resolves symlinked EE/B2B/MSI to real modules |

**Symlink rule (shared with Dependency Tracer / Test Author):** always resolve paths through
`ComponentRegistrar` before module attribution; never assume the monorepo layout in `ext/…`.

## 6. Resolver algorithm (Phase 0 subset)

```
input:  git diff → changed files → changed symbols (method-level AST delta)
1. seed          = methods whose body/signature changed
2. intercepts    = INTERCEPTS edges whose subject ∈ seed            (plugins on changed methods)
3. dispatch_fan  = for each seed method: DISPATCHES → OBSERVES      (event → observers)
4. impl_fan      = PREFERS edges for interfaces in seed             (interface → concrete)
5. impacted_mods = modules owning {seed ∪ intercepts ∪ observers ∪ concretes}   (via ModuleMap)
6. tests         = MFTF tests IN_MODULE(impacted_mods) filtered by @group/domain
                   ∪ dependent-domain suites from .github/agents/reference/domains.md adjacency
7. mock_impact   = for each seed method, collaborators newly called in the NEW revision
                   (self-calls + injected-service calls via AST delta);
                   MOCKS-linked unit tests whose stubbed set ⊉ new collaborator set
8. score:  INTERCEPTS/OBSERVES/PREFERS = HIGH · module-dep only = MEDIUM
9. gate:   seed is @api OR has INTERCEPTS/OBSERVES edge  ⇒  acceptance_required = true
```

Steps 2/3 are the cross-domain catch; step 7 is the stale-mock catch. No reverse-`CALLS` closure in Phase 0 —
direct-caller impact (e.g. `registerCancellation`) is Phase 1; Phase 0 relies on module + wiring edges, which
is sufficient for the DoD.

## 7. Cache (thin, incremental — NOT a graph)

- Location: `var/impact/edges.sqlite` (disposable; rebuilt on demand; never committed).
- Key: per source file, `git hash-object <file>` (blob hash) → memoized edges from that file.
- Invalidation: surgical — on read, if a file's current blob hash ≠ cached, re-extract just that file.
- No whole-repo prebuild step; the cache warms as traces run. `setup-swarm.sh --index` may pre-warm after relink.

## 8. Output schema (Blast-Radius Report)

Reuse `impact-graph-proposal.md` §5 JSON verbatim (`seed`, `impacted_modules`, `edges_of_note`, `mock_impact`,
`run_plan`, `gate`). The Dependency Tracer pastes this as its Phase 0 report; the Consensus Gateway reads
`run_plan` as the mandatory set and `gate.acceptance_required` as the merge block.

## 9. Integration into the swarm

- **Dependency Tracer** (`.github/agents/dependency-tracer.agent.md`): Phase 0 becomes *"run
  `impact-graph analyze --diff` and paste the report"* instead of manual greps. Update the MIG seam from
  "reserved" to "Phase 0 active" once shipped.
- **Consensus Gateway** (`.github/agents/consensus-gateway.agent.md`): cross-domain gate consumes `run_plan` +
  `gate`; `mock_impact` becomes the mock-sync checklist.
- **Orchestrator**: `setup-swarm.sh --index` pre-warm hook (optional).

## 10. Regression-replay corpus (DoD gate)

```json
// dev/tools/impact-graph/test/corpus/ACP2E-5180.json
{
  "diff_ref": "2.4-develop...ACP2E-5180",
  "changed_symbol": "Magento\\Sales\\Model\\Order::registerCancellation",
  "must_include_test": "AdminCancelOrderWithSimpleProductCustomStockAfterPartialShipmentTest",
  "must_flag_mock": "Magento\\Sales\\Test\\Unit\\Model\\OrderTest::testRegisterDiscountCanceled",
  "expected_new_collaborators": ["Order\\Item::getQtyToShip", "PriceCurrencyInterface::round"]
}
```

`ResolverCorpusTest` asserts, for each corpus entry: `run_plan` **contains** `must_include_test`, and
`mock_impact` **flags** `must_flag_mock` with the expected collaborators. **Target: 100% recall.**

## 11. Task breakdown (execution order)

| # | Task | Output | Est |
|---|---|---|---|
| P0-1 | ✅ Scaffold `dev/tools/impact-graph` + CLI + autoload + `ModuleMap` | `impact-graph --help` runs | 0.5d |
| P0-2 | ✅ `DiXmlReader` (INTERCEPTS + PREFERS) + `EventsXmlReader` (OBSERVES) | edges for a given class | 1d |
| P0-3 | ✅ `MftfIndex` (test/group/module, glob-based, cached) | test lookup by module/group | 0.5d |
| P0-4 | 🟡 `DispatchScanner` (done) + `MockScanner` (done, class-level) | DISPATCHES + MOCKS edges | 1.5d |
| P0-5 | 🟡 `GitDiff` + `CollaboratorScanner` + per-method `MockScanner` + `Resolver.mockImpact` (mock-impact path done; COVERS test-selection is Phase 2) | JSON report from a diff | 1.5d |
| P0-6 | ✅ `EdgeCache` (content-hash, `var/impact/edges.json`) | warm/cold parity | 0.5d |
| P0-7 | ✅ Corpus + `ResolverCorpusTest` (mock-impact DoD green; acceptance-tier `markTestIncomplete` pending Phase 1) | green on ACP2E-5180, 100% recall | 0.5d |
| P0-8 | ✅ Wire report into Dependency Tracer + Consensus Gateway (+ Test Author mock-sync) profiles | swarm uses the tool | 0.5d |

**Total: ~1.5 weeks**, matching the proposal's Phase 0 estimate.

## 12. Risks (Phase 0-specific)

| Risk | Mitigation |
|---|---|
| No `CALLS` closure yet → miss a pure direct-caller impact | Recall via module + wiring edges; `@api`/shared-model forces acceptance tier; Phase 1 adds `CALLS` |
| Dynamic dispatch name (`dispatch($var)`) | Flag as "unresolved dispatch — widen scope"; do not silently drop |
| Partial mocks / trait collaborators in `MockScanner` | Phase 0 handles `createMock`/`getMockBuilder`+`method()`/`onlyMethods()`; document trait-provided as known gap |
| `generated/` staleness if used | Do not depend on it — sources of truth only; `generated/` is confirmation-only |

## 12a. Build log — P0-1 / P0-2 (2026-08-24)

Scaffolded and run against ACP2E-5180. Files: `dev/tools/impact-graph/{bin/impact-graph,
src/Cli.php, src/Support/FileScanner.php, src/Source/{ModuleMap,DiXmlReader,EventsXmlReader}.php}`.

**Mechanical results (match PART VIII hand-trace exactly):**
- `analyze --event sales_order_item_cancel` → `Magento\CatalogInventory\Observer\CancelOrderItemObserver`
  (`Magento_CatalogInventory`) **+** `Magento\InventorySales\Observer\CatalogInventory\CancelOrderItemObserver`
  (`Magento_InventorySales`, resolved under `ext/…` symlink root). ~1s cold, no cache.
- `analyze --plugins-method getQtyToCancel` → `Magento\Bundle\Model\Sales\Order\Plugin\Item`
  (`Magento_Bundle`), methods `getQtyToCancel, isProcessingAvailable`.
- `analyze --diff 2.4-develop...HEAD` → `Order.php`→`Magento_Sales` (+4 plugins), `AdminCancellationTest`,
  `OrderTest` correctly classified; JSON output matches the proposal §5 shape.

**Validation-standard decision:** this is a **framework-external dev CLI** (no ObjectManager bootstrap by
design), so it is linted with **PSR-12**, not the `Magento2` module standard. The Magento2 findings
(`exec`/`echo`/`is_dir`/`final` forbidden, `Magento\Framework\Shell`/`Filesystem` required) are category
errors here — satisfying them would force a framework bootstrap and defeat the tool's purpose (fast, standalone
static analysis). `php -l` + `phpcs --standard=PSR12` both green. Magento's own `dev/tools` scripts are
likewise not held to the module standard.

**Not yet wired (P0-4/P0-5):** diff→symbol→`DISPATCHES`→`OBSERVES` auto-chaining and `MOCKS` impact. Today the
event/plugin edges are surfaced by targeted query; the automatic diff→event chain needs the DispatchScanner.

## 12b. Build log — P0-4 (2026-08-24)

Added `src/Source/DispatchScanner.php` + `src/Source/MockScanner.php` (php-parser v5,
`createForNewestSupportedVersion`; php-parser autoloaded via root `vendor/autoload.php` in `bin/impact-graph`).
New CLI: `--dispatches <file|FQCN>`, `--mocks <testfile|FQCN>`; `--diff` now appends per-file
`dispatches` + `observes`. PSR-12 clean; `php -l` green.

**Mechanical results:**
- `--dispatches Order/Item.php` → `sales_order_item_cancel` (method `cancel`) → auto-chains to **both**
  observers (`Magento_CatalogInventory` + `Magento_InventorySales`). DispatchScanner + chaining fully work.
- `--mocks OrderTest.php` (HEAD vs `2.4-develop`): `this->item` stubs gained **`getQtyToShip`** post-fix
  (absent pre-fix) — correctly surfaces half of Blind Spot #2.

**Two precision boundaries this run surfaced (honest):**
1. **`--diff` for the *merged* ACP2E-5180 diff does NOT surface `sales_order_item_cancel`.** That diff changes
   only `Order.php`; the event is dispatched in `Order\Item::cancel()`, which `Order::registerCancellation`
   *calls* — **one `CALLS` hop away**. Phase 0 has no call graph, so `--diff` shows only the events dispatched
   *within* changed files (here: `sales_order_place_*`, `order_cancel_after`). **Recall for this ticket is
   preserved via the domain adjacency** (`domains.md`: Order Mgmt → run MSI), not via the dispatch chain. Full
   auto-chain needs **Phase 1 CALLS**. (Had the seed been the R1 localized edit to `Order\Item`, `--diff`
   *would* have chained directly, since the dispatch is in that file.)
2. **`MockScanner` is class-level, so `priceCurrency->round` is a false negative.** `round` is stubbed in a
   *sibling* test method pre-fix, so class-level aggregation reports it "present" and misses the gap specific to
   `testRegisterDiscountCanceled`. **Per-test-method stub scoping is required (P0-5)** for full recall on the
   `round` case; `getQtyToShip` was caught only because it was absent class-wide.

**Consequence for the corpus DoD:** achieving `must_flag_mock` with the `round` collaborator requires P0-5
(per-method MOCKS scoping + git-diff AST collaborator delta); `getQtyToShip` is already catchable. The
cross-domain half of the DoD is met for an item-file seed and, for the header-file seed, via domain adjacency.

## 12c. Build log — P0-5 (2026-08-24)

Added `src/Diff/GitDiff.php`, `src/Source/CollaboratorScanner.php`, per-method scoping in `MockScanner`
(`surfaceByMethod`/`surfaceByMethodFromCode`), and `src/Resolve/Resolver.php`. New CLI:
`--mock-impact <git-ref>`. PSR-12 clean; `php -l` green; earlier commands regress clean.

**Corpus DoD (mock-impact half) — MET.** `--mock-impact 2.4-develop...HEAD` flags
`OrderTest::testRegisterDiscountCanceled` (seed `registerCancellation`) with missing stubs including **both
`getQtyToShip` and `round`** — matching the corpus `must_flag_mock` + `expected_new_collaborators`.
`getQtyToInvoice` is correctly in `new_collaborators` (new call site in the new helper) but **excluded from
`missing_stubs`** because the base test already stubbed it.

**Design finding — call-site delta, not reachable-name delta (important).** The first implementation used a
transitive reachable-name set-difference (`collaborators(head) \ collaborators(base)`). It caught `getQtyToShip`
but **missed `round`**, because `round` was already reachable in base `registerCancellation`'s closure through
an unchanged path — even though base never actually rounded on the exercised path. The fix (`newCollaborators`)
computes collaborators from **newly-added call sites**: for each method reachable from the seed in head, take
its direct external calls, minus the base method's direct external calls (or all of them if the method is new,
e.g. the added `applyCanceledAmounts`/`getItemCancelableGrandTotal`). This both **catches `round`** (new call
site in a new helper) and **removes noise** (unchanged reachable collaborators like `getList`/`addFilter` no
longer appear). This resolves the P0-4 `round` false-negative.

**Per-method scoping resolves the other P0-4 boundary.** Stubs are attributed as `setUp` (global) ∪ the test
method's own stubs, so `round` stubbed in a *sibling* test no longer masks the gap in
`testRegisterDiscountCanceled`.

**Still open:** the *acceptance-test* half of the corpus DoD (`must_include_test` MSI) for the merged
`Order.php`-only diff still needs **Phase 1 CALLS** (the item-cancel event is one call hop away); recall is
held meanwhile via `domains.md` adjacency. Test selection (`COVERS`/run_plan) is Phase 2. Matching is by bare
method name (recall-first); class-qualified seeds are a later refinement.

## 12d. Build log — P0-7 (2026-08-24)

Added the regression-replay corpus + automated test:
`test/corpus/ACP2E-5180.json`, `test/bootstrap.php`, `test/ResolverCorpusTest.php`, `phpunit.xml.dist`.
Run: `vendor/bin/phpunit -c dev/tools/impact-graph/phpunit.xml.dist`.

**Result:** `Tests: 2, Assertions: 4, Incomplete: 1` — green. `testCorpusMockImpactRecall` asserts, for each
corpus entry, that the Resolver flags `must_flag_mock` with every `expected_new_collaborator` in its
`missing_stubs` (ACP2E-5180: `getQtyToShip` + `round`). `testCorpusAcceptanceSelectionRequiresCallGraph` is
`markTestIncomplete` — the acceptance-tier half honestly deferred to Phase 1 CALLS (recall held via
`domains.md`). PSR-12 clean on `src` + `test` + `bin`.

**Portability:** the test **skips** (not fails) when `diff_ref` yields no changed files (e.g. once ACP2E-5180
merges into `2.4-develop` and the three-dot range empties, or on an unrelated checkout). Flagged for a later
refinement: pin `diff_ref` to a stable commit range so the guard survives the merge. Seed matching is by
method-name suffix (recall-first).

## 12e. Build log — P0-8 (2026-08-24)

Wired the tool into the swarm profiles (`.claude/agents/`):
* **`dependency-tracer.md`** — MANDATORY PHASE 0 now runs `impact-graph analyze --diff` + `--mock-impact`
  first, then supplements with `domains.md` adjacency + grep (explicitly because Phase 0 has no CALLS graph).
  The MIG section flipped from "RESERVED / on hold" to "Phase 0 ACTIVE (partial)" with the available commands
  and the not-yet-built list (Phase 1 CALLS, P0-3, P0-6). Frontmatter description updated.
* **`consensus-gateway.md`** — the Cross-Domain Verification Gate consumes `--diff` `observes` (dependent-domain
  suites) and `--mock-impact` flags (mock-sync checklist); a non-empty unaddressed `mock_impact` blocks the
  green verdict; empty `observes` must not be read as "no impact" (Phase 0 recall caveat).
* **`test-author.md`** — Mock Surface Synchronization references `--mock-impact` for mechanical enumeration.

**Deferred:** Orchestrator `setup-swarm.sh --index` pre-warm hook — waits on **P0-6** (no `--index`/cache yet); the
tool runs on demand meanwhile.

## 12f. Build log — P0-6 + P0-3 (2026-08-24)

**P0-6 — `src/Cache/EdgeCache.php`** (content-hash keyed, `var/impact/edges.json`, `__destruct` save). Readers
(`ModuleMap`, `DiXmlReader`, `EventsXmlReader`) refactored to parse per-file through `remember(namespace, file,
producer)`, invalidated by `sha1_file` (chosen over `git hash-object` to avoid 550+ subprocess calls — same
invalidation guarantee, no shell-out).

* **Honest measurement:** `--event` cold 1.14s vs warm 1.11s — **no meaningful gain**, because the di/events
  set is small and the **FileScanner FS walk dominates**, not XML parsing; the walk re-runs every invocation.
  The cache's real payoff is parse-heavy work: **`--mftf-group msi` cold 1.63s → warm 0.95s (~40%)** across the
  2,769 MFTF files. Speeding di/events further would need file-*list* caching (deferred — invalidation risks
  missing newly-added files, against the always-fresh principle).

**P0-3 — `src/Source/MftfIndex.php`** (glob-based: `app/code/*/*/Test/Mftf/Test/*.xml` +
`ext/*/*/*/Test/Mftf/Test/*.xml`, since `FileScanner` prunes `Test/`). Parses `<test name>` + `<group>`;
attributes owning module via `ModuleMap`; cached. CLI: `--mftf-group <group>`, `--mftf-module <Module>`.

* `--mftf-group msi` → **554 tests incl. the corpus `must_include_test`** (`AdminCancelOrderWith…
  AfterPartialShipmentTest`).
* Corpus test upgraded: new **green** `testCorpusAcceptanceTestIsIndexed` asserts the index finds
  `must_include_test` in `must_include_group` (added to the fixture). The remaining incomplete test is now
  narrowed to **diff-driven** auto-selection only (still needs Phase 1 CALLS). Suite: `Tests: 3, Assertions: 5,
  Incomplete: 1`.

**Wiring:** Dependency Tracer + Consensus Gateway profiles now cite `--mftf-group`/`--mftf-module` for
dependent-domain acceptance-suite enumeration.

## 12g. Build log — FileScanner symlink-traversal bug fix (2026-08-25)

**Bug (found during ACP2E-5252 retrospective):** `Support/FileScanner.php` built its
`RecursiveDirectoryIterator` without `FilesystemIterator::FOLLOW_SYMLINKS`. PHP's SPL treats a
symlinked directory as a leaf under that default, so any EE/B2B module whose entire directory is a
symlink into `magento2ee/`/`magento2b2b/` (the common case — `build-ee.php` symlinks the module root
itself, not just files inside it) was invisible to `DiXmlReader`/`EventsXmlReader`/`DispatchScanner`/
`MockScanner` (all of which walk via `FileScanner`). This is **distinct from the documented Phase 1
CALLS gap** — it's a bug in the Phase 0 file-discovery layer itself, not an intentional non-goal.

**Reproduction (before fix):** `app/code/Magento/PricePermissions` is a real symlink to
`magento2ee/app/code/Magento/PricePermissions`, which has its own `etc/di.xml`. Scanning `app/code` +
`ext` for `di.xml` returned **436 files without the flag vs. 713 with it — 277 files (39%) missing**,
entirely accounted for by the 184 EE/B2B module-root symlinks under `app/code`. Confirmed no symlink
cycles exist (0 found) and negligible performance cost (~0.9s scan, matching the P0-6 cold-scan
baseline).

**Fix:** added `\FilesystemIterator::FOLLOW_SYMLINKS` to the iterator flags in
`findByName()`. `php -l` + `phpcs --standard=PSR12` clean; corpus suite
(`vendor/bin/phpunit -c dev/tools/impact-graph/phpunit.xml.dist`) still green (2 assertions, 1
skipped — the ACP2E-5180 `diff_ref` range has emptied now that it merged, as the P0-7 log already
anticipated — 1 incomplete, unrelated to this fix). Spot-checked via the actual CLI post-fix:
`--plugins-method`/`--plugins-subject` now resolve through EE-symlinked module roots.

**Scope note (don't overclaim):** `MftfIndex` uses `glob()`, not `FileScanner`, so `--mftf-module`/
`--mftf-group` were **never affected by this bug** — `glob()` already resolves symlinked directories.
Verified: `--mftf-module Magento_AdminGws` correctly returns AdminGws's real MFTF tests (AdminGws is
also EE-symlinked) both before and after this fix. This fix specifically restores visibility for
`INTERCEPTS`/`OBSERVES`/`DISPATCHES`/`MOCKS` edges on symlinked module roots — the ACP2E-5252
Consensus Gateway's separate "0 MFTF tests for AdminGws" error (see the swarm report §3) was a
gateway reasoning/quoting failure, not this tool bug; addressed instead in `consensus-gateway.md`.

## 13. First concrete action

Scaffold P0-1 and P0-2, then immediately run against the ACP2E-5180 diff to confirm the `INTERCEPTS`/`OBSERVES`
edges (already verified by hand in PART VIII) come back mechanically. That single check de-risks the rest.
