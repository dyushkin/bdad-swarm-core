# BDAD Swarm — Stage 3 Dry Run: Workshop Procedure and Reference Run (ACP2E-5597)

| Field | Value |
|---|---|
| Ticket | ACP2E-5597: the Customer Segment *Newsletter Subscription* condition scans all of `customer_entity` |
| Stage | STOP 2 approved → Patch Generator (Green) → Orchestrator Green verification → **STOP 3** (third human stop) |
| Input | Stage 2 reference run (`docs/bdad-workshop/stage2-dryrun/reference/`), the approved Red test in the working tree, and the human STOP 2 decisions (`stop2-decisions.md`) |
| Reference run | 2026-11-20T03:07:04Z → 03:13:14Z UTC (6 min 10 s), exit 0 |
| Repository effect | A two-line change to `Newsletter.php` in `magento2ee` (uncommitted). The test file is unchanged; nothing staged or committed. |
| Tooling | GitHub Copilot CLI 1.0.114; MCP server `scout`; PHPUnit 12.5.14 on PHP 8.5.12 (host) |
| Workshop kit | `docs/bdad-workshop/stage3-dryrun/` (`run-stage3.sh`, `prompt.template.txt`, `stop2-decisions.md`, `reference/`) |
| Result | **PASS with a finding.** Each method passes on its own (Defect FAIL → PASS; Invariants stay PASS). Running the whole class shows that the cart-discount Invariant fails both with and without the fix. That is a test-isolation defect in the Red test, which STOP 3 asks the human to decide on. |

## 1. Preconditions

| # | Precondition | Check |
|---|---|---|
| P1 | Stage 2 preconditions P1–P4 hold (pins, MCP, Docker services, integration DB) | Stage 2 report §1 |
| P2 | The approved Red test exists: `magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php`. For a fresh workshop run, copy it from `docs/bdad-workshop/stage2-dryrun/reference/NewsletterTest.php.txt` | `run-stage3.sh` checks it |
| P3 | `Newsletter.php` is unmodified. After a previous run, restore it with `git -C magento2ee checkout -- app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php` | `run-stage3.sh` checks it |
| P4 | Patch Generator is pinned: `model: claude-opus-5.5` in `.github/agents/patch-generator.agent.md` (changelog v2.20) | `head -5` the profile |
| P5 | STOP 2 decisions are recorded in `docs/bdad-workshop/stage3-dryrun/stop2-decisions.md` | review the file |

STOP 2 decisions (human, 2026-11-19):

| # | STOP 2 question | Decision |
|---|---|---|
| 1 | Approve Red | Approved |
| 2 | Strictness of the Defect assertion | Keep strict: correlate to the customer; a `LIMIT 1`-only fix is not acceptable |
| 3 | Measure of "examined" | Rows returned (no `EXPLAIN` check) |
| 4 | Test placement | Keep the new `NewsletterTest` class |
| 5 | Fix scope | Patch Generator decides within `Newsletter.php`; the bulk path must not change; justify the choice |
| — | Patch Generator model | Pin `claude-opus-5.5` |

## 2. Procedure

### 2.1 Live run (spends about 163 AI credits and takes about 6 minutes)

```bash
docs/bdad-workshop/stage3-dryrun/run-stage3.sh ACP2E-5597              # artifacts: ~/bdad-dryrun/ACP2E-5597-stage3-<timestamp>/
S2=/path/to/stage2/run docs/bdad-workshop/stage3-dryrun/run-stage3.sh ACP2E-5597 /tmp/demo3   # use another Stage 2 run
```

The script:
1. checks P2 and P3, records the test file's SHA1, copies `stop2-decisions.md` into `$A` and renders
   `prompt.template.txt` into `$A/prompt.txt`;
2. runs the Stage 2 harness, with one change: the only repository file it may write is
   `Newsletter.php` (the EE path and its CE `app/code` symlink). It cannot write the test directory;
3. tees the stream to `$A/stream.jsonl` and shows it in `swarm_console.py` (`PATCH-GEN` in green);
4. prints the post-run checks: exit code, repository changes, whether the test file is unchanged, artifacts present,
   and saves the diff to `$A/newsletter.diff`.

**After the workshop:** restore `Newsletter.php` (P3), or keep it for Stage 4.

### 2.2 Replay (fallback: no credits, no network)

```bash
docs/bdad-workshop/stage3-dryrun/run-stage3.sh --replay docs/bdad-workshop/stage3-dryrun/reference 10
```

### 2.3 Verify

| # | Check | Reference result |
|---|---|---|
| V1 | Exit code 0 | PASS |
| V2 | Only `Newsletter.php` changed in production code | PASS (` M .../Customer/Newsletter.php`) |
| V3 | `NewsletterTest.php` unchanged | PASS (SHA1 `c5773da5…0007` before and after) |
| V4 | Artifacts present: `execution-log.md`, `1-patch-generator.md`, `green-verification.md`, `stop3.md`, `usage.json`, `newsletter.diff` | PASS |
| V5 | Patch Generator ran on its pinned model | PASS (`claude-opus-5.5`, `agent_definition_default`) |
| V6 | The fix correlates to the customer (no `LIMIT`), and `getConditionsSql()` is untouched | PASS (diff: +1 `where`, 1 changed `matchParameters` argument) |
| V7 | Defect test FAIL → PASS; Invariant tests PASS (each method run on its own) | PASS (4 / 1 / 2 / 5 assertions) |
| V8 | Orchestrator verified independently (lint, whole class, diff) | PASS. It also found the whole-class failure (§3 Step 6) |
| V9 | Whole class, re-run outside the Swarm | Cart Invariant FAILS at `NewsletterTest.php:203`; the other 3 pass. Matches the Orchestrator |
| V10 | Specialist returned only to the Orchestrator | PASS |

### 2.4 Chat dry run (no credits; no actions)

Type `Dry-run Stage 3` in a Copilot CLI session in this repository. The project skill
`.github/skills/swarm-dryrun/SKILL.md` replays the Stage 3 reference run: one block per step
(what it does, calls, output, next), then usage, then the final Orchestrator output (`stop3.md`, verbatim).
It ends with the STOP 3 decision form (`ask_user`) and a "your decision vs recorded decision" table.
The answers are recorded in chat only: nothing is written, run or dispatched. Say "step by step" to pause
after each block. A session that was already running needs `/skills reload` first.

## 3. Execution log (reference run, sequential)

### Step 1 — ORCHESTRATOR: preflight and Stage 2 hand-over

- Main session (it can dispatch custom agents). Branch `ACP2E-5597` in CE and EE, HEAD `90f60c1e8ae`.
- Loaded `stop2-decisions.md`, the approved contract (`stop1-decisions.md`), `1-dependency-tracer.md`,
  `red-verification.md` and `Newsletter.php`.
- Recorded a baseline: the test file's SHA1 matches the one the harness recorded (`c5773da5…0007`).
- Two shell calls were denied (`shasum -a 256`; a chained `git status`) and were retried in allowed forms.
- No Glean call: the ticket intake was approved at STOP 1.

### Step 2 — ORCHESTRATOR: Scout freshness and routing

- `scout-list_repositories` → `scout-ensure_fresh`: `verified`, 0 gap files, `derived_views_verified=false`.
- Routing: one specialist, Patch Generator, run synchronously. No Tracer this time: Phase 0 from Stage 2 is still
  valid, because the code has not changed since.

### Step 3 — ORCHESTRATOR → PATCH GENERATOR: dispatch

| Field | Value |
|---|---|
| Agent / model | `patch-generator` / `claude-opus-5.5` (`agent_definition_default`, the profile pin) |
| Mode | sync |
| Brief | Approved contract and STOP 2 decisions verbatim; Phase 0 report (bulk-path guard); test path, method map and exact commands |
| Allowed | Write `Newsletter.php` only; `php -l`; `phpunit --filter`; no test changes |

### Step 4 — PATCH GENERATOR: implements the fix (Green)

9 tool calls, 80 s (03:08:47Z → 03:10:07Z).

```diff
@@ -198,9 +198,10 @@ class Newsletter extends AbstractCondition
         $customerSelect->from(['root' => $table], ['entity_id']);
+        $customerSelect->where($this->_createCustomerFilter($customer, 'root.entity_id'));
         $select = $this->getConditionsSql($customer, $websiteId);
         $customerSelect->where($select);
-        $matchedParams = $this->matchParameters($select, $params);
+        $matchedParams = $this->matchParameters($customerSelect, $params);
```

| Topic | Patch Generator's reasoning |
|---|---|
| What | The outer `customer_entity AS root` query is filtered to `root.entity_id = :customer_id`, reusing `AbstractCondition::_createCustomerFilter()`. A primary-key lookup returns at most 1 row |
| Strict (decision 2) | Correlated to the customer; no `LIMIT` |
| Binding | `matchParameters()` now scans the full query. It finds the same `:customer_id` the subquery already needed, so no new parameter is added |
| Alternative rejected | Running the subquery directly (as `Address.php` does). For "not subscribed" (value 0), `getConditionsSql()` returns an `IFNULL(...)` expression, not a select. That would need a separate wrapper per value |
| Bulk path (decision 5) | `getConditionsSql()` is untouched, so `getSatisfiedIds()`, `AbstractCombine::getSatisfiedIds()` and `LinksMatcher` build the same SQL. Verified by reading the code only |
| Tests | `php -l` clean; each of the 4 methods run separately with `--filter`: Defect PASS (4), Invariants PASS (1 / 2 / 5) |
| Tooling | No Scout tools available; one read-only `grep` on `AbstractCondition.php`, labelled UNINDEXED |

### Step 5 — ORCHESTRATOR: receipt and Green verification

- Saved the output verbatim (`1-patch-generator.md`).
- `php -l Newsletter.php` clean. `git -C magento2ee diff`: only `Newsletter.php`. The test SHA1 is unchanged.
- Ran the **whole class** (no `--filter`) instead of trusting the per-method runs.
- Checked both helpers with Scout: `_createCustomerFilter` (`AbstractCondition.php:110-113`) and
  `matchParameters` (`AbstractCondition.php:202-220`).

### Step 6 — ORCHESTRATOR: finds the whole-class failure

| Run | Defect | Unsubscribed | Other website | Cart discount |
|---|---|---|---|---|
| Whole class, with the fix | PASS | PASS | PASS | **FAIL** (`array contains '3'`, line 203) |
| Cart method alone, with the fix | — | — | — | PASS (5) |
| Whole class, fix temporarily reverted | FAIL (6 of 6 rows) | PASS | PASS | **FAIL** (`array contains '5'`, line 203) |

- Conclusion: the cart Invariant fails whenever it runs after the other tests in the same process, with or without
  the fix. The fix is not the cause. Stage 2 never ran the whole class, so this went unnoticed.
- To get the pre-fix baseline, the Orchestrator reverted `Newsletter.php` with the edit tool and re-applied it.
  The result is the same blob, `67ea0e5ae9` (see O3).
- Root cause not investigated. Probably state carried between tests in the same process (unverified).
- Stage 2 false-positive note: from the assertion counts, exactly one captured query touches `customer_entity`,
  before and after the fix.

### Red-phase test runs in this stage (summary)

Every test run in Stage 3 used the Red-phase file approved at STOP 2,
`magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php`.
It was not modified: its SHA1 was `c5773da5…0007` before and after the run. No other test was run in this stage.

| # | Who | Command scope | Code state | Result |
|---|---|---|---|---|
| 1 | Patch Generator | Each of the 4 methods, one `--filter` run per method | Fixed | Defect FAIL → PASS (4); Invariants PASS (1 / 2 / 5) |
| 2 | Orchestrator | Whole class (no `--filter`, `--testdox`) | Fixed | 3 PASS; cart Invariant FAIL (line 203) |
| 3 | Orchestrator | `--filter testCollectTotalsForSubscribedCustomerAppliesSegmentCartRuleDiscount` | Fixed | PASS (5) |
| 4 | Orchestrator | Whole class | Fix temporarily reverted | Defect FAIL (6 of 6 rows); cart Invariant FAIL; 2 PASS |
| 5 | Report author (outside the Swarm, V9) | Whole class | Fixed | Same as run 2 |

### Step 7 — HUMAN: STOP 3

`stop3.md` shows the diff, the reasoning, the per-method and whole-class table, the files changed and what was not
run (Stage 4: PHPCS Magento2, static suite, MIG `--diff` / `--mock-impact`, bulk-path tests, MFTF and the dependent
domain suites).

Decisions requested:
1. Approve Green (per-method STOP 2 table met).
2. Whole-class failure of the cart Invariant: (a) accept as a known defect for now, (b) loop back to Test Author to
   make the class order-independent (Red/Green confirmed again), or (c) investigate the root cause first.
3. Add an Invariant for "not subscribed" (value 0)? The changed query serves that case too, and no test covers it.
   This would be a contract change.
4. Bulk path: is code reading enough, or should Stage 4 run a bulk / `LinksMatcher` test?
5. Go-ahead for Stage 4 (Consensus Gateway, then Scribe egress).

## 4. Usage (`usage.json` → `agentMetrics`)

| Participant | Model | Requests | Tool calls | AI credits |
|---|---|---|---|---|
| main (Orchestrator) | `claude-opus-5.5` | 22 | 38 | 130.82 |
| patch-generator | `claude-opus-5.5` | 9 | 9 | 31.73 |
| **Total** | | **31** | **47** | **162.55** |

Premium requests: 15. Wall time 6 min 10 s.

## 5. Observations

| # | Observation | Impact |
|---|---|---|
| O1 | The Red test is not order-independent: the cart Invariant fails when the whole class runs | Stage 4 (whole-class and suite runs) will report it. The Stage 2 Red criteria only required per-method runs. Raised at STOP 3 |
| O2 | Patch Generator has no Scout tools (`tools: [shell, read, write, edit]`), the same as Test Author (Stage 2, O2) | Its code reads are UNINDEXED. The Orchestrator covered it by checking both helpers with Scout |
| O3 | The Orchestrator edited production code itself (a temporary revert and re-apply) to get a baseline | Outcome identical (same blob), but this blurs the "specialist implements" boundary. Running the class against `git stash` or a worktree would avoid it |
| O4 | `matchParameters()` now runs on the full outer query | Binds the same `:customer_id`; covered by the passing tests |
| O5 | Unknown customer ids now return false (before, they could return true if any other customer existed) | More correct; worth mentioning in release notes |
| O6 | Patch Generator usage is low: 31.7 credits (20%) even on Opus | The pin to the strongest tier is cheap here; most of the cost is the Orchestrator's verification |
| O7 | `execution-log.md` still shows the model as "unknown" (same as Stage 2, O5) | The model and source are in `stream.jsonl` and `usage.json` |

## 6. Notes for the presenter

- The highlight of this stage is **Step 6**. The specialist reported Green honestly, but only for the commands it
  was told to run. The Orchestrator does not accept a self-reported PASS: it ran the whole class, found a failure,
  and checked it against the code without the fix before blaming anything.
- Compare it with the Stage 1 and Stage 2 blast radius: the fix is two lines in one method, as predicted by Phase 0
  ("the fix stays in `isSatisfiedBy()`").
- STOP 3 is a real decision point, not a formality. Decision 2 decides whether the Swarm loops back to Test Author.
