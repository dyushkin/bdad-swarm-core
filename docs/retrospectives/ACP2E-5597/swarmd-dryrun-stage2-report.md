# BDAD Swarm — Stage 2 Dry Run: Workshop Procedure and Reference Run (ACP2E-5597)

| Field | Value |
|---|---|
| Ticket | ACP2E-5597: the Customer Segment *Newsletter Subscription* condition scans all of `customer_entity` |
| Stage | STOP 1 approved → MIG Phase 0 (Dependency Tracer) → Red test (Test Author) → **STOP 2** (second human stop) |
| Input | Stage 1 reference run (`docs/bdad-workshop/stage1-dryrun/reference/`) plus the human STOP 1 decisions (`stop1-decisions.md`) |
| Reference run | 2026-11-20T02:10:49Z → 02:22:19Z UTC (11 min 30 s), exit 0 |
| Repository effect | One new, uncommitted test file in `magento2ee`. No production code changed; nothing staged or committed. |
| Tooling | GitHub Copilot CLI 1.0.114; MCP server `scout`; MIG `impact-graph`; PHPUnit 12.5.14 on PHP 8.5.12 (host) |
| Workshop kit | `docs/bdad-workshop/stage2-dryrun/` (`run-stage2.sh`, `prompt.template.txt`, `stop1-decisions.md`, `reference/`) |
| Result | **PASS**. The Defect test is Red for the right reason, all three Invariant tests pass, and the run halted at STOP 2. |

## 1. Preconditions

| # | Precondition | Check |
|---|---|---|
| P1 | Stage 1 preconditions P1–P5 hold (branch, model pins, MCP servers, Scout attached, tools on `PATH`) | Stage 1 report §1 |
| P2 | Docker services are up: DB, OpenSearch, Redis, RabbitMQ | `docker ps` |
| P3 | The integration test database is installed (`TESTS_CLEANUP=disabled`, an existing `dev/tests/integration/tmp/sandbox-*`) | `ls dev/tests/integration/tmp` |
| P4 | The MIG tool runs | `php dev/tools/impact-graph/bin/impact-graph analyze --help` |
| P5 | The target test directory does not exist yet (fresh run). Remove the previous run's file first, if any | `git -C magento2ee status --porcelain` |
| P6 | STOP 1 decisions are recorded in `docs/bdad-workshop/stage2-dryrun/stop1-decisions.md` | review the file |

STOP 1 decisions used for this run:

| # | STOP 1 question | Decision |
|---|---|---|
| 1 | Approve or amend the behavior contract | Approved, with Scenario 5 merged into the Defect scenario (4 scenarios) |
| 2 | Scope | Newsletter condition only |
| 3 | Scenario 4 (cart discount end to end) | Keep as an integration invariant test |
| 4 | Go-ahead for MIG Phase 0 | Approved |

## 2. Procedure

### 2.1 Live run (spends about 295 AI credits and takes about 12 minutes)

```bash
docs/bdad-workshop/stage2-dryrun/run-stage2.sh ACP2E-5597              # artifacts: ~/bdad-dryrun/ACP2E-5597-stage2-<timestamp>/
S1=/path/to/stage1/run docs/bdad-workshop/stage2-dryrun/run-stage2.sh ACP2E-5597 /tmp/demo2   # use another Stage 1 run
```

The script:
1. copies `stop1-decisions.md` into `$A` and renders `prompt.template.txt` into `$A/prompt.txt`;
2. runs the Stage 1 harness with these additions:
   - `shell(php)` and `shell(vendor/bin/phpunit)` for MIG and PHPUnit (`php bin/magento` and `php -r` denied);
   - repository writes allowed only under `magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/`
     (and its CE symlink);
   - `shell(tail)` and `shell(cd)`;
3. tees the stream to `$A/stream.jsonl` and shows it in `swarm_console.py`;
4. prints the post-run checks: exit code, repository changes made by the run, artifacts present.

**After the workshop:** delete the generated test file, or keep it for Stage 3:
`magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php`.

### 2.2 Replay (fallback: no credits, no network)

```bash
docs/bdad-workshop/stage2-dryrun/run-stage2.sh --replay docs/bdad-workshop/stage2-dryrun/reference 10
```

### 2.3 Verify

| # | Check | Reference result |
|---|---|---|
| V1 | Exit code 0 | PASS |
| V2 | Only the new test file changed in the repository; no production code | PASS (`?? .../Condition/Customer/NewsletterTest.php`) |
| V3 | Artifacts present: `execution-log.md`, `1-dependency-tracer.md`, `2-test-author.md`, `red-verification.md`, `stop2.md`, `usage.json` | PASS |
| V4 | Tracer ran MIG commands and named dependent domains from `domains.md` | PASS (5 MIG commands; 3 dependent domains) |
| V5 | Each contract scenario maps to exactly one test method | PASS (4 → 4) |
| V6 | Defect test FAILS on the behavior assertion, not on setup | PASS (assertion 4 of 4 at `NewsletterTest.php:106`) |
| V7 | Invariant tests PASS before the fix | PASS (1 / 2 / 5 assertions) |
| V8 | Orchestrator re-ran the Defect test independently | PASS (`red-verification.md`) |
| V9 | No timing assertion anywhere | PASS |
| V10 | Specialists returned only to the Orchestrator; nothing relayed between them | PASS |

### 2.4 Chat dry run (no credits; no actions)

Type `Dry-run Stage 2` in a Copilot CLI session in this repository. The project skill
`.github/skills/swarm-dryrun/SKILL.md` replays the Stage 2 reference run: one block per step
(what it does, calls, output, next), then usage, then the final Orchestrator output (`stop2.md`, verbatim).
It ends with the STOP 2 decision form (`ask_user`) and a "your decision vs recorded decision" table.
The answers are recorded in chat only: nothing is written, run or dispatched. Say "step by step" to pause
after each block. A session that was already running needs `/skills reload` first.

## 3. Execution log (reference run, sequential)

Each step lists the process first, then the output.

### Step 1 — ORCHESTRATOR: preflight and Stage 1 hand-over

**Process**
- Preflight: confirmed this is the main session (it can dispatch custom agents).
- `git rev-parse` → branch `ACP2E-5597` @ `90f60c1e8ae`.
- Loaded `stop1-decisions.md` (approved contract, 4 scenarios) and the Stage 1 artifacts (`stop1.md`, `01-scribe.md`,
  `02-dependency-tracer.md`).

**Output**
- Preflight PASS. No Glean call: ticket evidence was taken from the Stage 1 artifacts.

### Step 2 — ORCHESTRATOR: Scout freshness and routing

**Process**
- `scout-list_repositories` → `magento-commerce/magento2ce`.
- `scout-ensure_fresh` → `status=verified`, `gap_files_reindexed=0`, `derived_views_verified=false`.
- Read the MIG Phase 0 command set from `dependency-tracer.agent.md`.

**Output**
- Routing: STEP 2 (Tracer) and STEP 3 (Test Author) run **sequentially**, because the Test Author needs the Phase 0 report.

### Step 3 — ORCHESTRATOR → DEPENDENCY TRACER: dispatch

| Field | Value |
|---|---|
| Agent / model | `dependency-tracer` / `mai-code-2` (`agent_definition_default`, the profile pin) |
| Mode | sync |
| Brief | Formal Phase 0 Blast-Radius Report for `Newsletter::isSatisfiedBy` and its class; MIG commands; dependent domains; callers via Scout; bulk-path guard; anti-pattern census; tests and mocks; test placement and fixtures |
| Budget | 30 tool calls, 150 output lines |

### Step 4 — DEPENDENCY TRACER: formal MIG Phase 0 blast radius

**Process** (23 tool calls, 116 s)
- MIG `impact-graph` (shell, writes only the git-ignored `var/impact` cache):
  - `--plugins-subject 'Magento\CustomerSegment\Model\Segment\Condition\Customer\Newsletter'`
  - `--plugins-method isSatisfiedBy`
  - `--mocks '<Newsletter FQCN>'` (after `--help` to confirm syntax)
  - `--mftf-module Magento_CustomerSegment`
  - `--diff HEAD`
  - plugin checks on parents `AbstractCondition` and `AbstractCombine`
- Scout: `search`, `keyword_search`, `regex_search` (census), `find_references`, `investigate_expand` for callers and source lines.

**Output** (full text: `reference/1-dependency-tracer.md`)

| Section | Result |
|---|---|
| MIG | No plugins on the class, the method or its parents; no mocks found by MIG; `--diff` empty (no change yet); 29 MFTF tests in the module, 5 newsletter-related |
| Domains | Owner: Customer Management. Dependents (adjacency map): Cart & Checkout, Order Management & Delivery, B2B Commerce. Observed consumer: Merchandising & Promotions (SalesRule) |
| Callers (provisional) | `Segment::validateCustomer()` → Root / `AbstractCombine::isSatisfiedBy()` → `Newsletter::isSatisfiedBy()`; reached from `CustomerSegmentsProvider`, `QuoteMatcher`, `Customer::processEvent`, SalesRule `FilterTextGenerator\Segment` |
| Bulk-path guard | `getSatisfiedIds()` → `getConditionsSql(null, $websiteId, false)` → `LinksMatcher`. The fix must stay in `isSatisfiedBy()` |
| Anti-pattern census | Newsletter is the only outer-root + `fetchOne()` occurrence |
| Tests and mocks | One unit mock of Newsletter (option generation only; MIG missed it); integration `CustomerTest::testProcessEventCustomerWithSubscription` |
| Test placement | Recommended: beside `Model/CustomerTest.php:338-396`; fixtures listed |
| Risks | Changing `getConditionsSql()` breaks bulk matching; dependent-domain suites still required at the gate; run `--mock-impact` once a diff exists |

Returned → ORCHESTRATOR. Saved verbatim as `1-dependency-tracer.md`.

### Step 5 — ORCHESTRATOR: receipt and spot check

**Process**
- `git status` in CE and EE: no repository change by the Tracer; `var/impact/edges.json` present.
- `scout-investigate_expand` on `Newsletter.php:120-207`: confirmed the outer `root` select with no customer filter and
  `fetchOne()` at L204.

### Step 6 — ORCHESTRATOR → TEST AUTHOR: dispatch

| Field | Value |
|---|---|
| Agent / model | `test-author` / `claude-opus-5.5` (`session_inheritance`: no profile pin) |
| Mode | sync |
| Brief | Approved contract verbatim, Phase 0 report, Stage 1 notes (cost defect; only the Defect can fail before the fix; assert rows or SQL shape, never timing) |
| Expected return | File paths, method-to-scenario map, exact commands, PHPUnit result per method |

### Step 7 — TEST AUTHOR: write the tests and run Red

**Process** (29 tool calls, 299 s)
- Read the condition source, segment, customer, cart rule and newsletter fixtures (native reads, labelled UNINDEXED:
  the Test Author has no Scout tools).
- Created `.../Model/Segment/Condition/Customer/NewsletterTest.php` (256 lines, `#[DbIsolation(false)]`, PHP 8 fixture attributes).
- Ran each method with `--filter`. One earlier Defect run failed its own guard (fixture syntax `as: 'other{1..5}'`
  created only one customer); corrected to `as: 'other', count: 5` and re-run.

**Output** (full text: `reference/2-test-author.md`)

| Method | Contract scenario | Result |
|---|---|---|
| `testValidateSubscribedCustomerExaminesOnlyThatCustomerRecord` | Defect: checking a subscribed customer examines only that customer | **FAIL (Red)**, assertion 4 of 4 |
| `testValidateUnsubscribedCustomerIsNotSatisfied` | Invariant: an unsubscribed customer does not satisfy the condition | PASS (1 assertion) |
| `testValidateCustomerSubscribedInOtherWebsiteIsNotSatisfied` | Invariant: a subscription on another website does not satisfy the condition | PASS (2 assertions) |
| `testCollectTotalsForSubscribedCustomerAppliesSegmentCartRuleDiscount` | Invariant: the cart price rule discount still applies | PASS (5 assertions) |

How the Defect test measures "examined": it enables the DB profiler around the real `Segment::validateCustomer()`
call, re-runs each captured query that touches `customer_entity` with the same bound parameters, and asserts at most
one row, belonging to the checked customer.

Open points the Test Author raised for STOP 2:
- A `LIMIT 1`-only fix would still fail the test (it returns the lowest `entity_id`, another customer).
- "Examined" is measured by rows returned, not `EXPLAIN`; an `EXPLAIN` check could be added.
- Placement differs from the Tracer's recommendation (new class instead of `Model/CustomerTest.php`).

Returned → ORCHESTRATOR. Saved verbatim as `2-test-author.md`.

### Step 8 — ORCHESTRATOR: independent Red verification

**Process**
```bash
T="$PWD/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php"
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$T" --filter testValidateSubscribedCustomerExaminesOnlyThatCustomerRecord
```

**Output** (verbatim excerpt; full text: `reference/red-verification.md`)
```
F                                                                   1 / 1 (100%)
1) ...NewsletterTest::testValidateSubscribedCustomerExaminesOnlyThatCustomerRecord
Expected only customer 17's record to be examined, got 6 rows (of 6 customers): [12, 13, 14, 15, 16, 17]
SQL: SELECT `root`.`entity_id` FROM `customer_entity` AS `root` WHERE (SELECT 1 FROM `newsletter_subscriber` AS `main`
WHERE (main.customer_id = :customer_id) AND (main.subscriber_status = 1) AND (main.store_id IN ('1')) LIMIT 1)
FAILURES!
Tests: 1, Assertions: 4, Failures: 1.
```

Verdict: **RED confirmed, failing for the right reason.** The first three assertions passed (no setup error), and
fixtures self-cleaned between runs (ids 3–8, then 12–17; 6 customers each time).

### Step 9 — HUMAN: STOP 2

Presented (`reference/stop2.md`): approved contract (verbatim), condensed Phase 0 report, method-to-scenario map with
Red/Pass, Red verification, repository changes, and these decisions:

1. **Approve Red.** Accept `NewsletterTest` as the failing Defect test and the three Invariants as pre-fix guards.
2. **Strictness.** Accept that the Defect assertion requires per-customer correlation (rules out a `LIMIT 1`-only fix),
   or relax it to "at most 1 row".
3. **Measure of "examined".** Rows returned is enough, or add an `EXPLAIN` check?
4. **Test placement.** Keep the new class, or move the tests into `Model/CustomerTest.php` as the Tracer recommended?
5. **Go-ahead for Stage 3.** Dispatch Patch Generator to fix `Newsletter::isSatisfiedBy()` only, leaving
   `getConditionsSql()` unchanged.

**State:** halted at STOP 2. Patch Generator and later stages wait for approval.

## 4. Usage (`usage.json` → `agentMetrics`)

| Participant | Model | Selection | Requests | Tool calls | AI credits |
|---|---|---|---|---|---|
| Orchestrator (main) | `claude-opus-5.5` | session | 19 | 26 | 168.42 |
| Dependency Tracer | `mai-code-2` | profile pin | 6 | 23 | 7.66 |
| Test Author | `claude-opus-5.5` | inherited (no pin) | 28 | 29 | 118.82 |
| **Total** | | | | 78 | **294.89** |

## 5. Observations

| # | Observation | Impact | Suggested action |
|---|---|---|---|
| O1 | Test Author has no model pin and inherited `claude-opus-5.5` (`session_inheritance`) | 40% of the run's credits | Decide on a pin for `test-author.agent.md` |
| O2 | Test Author has no Scout tools; its reads were native and labelled UNINDEXED | More tool calls; no index citations | Consider adding `"scout/*"` to its `tools` |
| O3 | The `create` tool failed because the target directory did not exist; the Test Author fell back to `mkdir` | One failed call | Note in the Test Author profile: create the directory first |
| O4 | `awk` was denied by the harness (line-length check) | Line length checked by eye | Allow `shell(awk)` or leave it to the PHPCS gate |
| O5 | The Orchestrator's execution log reports requested model "none" and runtime model "unknown" | Model shown only in `usage.json` | Expected; same as Stage 1 |
| O6 | Tracer and Test Author disagree on test placement | Surfaced correctly as STOP 2 decision 4 | None (working as designed) |
| O7 | Side finding: `OrdersnumberTest` uses `as: 'x{1..3}'`, which may create fewer fixtures than intended | Out of scope | Optional follow-up ticket |

## 6. Notes for the presenter

- Stage 2 is the first stage that **changes the repository**: show `git -C magento2ee status` before and after.
- Show the Red output in Step 8: the SQL has no `root.entity_id` filter, and 6 rows come back for 1 customer.
- Point out that the Invariant tests pass **before** the fix: they guard behavior; only the Defect test drives the fix.
- Point out that MIG found no plugins and no mocks, while Scout found one unit mock that MIG missed: the two tools complement each other.
