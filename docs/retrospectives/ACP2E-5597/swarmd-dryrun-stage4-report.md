# BDAD Swarm — Stage 4 Dry Run: Workshop Procedure and Reference Runs (ACP2E-5597)

| Field | Value |
|---|---|
| Ticket | ACP2E-5597: the Customer Segment *Newsletter Subscription* condition scans all of `customer_entity` |
| Stage | STOP 3 approved → Test Author loop-back → Patch Generator Red/Green re-confirmation → Consensus Gateway (STEP 5) → Scribe egress (STEP 6) → **STOP 4** (fourth human stop) |
| Input | Stage 2 and Stage 3 reference runs, the Stage 3 fix and Red test in the working tree, and the human STOP 3 decisions (`stop3-decisions.md`) |
| Reference runs | **Stage 4:** 2026-11-20T03:35:53Z → 04:12:09Z UTC (36 min 16 s), exit 0, verdict **NOT-GREEN**. **Stage 4b:** 04:15:50Z → 04:33:57Z (18 min 7 s), exit 0, verdict **GREEN with deferrals** |
| Repository effect | `NewsletterTest.php` was made order-independent and gained a Scenario 5 method. `Newsletter.php` gained one docblock description line, from a Gateway loop-back. Nothing staged or committed; `docs/retrospectives/ACP2E-5597/` untouched |
| Tooling | GitHub Copilot CLI 1.0.114; MCP server `scout`; PHPUnit 12.5.14 on PHP 8.5.12 (host); MIG `dev/tools/impact-graph` |
| Workshop kit | `docs/bdad-workshop/stage4-dryrun/`: `run-stage4.sh`, `prompt.template.txt` and `stop3-decisions.md` for Stage 4; `run-stage4b.sh`, `prompt-4b.template.txt` and `stop4-decisions.md` for Stage 4b; recorded runs in `reference/` and `reference-4b/` |
| Result | **PASS, in two runs.** Stage 4 did all the specialist work and the Gateway caught a real CI failure, which one loop-back fixed. Two blockers remained (an over-broad §B rule and dependent-domain suites that no decision covered), so it stopped NOT-GREEN. After the human STOP 4 decisions and profile v2.24, Stage 4b ended GREEN with deferrals and produced the egress |

## 1. Preconditions

| # | Precondition | Check |
|---|---|---|
| P1 | Stage 2 preconditions P1–P4 hold (pins, MCP, Docker services, integration DB) | Stage 2 report §1 |
| P2 | The Stage 3 fix is in `Newsletter.php` (uncommitted) and the approved Red test `NewsletterTest.php` exists. For a fresh workshop run, apply `stage3-dryrun/reference/newsletter.diff` and copy the test from `stage2-dryrun/reference/NewsletterTest.php.txt` | `run-stage4.sh` checks both |
| P3 | Agents are pinned: Test Author `claude-sonnet-5.5`, Patch Generator `claude-opus-5.5`, Consensus Gateway `gpt-6.1-sol`, Scribe `claude-sonnet-5.5` (changelog v2.18, v2.20, v2.22) | `grep -n '^model:' .github/agents/*.agent.md` |
| P4 | MIG sees nested repos: `analyze --diff HEAD` lists `Newsletter.php` (changelog v2.21) | run the command |
| P5 | No `changed_files*` list exists in `dev/tests/static/testsuite/Magento/Test/_files/`. The kit writes it before the run and removes it afterwards | `run-stage4.sh` checks it |
| P6 | Stage 4b only: the working tree matches `stage4-dryrun/reference/` (`newsletter.diff`, `NewsletterTest-after.php.txt`) | `run-stage4b.sh` checks it |

STOP 3 decisions (human, 2026-11-19):

| # | STOP 3 question | Decision |
|---|---|---|
| 1 | Approve Green | Approved |
| 2 | Cart Invariant fails when the whole class runs | (b) Loop back to Test Author for order independence; Red and Green must be confirmed again |
| 3 | "Not subscribed" (`value = 0`) Invariant | Add it as Scenario 5 (contract change, 5 scenarios) |
| 4 | Bulk path | Run `SegmentMatchConsumerTest`, `…WithoutRealTimeCustomerSegmentValidationTest` and `Model/CustomerTest` |
| 5 | Gateway model | `gpt-6.1-sol` |
| 6 | Who re-confirms Red | Patch Generator (undo the fix, run Red, restore it, run Green) |
| 7 | Egress location | Artifact directory only |
| — | Gateway scope | MFTF §C skipped (no MFTF XML changed); static §B run |
| — | Loop-back cap | At most 2 Gateway loop-backs |

STOP 4 decisions after the NOT-GREEN run (human, 2026-11-19), the input to Stage 4b:

| # | STOP 4 question | Decision |
|---|---|---|
| 1 | Dependent-domain suites | Deferred to CI: `DEFERRED (human, STOP 4)` |
| 2 | 17 untracked files outside the list | Outside the ticket; §B is judged against the declared change set (profile v2.24) |
| 3 | Docblock line added by the loop-back | Approved |
| 4 | `_resetState()` isolation approach | Accepted, no bisect |
| 5 | Next | Re-run the Gateway, then Scribe egress |

## 2. Procedure

### 2.1 Live runs

```bash
# Stage 4: about 350 AI credits, about 36 minutes (the bulk integration tests take about 13 minutes per Gateway pass)
docs/bdad-workshop/stage4-dryrun/run-stage4.sh ACP2E-5597     # artifacts: ~/bdad-dryrun/ACP2E-5597-stage4-<timestamp>/

# Stage 4b: about 180 AI credits, about 18 minutes; run it after recording the STOP 4 decisions
docs/bdad-workshop/stage4-dryrun/run-stage4b.sh ACP2E-5597    # artifacts: ~/bdad-dryrun/ACP2E-5597-stage4b-<timestamp>/
```

Harness added to Stage 3's:
- Writes to `NewsletterTest.php` (Test Author) and `Newsletter.php` (Patch Generator).
- `vendor/bin/phpcs`, `diff` and `shasum` allowed; `vendor/bin/mftf` denied.
- Before the run the kit writes `dev/tests/static/phpunit.php.xml` with Allure stripped, and `changed_files_local.txt` with both ticket files as CE-relative paths. A `trap` removes the list on exit.

### 2.2 Replay (fallback: no credits, no network)

```bash
docs/bdad-workshop/stage4-dryrun/run-stage4.sh  --replay docs/bdad-workshop/stage4-dryrun/reference     30
docs/bdad-workshop/stage4-dryrun/run-stage4b.sh --replay docs/bdad-workshop/stage4-dryrun/reference-4b  30
```

### 2.3 Verify

| # | Check | Stage 4 reference | Stage 4b reference |
|---|---|---|---|
| V1 | Exit code 0 | ✔ | ✔ |
| V2 | Verdict | NOT-GREEN (2 blockers) | GREEN with deferrals |
| V3 | Dispatch models match pins | test-author `claude-sonnet-5.5`, patch-generator `claude-opus-5.5` ×2, consensus-gateway `gpt-6.1-sol` ×2 | consensus-gateway `gpt-6.1-sol`, scribe `claude-sonnet-5.5` |
| V4 | Red with the fix undone: only the Defect method fails | ✔ (`2-patch-generator.md`) | — |
| V5 | Green, whole class: 5/5 | ✔ `OK (5 tests, 13 assertions)` | ✔ |
| V6 | Production diff | Stage 3 two lines plus one docblock line | unchanged since Stage 4 |
| V7 | Static suite checked the ticket files, not nothing | ✔ both files listed | ✔ |
| V8 | Bulk path green | ✔ 6+6+3 tests | ✔ |
| V9 | Egress in `$A/egress/` only; `docs/retrospectives/` untouched | — (skipped: NOT-GREEN) | ✔ 3 files; checksum unchanged |
| V10 | Nothing staged or committed | ✔ | ✔ |

### 2.4 Chat dry run (no credits; no actions)

Type `Dry-run Stage 4` in a Copilot CLI session in this repository. The project skill
`.github/skills/swarm-dryrun/SKILL.md` replays the Stage 4 reference run: one block per step
(what it does, calls, output, next), then usage, then the final Orchestrator output (`stop4.md`, verbatim).
It ends with the STOP 4 decision form (`ask_user`) and a "your decision vs recorded decision" table.
Stage 4 has two stops: the first STOP 4 form follows the NOT-GREEN run (`reference/stop4.md`). The dry run then
continues with Stage 4b using the recorded decisions and ends with the final STOP 4 form (`reference-4b/stop4.md`).
The answers are recorded in chat only: nothing is written, run or dispatched. Say "step by step" to pause
after each block. A session that was already running needs `/skills reload` first.

## 3. Execution log (reference runs, sequential)

### Step 1 — ORCHESTRATOR: preflight (Stage 4, 03:35:53Z)
Loads `stop3-decisions.md` and the Stage 2 and 3 artifacts, runs `scout-ensure_fresh`, and records the Execution Model
preflight. Then it sends a focused brief to each specialist in turn (sync): Test Author → Patch Generator → Gateway.

### Step 2 — TEST AUTHOR: order independence + Scenario 5 (03:37:12Z → 03:38:00Z, 48 s, 10 tool calls)
- **Cause, from reading the code:** the shared `Magento\CustomerSegment\Model\Customer` caches the active segments
  for each event and website. With `DbIsolation(false)`, segments from earlier tests carry over into the cart test,
  which then failed at line 203 (`array contains '5'` / `'3'`) when the whole class ran.
- **Change:** `setUp()` calls `_resetState()` on that shared model. A new method,
  `testValidateUnsubscribedCustomerSatisfiesNotSubscribedCondition`, covers Scenario 5 with a `value => '0'` condition.
- **Result with the fix in place:** whole class `OK (5 tests, 13 assertions)`. Production code untouched.
- The cause was not proven by running without the reset. STOP 4 accepted it as is.

### Step 3 — PATCH GENERATOR: Red/Green re-confirmation (03:38:36Z → 03:39:45Z, 69 s, 8 tool calls)
| Run | How | Result |
|---|---|---|
| Red | Edit tool removes the two fix lines; `git diff --stat` is empty, so the file equals HEAD | `F....`: only the Defect method fails: *"got 6 rows (of 6 customers)"* with the uncorrelated `SELECT root.entity_id FROM customer_entity …` |
| Green | Edit tool restores the fix; the diff matches `stage3-dryrun/reference/newsletter.diff` including `index 52ac72a9e9..67ea0e5ae9` | `.....`: 5/5 pass |

### Step 4 — ORCHESTRATOR: verification
Checks that the diff equals the Stage 3 reference and runs the whole class once (5/5). Saved to `verification.md`.

### Step 5 — CONSENSUS GATEWAY, pass 1 (03:41:00Z → 03:54:25Z, 13 min 24 s, 30 tool calls)
| Check | Result |
|---|---|
| §A `php -l` (both files) | PASS |
| §A `phpcs --standard=Magento2 Newsletter.php` | **FAIL**: 2 warnings at lines 187–188 (`Missing short description`, `There must be exactly one blank line before tags`) |
| §B static suite | **FAIL**: same two warnings through `LiveCodeTest::testCodeStyle` |
| MIG `--diff HEAD` / `--mock-impact HEAD` | PASS: `Newsletter.php` detected; `mock_impact: []` |
| Bulk path + `NewsletterTest` | PASS |
| Dependent domains | **FAIL**: not run |

The warnings were already in the base file. The Gateway classed them as *"not an environmental failure… gate-blocking"*.
In CI, touching the file means the whole file is checked, so the PR would have failed the static tests. **This is the
false green that v2.23 prevented:** without the kit's changed-file list, the suite reports `OK` without checking anything.

### Step 6 — PATCH GENERATOR: loop-back 1 (03:55:20Z → 03:56:00Z, 40 s, 5 tool calls)
The Orchestrator passed the failing output on verbatim. Patch Generator added one short-description line and a blank
` *` line to the `isSatisfiedBy()` docblock. phpcs then reported 0/0 and 5/5 tests passed; the two fix lines were untouched.

### Step 7 — CONSENSUS GATEWAY, pass 2 (03:56:54Z → 04:10:02Z, 13 min 8 s, 32 tool calls)
Every check that ran passed, including phpcs and the static suite. Two items still blocked:
1. **§B coverage:** 17 untracked CE PHP files were not in the list: the MIG tool sources under `dev/tools/impact-graph/`
   and `docs/retrospectives/ACP2E-5537/AddProductsToCartErrorTest.php`. The cause is the v2.23 wording
   ("missing a changed PHP file from any repository"), which was too broad.
2. **Dependent domains:** Cart & Checkout, Order Management & Delivery and B2B Commerce (Tracer report,
   `domains.md:82`) had no results. The profile had no way to defer them.

### Step 8 — ORCHESTRATOR: STOP 4, NOT-GREEN (04:12:09Z)
The Orchestrator did not use the second loop-back: *"neither item can be fixed in the two files the agents may edit"*.
It skipped Scribe as the prompt required, and asked for five decisions (§1).

### Between the runs — human decisions and profile v2.24
- §B coverage is checked against the ticket's **declared change set**; other dirty files are reported as info.
- A new **human deferral** rule in the Cross-Domain gate: `DEFERRED (human, <stop>)`, giving **GREEN with deferrals** only if
  every check that was run passes. The Gateway never defers on its own.

### Step 9 — ORCHESTRATOR: Stage 4b preflight (04:15:50Z)
Loads `stop4-decisions.md` and confirms the working tree matches the Stage 4 reference.

### Step 10 — CONSENSUS GATEWAY, pass 3 (04:17:27Z → 04:30:50Z, 13 min 23 s, 31 tool calls)
**GREEN with deferrals.** §A ×4, §B (list coverage plus the suite: `Tests: 6, Assertions: 5, Skipped: 1`), MIG diff and
mock-impact, `SegmentMatchConsumerTest` 6/34, `…WithoutRealTime…` 6/34, `CustomerTest` 3/13 and `NewsletterTest` 5/13:
**20 tests, 94 assertions** in total. §C is N/A; the three dependent domains are `DEFERRED (human, STOP 4)`. No loop-back.
The Orchestrator re-ran phpcs and MIG itself and got the same results.

### Step 11 — SCRIBE: egress (04:32:07Z → 04:32:44Z, 37 s, 4 tool calls)
Writes `egress/dev-summary.md` (36 lines), `egress/release-notes.md` (11) and `egress/retrospective.md` (112: Manual
Steps to Reproduce, the deferred suites marked "not run locally", Usage "pending session end"). The release notes carry
the Stage 3 behavior change: a customer id with no `customer_entity` row is now *not satisfied*.

### Step 12 — HUMAN: STOP 4 (04:33:57Z)
Decisions requested: accept the verdict and the egress; who runs the deferred suites in CI; whether to copy the
retrospective into `docs/retrospectives/`; go-ahead to commit the two files.

## 4. Usage (`usage.json` → `agentMetrics`)

| Run | Participant | Model | Credits | Wall time | Tool calls |
|---|---|---|---|---|---|
| 4 | Orchestrator (main) | claude-opus-5.5 | 283.69 | — | 37 |
| 4 | Test Author | claude-sonnet-5.5 | 15.45 | 48 s | 10 |
| 4 | Patch Generator (Red/Green) | claude-opus-5.5 | 19.08 | 69 s | 8 |
| 4 | Consensus Gateway, pass 1 | gpt-6.1-sol | 9.98 | 13 min 24 s | 30 |
| 4 | Patch Generator (loop-back) | claude-opus-5.5 | 14.61 | 40 s | 5 |
| 4 | Consensus Gateway, pass 2 | gpt-6.1-sol | 7.52 | 13 min 8 s | 32 |
| **4** | **Total** | | **350.34** | **36 min 16 s** | premium requests 15 |
| 4b | Orchestrator (main) | claude-opus-5.5 | 157.87 | — | 27 |
| 4b | Consensus Gateway, pass 3 | gpt-6.1-sol | 9.63 | 13 min 23 s | 31 |
| 4b | Scribe | claude-sonnet-5.5 | 11.78 | 37 s | 4 |
| **4b** | **Total** | | **179.28** | **18 min 7 s** | premium requests 15 |

The Gateway's wall time is almost all PHPUnit: about 70 s of model API time per pass against about 13 minutes of tests
(the two `SegmentMatchConsumer` classes dominate). The Orchestrator is 81–88% of the credits.

## 5. Observations

| # | Observation | Effect |
|---|---|---|
| O1 | Each dry run found a real tooling blind spot for nested repos (EE/B2B symlinked into CE). MIG `--diff` returned `changed: []` (fixed in v2.21), and `LiveCodeTest` reported `OK` while checking no files (v2.23) | Both were silent false greens. The Gateway now has to quote which files were checked |
| O2 | The Gateway caught warnings that were already in the touched file, which CI would have failed. One loop-back to the right owner fixed them in 40 s | The blocking loop-back works as designed |
| O3 | My own v2.23 rule was too broad: it blocked on 17 workspace files outside the ticket | Fixed in v2.24 (declared change set). A rule written in a hurry needs a dry run too |
| O4 | The Cross-Domain gate had no human deferral. The "MUST run" dependent-domain suites can't run locally (no MFTF browser stack) | v2.24 adds `DEFERRED (human, <stop>)` and GREEN with deferrals. The deferral is explicit in the verdict and the egress |
| O5 | The Orchestrator did not use the second loop-back, because neither blocker had a file owner | Correct: loop-backs are for fixable defects, not for decisions that belong to a human |
| O6 | Test Author's isolation cause comes from reading code; it was not proven by running without the fix | Accepted at STOP 4. Proving it would cost about 1 min of tests |
| O7 | Patch Generator re-confirmed Red with the edit tool (no `git stash`/`checkout`) and proved the file was restored exactly by its blob index | The STOP 3 decision 6 pattern works inside the restricted harness |
| O8 | Scribe worked only from the Orchestrator's handoff (4 tool calls, 37 s) | Cheap egress; its quality depends on the brief |

## 6. Notes for the presenter

- Tell the story as **NOT-GREEN → human decisions → GREEN with deferrals**. The NOT-GREEN run is the useful part: the
  gate blocked a real CI failure, recovered on its own through a loop-back, and then stopped at what only a human could decide.
- Show `reference/3-consensus-gateway.md` (the docblock FAIL) next to `reference/4-patch-generator.md` (the fix).
- For time, replay the runs (§2.2). Each live Gateway pass spends about 13 minutes in the integration tests.
- Reset after a live run: restore `Newsletter.php` to the Stage 3 or Stage 4 state you need (see P2 and P6), then copy
  `reference/NewsletterTest-after.php.txt` or the Stage 2 test back.
