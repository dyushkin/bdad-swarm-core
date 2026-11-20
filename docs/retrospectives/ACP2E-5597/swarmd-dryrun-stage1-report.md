# BDAD Swarm — Stage 1 Dry Run: Workshop Procedure and Reference Run (ACP2E-5597)

| Field | Value |
|---|---|
| Ticket | ACP2E-5597: the Customer Segment *Newsletter Subscription* condition scans all of `customer_entity` |
| Stage | Intake → **STOP 1** (first human stop) |
| Reference run | 2026-11-20T01:33:24Z → 2026-11-20T01:39:18Z UTC, exit 0, repository unchanged |
| Tooling | GitHub Copilot CLI 1.0.114; MCP servers `glean` and `scout`; agent profiles at Swarm changelog v2.17 |
| Workshop kit | `docs/bdad-workshop/stage1-dryrun/` (`run-stage1.sh`, `swarm_console.py`, `swarm_emulate.py`, `prompt.template.txt`, `reference/`) |
| Result | **PASS**. The run reached STOP 1, and the Given/When/Then behavior contract is presented verbatim. |

## 1. Preconditions

| # | Precondition | Check |
|---|---|---|
| P1 | The repository is on the ticket branch and has the v2.17 agent profiles | `git rev-parse --abbrev-ref HEAD` |
| P2 | The model pins are Orchestrator `claude-opus-5.5`, Scribe `claude-sonnet-5.5` and Tracer `mai-code-2` | `grep -h '^model:' .github/agents/{orchestrator,scribe,dependency-tracer}.agent.md` |
| P3 | The MCP servers `glean` (authenticated) and `scout` are configured in `~/.copilot/mcp-config.json` | `copilot` → `/mcp` |
| P4 | Scout has `magento-commerce/magento2ce` attached and idle | `scout-list_repositories` |
| P5 | `copilot`, `python3` and `git` are on `PATH`, and the terminal is at least 160 columns wide | `copilot --version` |

## 2. Procedure

### 2.1 Live run (spends about 113 AI credits and takes about 6 minutes)

```bash
docs/bdad-workshop/stage1-dryrun/run-stage1.sh ACP2E-5597            # artifacts: ~/bdad-dryrun/ACP2E-5597-<timestamp>/
docs/bdad-workshop/stage1-dryrun/run-stage1.sh ACP2E-5597 /tmp/demo  # or an explicit directory outside the repository
```

The script:
1. renders `prompt.template.txt` into `$A/prompt.txt`;
2. runs the runbook §2 harness (`copilot -p … --agent orchestrator`, read-only allow and deny rules,
   `--usage-output-file`, `--output-format json`);
3. tees the JSON stream to `$A/stream.jsonl` and pipes it into `swarm_console.py` for a live, readable view;
4. prints the post-run checks: exit code, repository unchanged, artifacts present, and a `gherkin` contract in STOP 1.

### 2.2 Replay (fallback: no credits, no network)

```bash
docs/bdad-workshop/stage1-dryrun/run-stage1.sh --replay docs/bdad-workshop/stage1-dryrun/reference 10   # 10x speed
python3 docs/bdad-workshop/stage1-dryrun/swarm_console.py --replay <ARTIFACT_DIR>/stream.jsonl --speed 5 --max-lines 40
```

`reference/` holds this run's `stream.jsonl`, `usage.json` and artifacts. `--max-lines` caps each brief and
output block on screen; the full text is in the artifacts.

### 2.3 Sequential emulation (presentation mode: no credits, no network)

```bash
docs/bdad-workshop/stage1-dryrun/run-stage1.sh --emulate docs/bdad-workshop/stage1-dryrun/reference   # Enter = next step
docs/bdad-workshop/stage1-dryrun/run-stage1.sh --emulate <ARTIFACT_DIR> --no-pause                    # any recorded run
```

This mode walks a recorded run one participant at a time, in eight steps, and prints only the essentials.
The live console instead shows the tool calls interleaved.

**In the Copilot CLI chat:** type `Dry-run Stage 1` (add `step by step` to pause for `next`).
The project skill `.github/skills/swarm-dryrun` replays the reference run without taking any action.
Each step states what it does and what it outputs. The skill then prints the final Orchestrator output (`stop1.md`,
verbatim) and shows the STOP 1 decision form. The answers are compared with the recorded decisions and are not executed.

| Step | Actor | Shows |
|---|---|---|
| 1 | MAIN | Execution Model preflight result |
| 2 | MAIN | Glean calls, ticket title, and the snapshot source with its indexed time |
| 3 | MAIN | Scout `list_repositories` and `ensure_fresh`: `status`, `gap_files_reindexed`, `derived_views_verified` |
| 4 | MAIN | Routing decision; dispatches with model, model source and mode |
| 5 | SCRIBE | Input, tool summary, brief sections, domain, **behavior contract (verbatim)**, return to MAIN |
| 6 | TRACER | Input and budget, tool summary and retries, one line per finding section, return to MAIN |
| 7 | MAIN | Receipt order (MAIN only), saved artifacts, the Orchestrator's synthesis notes |
| 8 | HUMAN | STOP 1: what is presented and the decisions requested; halted |
| — | — | Usage per participant (model, requests, AI credits) |
| — | MAIN | Chat dry run only: the final Orchestrator output (`stop1.md`, verbatim) |
| — | HUMAN | Chat dry run only: STOP 1 decision form, then "your decision vs recorded decision"; no action taken |

### 2.4 Reading the live/replay console

| Console element | Meaning |
|---|---|
| `[MAIN   ]` (magenta) | The Orchestrator, running as the main session |
| `[SCRIBE ]` (cyan), `[TRACER ]` (yellow) | Specialist subagents |
| `▶ tool  args` / `✔` / `✖ reason` | A tool call, then its success or failure (denials included) |
| `💬` | The participant's narration (first line) |
| `⇒ DISPATCH X model=… (agent_definition_default)` | The Orchestrator starts a subagent; the source shows the frontmatter pin was used |
| `BRIEF MAIN → X` block | The full brief the Orchestrator sent |
| `tools allowed:` | The subagent's effective tool allowlist |
| `⇐ RETURNED to MAIN …` | The subagent finished: model, tool calls, duration, tokens |
| `OUTPUT X → MAIN` block | The specialist's verbatim result, received only by the Orchestrator |
| `MAIN — final message` block | The Orchestrator's closing message (STOP 1) |
| `RESULT …` | Exit code, premium requests, session time, and tool calls per participant |

Console excerpt from the reference run:

```text
01:33:27 [MAIN   ] MCP server connected: scout
01:33:28 [MAIN   ] MCP server connected: github-mcp-server
01:33:28 [MAIN   ] MCP server connected: glean
01:33:34 [MAIN   ] 💬 Preflight: custom agents (scribe, dependency-tracer, …) are dispatchable from this session, so I'm the session-level Orchestrator — PASS. Loading Glea
01:33:34 [MAIN   ] ▶ tool_search_tool           pattern=^(glean-search|glean-read_document|scout-list_repositories|scout-ensure_fresh)$
01:33:34 [MAIN   ] ▶ glob                       pattern=docs/retrospectives/ACP2E-5597/**
01:33:34 [MAIN   ] ▶ bash                       command=git rev-parse --abbrev-ref HEAD; git --no-pager log -1 --oneline
01:33:34 [MAIN   ]   ✔ tool_search_tool
01:33:34 [MAIN   ]   ✔ bash
01:33:35 [MAIN   ]   ✔ glob
01:33:37 [MAIN   ] ▶ glean-search               query=ACP2E-5597
01:33:37 [MAIN   ] ▶ scout-list_repositories    repo=Magento-1
…
01:34:55 [MAIN   ] ⇒ DISPATCH TRACER  model=mai-code-2 (agent_definition_default)  mode=sync
01:34:55 [MAIN   ] ⇒ DISPATCH SCRIBE  model=claude-sonnet-5.5 (agent_definition_default)  mode=sync
01:34:55 [SCRIBE ] tools allowed: shell, read, write, edit
01:34:55 [TRACER ] tools allowed: shell, read, edit, search, scout/*
01:35:20 [SCRIBE ] ⇐ RETURNED to MAIN  model=claude-sonnet-5.5  tool calls=1  25.4s  tokens=33198
01:37:31 [TRACER ] ⇐ RETURNED to MAIN  model=mai-code-2  tool calls=20  156.8s  tokens=473852
…
```

### 2.5 Verify

| # | Check | Reference result |
|---|---|---|
| V1 | Exit 0, repository unchanged (printed by the script) | PASS |
| V2 | Artifacts: `execution-log.md`, `1-scribe.md`, `2-dependency-tracer.md`, `stop1.md`, `usage.json` | PASS (the reference names were `01-`/`02-`; the template now fixes `1-`/`2-`) |
| V3 | `⇒ DISPATCH` models: Scribe `claude-sonnet-5.5`, Tracer `mai-code-2`; source `agent_definition_default` | PASS |
| V4 | Tracer tool calls ≤ 30 (`⇐ RETURNED` line) | PASS (20) |
| V5 | The Tracer uses `scout-*` tools | PASS |
| V6 | `scout-ensure_fresh` result recorded in the execution log and at STOP 1 | PASS (`verified`; `derived_views_verified: false` disclosed) |
| V7 | Both `OUTPUT … → MAIN` blocks go to MAIN; no `write_agent` | PASS |
| V8 | `stop1.md` contains Scribe's `gherkin` contract verbatim: 1 `Defect:` plus `Invariant:` scenarios, ≤ 5 | PASS (identical, 35 lines) |
| V9 | The session ends at STOP 1 with *Decisions requested*; no Phase 0 or Test Author | PASS |

## 3. Execution log (reference run)

Chain: **Orchestrator** → [**Scribe** ∥ **Dependency Tracer**] → **Orchestrator** → **STOP 1**. The tables are
generated from `stream.jsonl`, with times in UTC.

### 3.1 Orchestrator: preflight, ticket evidence and Scout freshness

| # | Time | Tool | Arguments | Result |
|---|---|---|---|---|
| 1 | 01:33:34 | `tool_search_tool` | `pattern=^(glean-search\|glean-read_document\|scout-list_repositories\|scout-ensure_fresh)$` | OK |
| 2 | 01:33:34 | `glob` | `pattern=docs/retrospectives/ACP2E-5597/**` | OK |
| 3 | 01:33:34 | `bash` | `command=git rev-parse --abbrev-ref HEAD; git --no-pager log -1 --oneline` | OK |
| 4 | 01:33:37 | `glean-search` | `query=ACP2E-5597` | OK |
| 5 | 01:33:37 | `scout-list_repositories` | `repo=Magento-1` | OK |
| 6 | 01:33:37 | `view` | `path=docs/retrospectives/ACP2E-5597/retrospective.md` | OK |
| 7 | 01:33:44 | `scout-ensure_fresh` | `repo=Magento-1` | OK |
| 8 | 01:33:44 | `bash` | `command=head -c 6000 /var/folders/q_/zzxhys412_116d093fgh_fhr0000gq/T/1795138420562-copilot-tool-o…` | OK |
| 9 | 01:34:09 | `glean-read_document` | `urls=["https://jira.corp.adobe.com/browse/ACP2E-5597"]` | OK |
| 10 | 01:34:15 | `tool_search_tool` | `pattern=^scout-(keyword_search\|explain_symbol)$` | OK |
| 11 | 01:34:17 | `scout-keyword_search` | `q=isSatisfiedBy  q=getCustomerSegmentIdsByCustomerIdQuoteId` | OK |

Output: preflight **PASS** (main session; custom agents dispatchable). The ticket was read from the Glean
snapshot (indexed 2026-11-19T11:23:46Z). Scout `ensure_fresh` returned `verified`, with 0 gap files and
`derived_views_verified: false`. The named-symbol exception applies, so the Tracer runs in parallel with Scribe.

### 3.2 Orchestrator: dispatch

| # | Time | Tool | Arguments | Result |
|---|---|---|---|---|
| 1 | 01:34:55 | `task` | dispatch `scribe` (sync) | OK |
| 2 | 01:34:55 | `task` | dispatch `dependency-tracer` (sync) | OK |

Both dispatches are synchronous and parallel. The models come from the frontmatter pins
(`agent_definition_default`).

### 3.3 Scribe (intake and behavior contract)

Process log: 1 tool call(s), 25.4 s, 33,198 tokens,
`claude-sonnet-5.5`.

| # | Time | Tool | Arguments | Result |
|---|---|---|---|---|
| 1 | 01:34:56 | `view` | `path=.github/agents/reference/domains.md` | OK |

Output (verbatim, `1-scribe.md`):

#### Intake brief: ACP2E-5597

Source: Glean Jira snapshot (indexed 2026-11-19T11:23:46Z, not live), plus the local triage record and Scout anchors from the handoff. I did not read the code or the retrospective myself. Anchors below are "per Orchestrator handoff".

##### Title
Customer segment "Newsletter Subscription" condition reads the whole `customer_entity` table on every check. This slows cart totals for large customer bases.

##### Scenario
A registered, newsletter-subscribed customer's cart totals are calculated, and `REST POST /rest/V1/carts/mine/totals-information` is the observed entry point. An active cart price rule is conditioned on a Customer Segment. That segment uses the condition "Newsletter Subscription is subscribed".

Since ACP2E-2926, every active segment is validated on each totals calculation. The Newsletter condition's per-customer check issues an outer `SELECT root.entity_id FROM customer_entity AS root WHERE (<uncorrelated subquery>)` with no customer filter and no LIMIT. For a subscribed customer the subquery is true for every row, so MySQL returns all `customer_entity` rows to PHP, which uses only the first.

##### Preconditions
- Clean 2.4-develop CE+EE (reporter used commit 0af2ad9f), PHP 8.4, default mode, indexers on Update by Schedule, Customer Segments enabled.
- `customer_entity` has a very large row count (3M in the report). The website does not matter.
- A customer is subscribed to the newsletter (`newsletter_subscriber.subscriber_status = 1`) in a store of the segment's website.
- An active segment exists for that website, for registered customers, with the condition "Newsletter Subscription is subscribed".
- An active, couponless cart price rule (e.g. 10%) has the condition "Customer Segment is <segment>". `salesrule_rule` has been reindexed. Without that reindex there are 0 newsletter queries.
- The cart is **not empty**. An empty cart is rejected before `collectTotals`, so the path is never reached. The local triage record confirms this.
- The `customer_segments` cache type is cleaned before each measurement. A cache hit hides the cost: ACP2E-4299 caches per customer/website/cart for one hour.

##### Steps (from the ticket, verbatim in substance)
1. Create a customer on the default website and subscribe them to the newsletter.
2. Create an active segment for the default website, registered customers, with the condition Newsletter Subscription is subscribed.
3. Create an active couponless cart price rule (e.g. 10%) with the condition Customer Segment is that segment. Reindex `salesrule_rule`.
4. As that customer, add a product to the cart. Clean the `customer_segments` cache type. POST `/rest/V1/carts/mine/totals-information`.

Annotations (implied by the ticket, not stated in it):
- The cart must be non-empty.
- The customer must be subscribed. Unsubscribed costs about 0.1 ms.
- The cache must be cold.

##### Expected vs Actual
**Expected:** the condition examines only the given customer, so cost does not depend on the number of customers.

**Actual:** the discount applies, but the full table is read for subscribed customers.

| Customers | Totals time | Query time |
|---|---|---|
| 4 rows | ~510 ms | 0 ms |
| 100k | ~530 ms | 24 ms |
| 1M | ~750 ms | 248 ms |
| 3M | ~1,260 ms | ~745 ms |

- Warm cache at 3M is ~465 ms.
- Direct query at 3M: 754.5 ms as built, 2.2 ms with `LIMIT 1`, 0.4 ms with `root.entity_id = :customer_id`.
- The merchant's 2.9M-row copy shows 381 ms.
- New Relic production: the `customer_entity` select per totals-information goes from 2 ms to 186 ms. For shipping-information it goes from 4 ms to 217 ms.
- Local reproduction (full CE/EE/B2B/MSI, branch ACP2E-5597) returned AFF 3,000,001 rows in 0.3465 s within a 408 ms HTTP 200 request, with SQL and call-stack logging on.

The reporter's suggested fix is a customer filter or `LIMIT 1`. This brief does not adopt it. The reporter also says other conditions with a root select on `customer_entity` may share the pattern. That is unverified.

##### Code anchors (per Orchestrator handoff, Scout-returned, not re-read by me)
- `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php:187-206` (method `isSatisfiedBy`, signature at L193).
- `magento2ee/app/code/Magento/CustomerSegment/Model/CustomerSegmentsProvider.php:25-139` (class; `getCustomerSegmentIdsByCustomerIdQuoteId` declared at L80).
- Callers named in the ticket but not Scout-verified: `SalesRule\Model\Validator::initTotals` and the free shipping calculator.

##### Domain classification
- **Primary: Customer Management.** Customer groups and segments (`Magento_CustomerSegment`).
- **Touches:**
  - Merchandising & Promotions (`Magento_SalesRule` consumer, Newsletter).
  - Cart & Checkout (totals-information and shipping-information paths).
  - Performance (a query-cost defect).
  - Commerce Web APIs (REST entry point).
- Per the adjacency map for a Customer Management change, the dependent suites are Cart & Checkout, Order Management & Delivery and B2B Commerce. Merchandising & Promotions is also relevant because of SalesRule.

##### Repro class
- **`repro-class: single-request`.** It needs no concurrent sessions or stale cross-request state. It is a deterministic single-process behavior.
- It is data-volume sensitive. The ticket's own measurements show about 4 ms to 745 ms across 4 rows to 3M rows. Wall-clock assertions are fragile.
- **Vehicle hint:** assert the structure of the query rather than timing. Capture the SQL, or the rows returned or examined, for the Newsletter check with extra `customer_entity` rows present. It should be independent of the table size. An integration test is feasible with a few extra customers plus a row-count or SQL-shape assertion. MFTF is not required.
- The manual repro needs a large dataset, and ~3M rows were already seeded locally.

##### Open questions / evidence gaps
1. Kateryna Muntianu (2026-11-16) could not confirm a performance issue from a 4M vs 3M comparison. The ticket's numbers and the local reproduction contradict this. The comparison probably used an unsubscribed customer, a warm cache, or an unreindexed rule. This needs confirming.
2. The local reproduction ran with SQL and call-stack logging on. There are no logging-off numbers, no warm-cache numbers and no small-data baseline locally.
3. Discount application was not separately confirmed in the local final response.
4. Scope: do other segment conditions that build a root select on `customer_entity` share the pattern? The ticket says "may". Should those be in scope? Recommend a Dependency Tracer sweep, and the human decides at STOP 1.
5. Does the "subscribed in a store of the website" filter (`store_id IN (...)`) need to be preserved exactly? The contract assumes yes.
6. Backports: ACP2E-5598 (2.4.8-p5) blocks on this ticket. Code is identical in 102.1.5-p16 and 102.1.8-p3 per the reporter. Not verified here.
7. The Scout index was `derived_views_verified=false` (indexed through 90f60c1e8ae).

##### Behavior contract
```gherkin
Scenario: Defect: Checking the newsletter condition for a subscribed customer examines only that customer
  Given customer segments are enabled and a segment is active for the customer's website
  And   the segment condition is "Newsletter Subscription is subscribed"
  And   many other customers exist on the store besides the one being checked
  And   the checked customer is subscribed to the newsletter in a store of the segment's website
  When  the segment condition is evaluated for that customer
  Then  the condition is satisfied
  And   only that customer's record is examined, not the other customers' records

Scenario: Invariant: An unsubscribed customer does not satisfy the newsletter condition
  Given an active segment with the condition "Newsletter Subscription is subscribed"
  And   the customer is not subscribed to the newsletter
  When  the segment condition is evaluated for that customer
  Then  the condition is not satisfied

Scenario: Invariant: A subscription in a store outside the segment's website does not satisfy the condition
  Given an active segment with the condition "Newsletter Subscription is subscribed" for one website
  And   the customer is subscribed only in a store that belongs to a different website
  When  the segment condition is evaluated for that customer
  Then  the condition is not satisfied

Scenario: Invariant: The cart price rule discount still applies to a subscribed customer
  Given an active couponless cart price rule conditioned on a customer segment whose condition is "Newsletter Subscription is subscribed"
  And   the customer is subscribed and has a product in the cart
  When  the cart totals are calculated
  Then  the discount is applied to the cart totals

Scenario: Invariant: The amount of customer data examined does not depend on how many customers exist
  Given the same subscribed customer and segment as above
  And   the store has either few customers or very many customers
  When  the segment condition is evaluated for that customer
  Then  the same limited set of records is examined in both cases
  # OPEN: Should this be merged with the Defect scenario? It is kept separate to express scale independence. Merge it if the 5-scenario cap or redundancy is a concern.
```

Notes on the contract:
- The inverse "unsubscribed" check relies on the ticket's own measurement (unsubscribed 0.1 ms) and observed correctness.
- The Then clauses avoid timings and class names.
- Scenarios 1 and 5 overlap. The Orchestrator can collapse them at STOP 1.
- "Unsubscribed" assumes `subscriber_status` values other than 1 all count as not subscribed. This is unverified.

Dry run: I wrote no files and ran no tools beyond reading `domains.md`.

### 3.4 Dependency Tracer (pre-intake trace; not MIG Phase 0)

Process log: 20 tool calls (budget 30), 156.8 s, 473,852 tokens,
`mai-code-2`.

| # | Time | Tool | Arguments | Result |
|---|---|---|---|---|
| 1 | 01:35:06 | `scout-investigate` | `query=ACP2E-5597 Newsletter::isSatisfiedBy CustomerSegmentsProvider mechanism sibling conditions…` | **Failed:** MCP server 'scout': McpError: MCP error -32602: investigate_phase_invalid: structured probes require resume_bu |
| 2 | 01:35:13 | `scout-search` | `query=ACP2E-5597 Newsletter isSatisfiedBy CustomerSegmentsProvider uncorrelated customer_entity …` | OK |
| 3 | 01:35:13 | `scout-keyword_search` | `q=class Newsletter, function isSatisfiedBy, CustomerSegmentsProvider  q=fetchOne, createSelect, getConditionsS` | OK |
| 4 | 01:35:20 | `scout-investigate` | `repo=Magento-1` | OK |
| 5 | 01:35:39 | `scout-investigate_expand` | `targets=["magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.…` | OK |
| 6 | 01:35:39 | `scout-find_references` | `repo=Magento-1` | OK |
| 7 | 01:35:39 | `scout-call_graph` | `repo=Magento-1` | OK |
| 8 | 01:35:39 | `scout-regex_search` | `pattern=public function isSatisfiedBy\(\|fetchOne\(` | OK |
| 9 | 01:35:50 | `scout-keyword_search` | `q=customer_entity  q=root.entity_id  q=_createCustomerFilter` | OK |
| 10 | 01:35:50 | `scout-call_graph` | `repo=Magento-1` | OK |
| 11 | 01:35:50 | `scout-find_references` | `symbols=["getSatisfiedIds", "getConditionsSql"]` | OK |
| 12 | 01:35:50 | `scout-keyword_search` | `q=free_shipping  q=generateFilterText  q=setValidationFilter` | OK |
| 13 | 01:36:05 | `scout-investigate_expand` | `targets=["magento2ee/app/code/Magento/CustomerSegment/Model/Condition/Combine/AbstractCombine.php:…` | OK |
| 14 | 01:36:05 | `scout-keyword_search` | `q=Newsletter\Model, Customer/Newsletter.php, Condition\Customer\Newsletter  q=Newsletter Subscription, newslet` | OK |
| 15 | 01:36:05 | `scout-search` | `query=Customer Segment matching all customers batch index getSatisfiedIds LinksMatcher Root vali…` | OK |
| 16 | 01:36:13 | `scout-investigate_expand` | `targets=["magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Attributes.…` | OK |
| 17 | 01:36:20 | `scout-investigate_expand` | `targets=["magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/CustomerTest.ph…` | OK |
| 18 | 01:36:23 | `scout-investigate_expand` | `targets=["magento2ee/app/code/Magento/CustomerSegment/Model/ResourceModel/Customer/LinksMatcher.ph…` | OK |
| 19 | 01:36:40 | `scout-investigate_expand` | `targets=["magento2ee/app/code/Magento/CustomerSegment/Model/Segment.php:310-375", "magento2ee/app/…` | OK |
| 20 | 01:36:57 | `scout-investigate_expand` | `targets=[".github/agents/reference/domains.md:1-240"]` | OK |

Output (verbatim, `2-dependency-tracer.md`):

##### ACP2E-5597 — Pre-intake mechanism report

**Owning domain:** Customer Management (`Magento_CustomerSegment`); observed consumers: Merchandising & Promotions, Cart & Checkout, Commerce Web APIs. Domain-map dependents: Cart & Checkout, Order Management & Delivery, B2B Commerce (`.github/agents/reference/domains.md:29,82`).

###### 1. Mechanism

**Ticket claim confirmed.**

- `Newsletter::isSatisfiedBy()` creates an outer `customer_entity AS root` select directly through `getResource()->createSelect()`, selecting every customer row without customer or website correlation, then attaches the condition as a `WHERE` subquery (`Newsletter.php:193-204`).
- `getConditionsSql(..., $isFiltered=true)` creates the inner `newsletter_subscriber` select and correctly filters `main.customer_id` using `_createCustomerFilter($customer, ...)`, subscription status, store/website, and `LIMIT 1` (`Newsletter.php:129-149`).
- That customer filter therefore constrains the inner subscription lookup, but it does **not** constrain `root`. The resulting condition is effectively an uncorrelated existence test: if the supplied customer is subscribed, some outer customer row matches and `fetchOne()` returns `> 0` (`Newsletter.php:198-205`).
- This is an override, not the inherited base behavior. `AbstractCondition::isSatisfiedBy()` fetches `getConditionsSql()` directly; it does not add a separate customer root select (`AbstractCondition.php:153-161`).
- `$isFiltered` is only bypassed for bulk matching; default per-customer evaluation enters the filtered branch at `Newsletter.php:134`.
- A `root.entity_id`/customer correlation or equivalent inner/outer query rewrite would address the immediate mechanism. `LIMIT 1` alone on the outer query may reduce scanning but does not correct which outer rows qualify; the inner select already has `LIMIT 1` (`Newsletter.php:141`).

###### 2. Sibling-pattern census

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

###### 3. Caller and batch paths

- **Segment validation:** `Segment::validateCustomer()` builds parameters and delegates to the root condition’s `isSatisfiedBy()` (`Segment.php:310-348`).
- Root combine dispatches to child conditions, including `Newsletter` (`Condition/Combine/Root.php:113-136`; `Condition/Combine/AbstractCombine.php:250-263`).
- **Provider runtime path:** `CustomerSegmentsProvider` consumes `QuoteMatcher` results, then falls back to `segment->validateCustomer($customerId, $websiteId)` (`CustomerSegmentsProvider.php:96-109`).
- **SalesRule path:** `SalesRule\Validator::getRules()` invokes collection `setValidationFilter()` with the quote address (`Validator.php:264-281`). `AdvancedSalesRule`’s collection plugin builds filter-text generators (`Collection.php:63-120`).
- **Customer-segment generator:** it obtains segment IDs from `CustomerSegmentsProvider` (`FilterTextGenerator/Segment.php:66-86`). This can gate selection of sales rules, including rules carrying free-shipping actions.
- **Batch/indexing path:** `AbstractCombine::getSatisfiedIds()` evaluates child bulk queries (`AbstractCombine.php:271-286`); `LinksMatcher` calls that path for every website and stores all matched customer IDs (`LinksMatcher.php:131-140`). Newsletter uses `getConditionsSql(null, website, false)`, so a fix placed unconditionally in `getConditionsSql()` could break “match all customers.” Keeping correlation per-customer in `isSatisfiedBy()` is safer unless bulk semantics are explicitly branched.
- A separate event path uses persisted combined `condition_sql` and direct SQL parameter binding (`Model/Customer.php:251-275,329-365`); it bypasses the PHP `isSatisfiedBy()` traversal for matching events.

###### 4. Existing tests

- Strongest integration coverage: `testProcessEventCustomerWithSubscription` configures a Newsletter condition and asserts customer/segment membership for three customers (`dev/tests/integration/testsuite/Magento/CustomerSegment/Model/CustomerTest.php:338-396`).
  - It does **not** cover a truly unsubscribed customer being assigned the segment; the negative case is caused by the additional `created_at` condition, so it can pass despite this defect.
- Unit `CustomerTest` only verifies the condition factory exposes `Newsletter`; it mocks the Newsletter object and never executes its SQL (`Test/Unit/.../Condition/CustomerTest.php:21-26,68-89,104-144`).
- Provider and filter-generator unit tests cover segment ID reuse/propagation, not SQL correctness (`CustomerSegmentsProviderTest.php:32-63`; `FilterTextGenerator/SegmentTest.php:127-142`).
- MFTF coverage exists for subscribed/not-subscribed newsletter segment administration, including:
  - `Test/Mftf/Test/AdminCreateCustomerSegmentNewsletterSubscriptionTest.xml:25`
  - `Test/Mftf/Test/AdminCreateCustomerSegmentNotSubscribedNewsletterTest.xml:25`
  - corresponding “without real-time check” variants.
- No direct unit test of `Newsletter::isSatisfiedBy()` was located.

###### 5. Risks / open questions

- Correlation belongs in the per-customer query; changing `getConditionsSql(..., false)` risks bulk matching/index rebuilds.
- The fix should distinguish subscribed, not-subscribed, negated condition/combine behavior, and visitor handling (`Newsletter.php:147-178,195-197`).
- If correlation is added through the outer `root`, ensure the binding remains compatible with `_createCustomerFilter()` when `$customer` is an ID, object, expression, or select.
- Suggested regression: one subscribed and one unsubscribed customer in the same website; evaluate the unsubscribed customer’s segment while a subscription for another customer exists. Also exercise “not subscribed” and bulk `getSatisfiedIds()`/link matching.
- Scout graph freshness is unverified and derived symbol/vector views are not guaranteed; no MIG Phase 0 cache-writing analysis was run.

### 3.5 Orchestrator: receipt, artifacts and STOP 1

| # | Time | Tool | Arguments | Result |
|---|---|---|---|---|
| 1 | 01:38:19 | `create` | `path=01-scribe.md` | OK |
| 2 | 01:38:19 | `create` | `path=02-dependency-tracer.md` | OK |
| 3 | 01:38:51 | `create` | `path=stop1.md` | OK |
| 4 | 01:39:09 | `create` | `path=execution-log.md` | OK |

Output (verbatim, `stop1.md`):

##### STOP 1 — Intake gate: ACP2E-5597 (DRY RUN)

**Preflight:** PASS. This session can dispatch custom agents, so it is running as the main-session Orchestrator.
**Branch:** `ACP2E-5597` @ `90f60c1e8ae`.
**Ticket evidence:** Glean Jira snapshot https://jira.corp.adobe.com/browse/ACP2E-5597, indexed update 2026-11-19T11:23:46Z. This is a snapshot, not live state.
- Status: Triage / Unresolved, Major.
- Assignee: Dmytro Yushkin. Reporter: Luke Fenn. Due 2026-11-26.
- Blocks the backport ACP2E-5598 (2.4.8-p5).

**Scout freshness:** `scout-ensure_fresh` returned:
- status=`verified`
- gap_files_reindexed=`0`
- derived_views_verified=`false`
- indexed_through=`90f60c1e8ae`

Tracked files are current. Symbol and vector graphs are not guaranteed current, so treat Scout-derived caller and census claims as provisional.

**Routing:** the named-symbol exception applied (`Newsletter::isSatisfiedBy`). Scribe and the pre-intake Dependency Tracer were dispatched in parallel and synchronously. The Tracer had a budget of 30 calls and 150 lines. Both returned to the Orchestrator.

###### Intake brief (Scribe, condensed; full text in 01-scribe.md)

**Scenario:** cart totals are calculated (`POST /rest/V1/carts/mine/totals-information`) for a newsletter-subscribed customer. An active couponless cart rule is conditioned on a segment with "Newsletter Subscription is subscribed". On a cold `customer_segments` cache, `Newsletter::isSatisfiedBy()` returns every `customer_entity` row.

**Preconditions:**
- Customer Segments enabled.
- About 3M customers.
- The customer is subscribed in a store of the segment's website.
- The segment and cart rule are active, and `salesrule_rule` has been reindexed.
- The cart is **non-empty**.
- The `customer_segments` cache has been cleaned.

**Expected:** only the given customer is examined, so cost does not depend on the size of `customer_entity`.

**Actual:** the ticket reports these times at 3M customers:

| Measurement | Time |
|---|---|
| Query as built | 754.5 ms |
| Query with `LIMIT 1` | 2.2 ms |
| Query with `root.entity_id` filter | 0.4 ms |
| Totals calculation | ~1,260 ms |
| Totals calculation, warm cache | ~465 ms |

Local reproduction returned AFF 3,000,001 rows in 346.5 ms inside a 408 ms HTTP 200 request, with SQL logging on.

**Domain:** the primary domain is Customer Management (`Magento_CustomerSegment`). It also touches:
- Merchandising & Promotions (SalesRule)
- Cart & Checkout
- Commerce Web APIs
- Performance

**repro-class:** `single-request`, deterministic and data-volume sensitive. Recommended vehicle: an integration test asserting SQL shape or row count, not wall-clock time. MFTF is not required.

###### Pre-intake mechanism (Dependency Tracer, condensed; full text in 02-dependency-tracer.md)

**Mechanism (ticket claim confirmed):**
- The outer `customer_entity AS root` select is created in the `Newsletter::isSatisfiedBy` override: `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php:187-206` (Scout range).
- The customer filter applies only to the inner `newsletter_subscriber` subquery, in the `$isFiltered` branch of `getConditionsSql`. The base `AbstractCondition::isSatisfiedBy` does not create this root select.

**Sibling census:** only Newsletter shows the uncorrelated outer-root + `fetchOne()` pattern.
- Address, Attributes and Storecredit already filter per customer.
- The bulk-scan selects are intentional: `Attributes::getCustomerSelect`, the wishlist `getSatisfiedIds`, and Newsletter's `$isFiltered=false` branch.

**Callers:** SalesRule `Validator::getRules` → AdvancedSalesRule collection plugin → `FilterTextGenerator\Segment` → `CustomerSegmentsProvider` → `Segment::validateCustomer` → Root combine → `Newsletter::isSatisfiedBy`.

**Fix-design risk:** the bulk path (`AbstractCombine::getSatisfiedIds` / `LinksMatcher`) uses `getConditionsSql(null, website, false)`. Keep the correction confined to the per-customer evaluation.

**Tests:** no direct test of `Newsletter::isSatisfiedBy()` was found. The integration test `CustomerTest::testProcessEventCustomerWithSubscription` would not detect this defect.

###### Orchestrator synthesis notes (corrections and caveats)

1. **Scribe misread the reviewer comment.** Kateryna Muntianu compared **4 customers vs 3 million**, not "4M vs 3M". The ticket table shows ~510 ms vs ~1,260 ms totals for those sizes.
2. **This is a cost defect; current results are correct.** The inner subquery already filters `main.customer_id = :customer_id`. Current code therefore returns the right true/false result for subscribed, unsubscribed and other-website customers.
   - The Tracer's suggested regression ("unsubscribed customer while another customer is subscribed") would **pass on current code**. It is an invariant guard, not a Red test.
   - The Red test must assert the examined or returned row volume, or the SQL shape. Only Scenario 1/5 of the contract can fail before the fix. Scenarios 2–4 are invariants that should pass both before and after.
3. **The Tracer's remark about outer `LIMIT 1`** ("does not correct which outer rows qualify") does not affect the boolean `fetchOne()` result. Both of the reporter's options remain open for fix design. Neither is adopted here.
4. **Citation format:** the Tracer cited short paths such as `Newsletter.php:193-204` rather than full repo-relative paths. Only the two anchors above were re-confirmed by the Orchestrator through Scout. The Tracer's other ranges are its own Scout results, with `derived_views_verified=false`, and are not independently re-verified.
5. **Not done:** formal MIG Phase 0 was not run (dry run; it writes `var/impact`). It is required after STOP 1 if approved.

###### Behavior contract (Scribe, verbatim)

```gherkin
Scenario: Defect: Checking the newsletter condition for a subscribed customer examines only that customer
  Given customer segments are enabled and a segment is active for the customer's website
  And   the segment condition is "Newsletter Subscription is subscribed"
  And   many other customers exist on the store besides the one being checked
  And   the checked customer is subscribed to the newsletter in a store of the segment's website
  When  the segment condition is evaluated for that customer
  Then  the condition is satisfied
  And   only that customer's record is examined, not the other customers' records

Scenario: Invariant: An unsubscribed customer does not satisfy the newsletter condition
  Given an active segment with the condition "Newsletter Subscription is subscribed"
  And   the customer is not subscribed to the newsletter
  When  the segment condition is evaluated for that customer
  Then  the condition is not satisfied

Scenario: Invariant: A subscription in a store outside the segment's website does not satisfy the condition
  Given an active segment with the condition "Newsletter Subscription is subscribed" for one website
  And   the customer is subscribed only in a store that belongs to a different website
  When  the segment condition is evaluated for that customer
  Then  the condition is not satisfied

Scenario: Invariant: The cart price rule discount still applies to a subscribed customer
  Given an active couponless cart price rule conditioned on a customer segment whose condition is "Newsletter Subscription is subscribed"
  And   the customer is subscribed and has a product in the cart
  When  the cart totals are calculated
  Then  the discount is applied to the cart totals

Scenario: Invariant: The amount of customer data examined does not depend on how many customers exist
  Given the same subscribed customer and segment as above
  And   the store has either few customers or very many customers
  When  the segment condition is evaluated for that customer
  Then  the same limited set of records is examined in both cases
  # OPEN: Should this be merged with the Defect scenario? It is kept separate to express scale independence. Merge it if the 5-scenario cap or redundancy is a concern.
```

###### Decisions requested from the human

1. **Approve or amend** the behavior contract above. The contract will be passed unchanged to Test Author and to Scribe at egress.
2. **Scenarios 1 and 5:** merge them, or keep both?
3. **Scope:** fix Newsletter only, as the Tracer's census suggests, or also sweep the other segment conditions?
4. **Scenario 4** (end-to-end discount): keep it as an integration invariant, or treat it as covered by existing coverage?
5. **Go-ahead** for formal MIG Phase 0. This is a real run, not a dry run.

**STOPPED at STOP 1. No further stages run (dry run).**

## 4. Usage (`usage.json` → `agentMetrics`)

| Participant | Model | Requests | Output tokens | API time | AI credits |
|---|---|---|---|---|---|
| Orchestrator (main) | `claude-opus-5.5` | 11 | 20,377 | 161.4 s | 99.12 |
| Scribe | `claude-sonnet-5.5` | 2 | 3,685 | 25.2 s | 8.00 |
| Dependency Tracer | `mai-code-2` | 12 | 7,358 | 143.6 s | 6.15 |
| **Total** | | | | | **113.27** |

## 5. Notes for the presenter

- Each Tracer run takes a different path. Transient Scout errors, such as `investigate_phase_invalid` or
  `bundle_stale`, show as `✖`, and the Tracer retries once. This is expected.
- Tool-call counts, timings and the scenario wording vary between runs. The structure does not: the preflight,
  the freshness gate, the parallel sync dispatch, both outputs going to MAIN, and the verbatim contract at STOP 1.
- If the network, Glean or Scout is unavailable, use the replay in §2.2.
