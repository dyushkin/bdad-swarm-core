# BDAD Swarm — Workshop Plan, Theses & ACP2E-5537 Dry Run

| | |
|---|---|
| **Purpose** | Facilitator plan for the internal BDAD Swarm workshop: agenda, main theses per section, and a worked dry run on a live ticket. |
| **Audience** | L4 support engineers, dev leads, anyone evaluating agentic development for Adobe Commerce tickets. |
| **Duration** | ~90 minutes (60 min content + 30 min dry run & discussion). Timings below are a suggested split. |
| **Last updated** | 2026-11-08 |
| **Companions** | `bdad-roadmap.md` (architecture + backlog), `docs/bdad-changelog.md` (history), `docs/bdad-new-ticket-runbook.md` (how to run a ticket), `docs/mig/impact-graph-proposal.md` + `docs/mig/mig-phase0-spec.md` (MIG), `docs/geth_architecture_multi_agent_swarm_synthesis.md` (philosophy), `.github/agents/*.agent.md` (the VIs) |

> **Evidence discipline for this document.** Statements are tagged where it matters:
> **[shipped]** = implemented and in the repo today · **[measured]** = from a recorded A/B run ·
> **[planned]** = roadmap item, not built · **[projection]** = estimate, not yet validated.
> Keep that discipline live in the room — the credibility of the whole approach depends on not
> overselling the parts that are still design.

---

## 0. Agenda at a glance

| # | Segment | Time | Core question answered |
|---|---|---|---|
| 1 | TDD → BDD | 8 min | Why do we start from behavior, not from code? |
| 2 | Mono → Swarm | 10 min | Why split one prompt into six specialists, and what did it buy? |
| 3 | Current workflow: Sequential Waterfall | 10 min | How does a ticket actually flow today, and where are the gates? |
| 4 | MIG & blast radius | 12 min | Why is `grep` structurally insufficient in Magento? |
| 5 | The six agents | 10 min | Who owns which decision, and where does each one stop? |
| 6 | Future: Stigmergic Blackboard | 10 min | Why move off the central Orchestrator? |
| 7 | Future: MIG Phases 1–3 | 5 min | Where does the impact graph go next? |
| 8 | **Dry run: ACP2E-5537** | 25 min | What does all of this look like on a real ticket? |
| 9 | Q&A / objections | 10 min | Where does this break, and what are we not claiming? |

**One-sentence framing for the opening slide:**
> BDAD replaces "ask an AI to fix the ticket" with a gated pipeline where *behavior is agreed first*,
> *blast radius is derived mechanically*, *a failing test exists before any patch*, and *a human approves
> at four points*.

---

## 1. The Theoretical Foundation (optional 5-min cold open)

If the audience is receptive to the "why does it look like this" question, open with the origin rather
than the mechanics. Swarm is a **specialized reflection of the Geth concept** from Mass Effect — a set of
autonomous, individually-limited units whose value appears in connection. The synthesis document
(`docs/geth_architecture_multi_agent_swarm_synthesis.md`) is the project's **philosophy**, not its
specification.

Six synthesized axioms shape the design intent:

| Axiom | Statement | What it means for Swarm |
|---|---|---|
| Statefulness & Perspective | Units carry distinct priors shaped by their history; they are not interchangeable calculators | Specialists are differentiated by role, context, and model tier — diversity is what makes agreement informative |
| Emergence through Depth | Intelligence scales via recurrent depth, not horizontal task-splitting | Adding agents is not a scaling strategy; iteration depth and evidence are |
| Federated Subconscious | Nodes share intermediate activations, not compressed text | Aspirational: today we pass prose. This is the gap the Blackboard addresses |
| Consensus by Resonance | Decisions emerge from convergence, not a central vote-counter | The current central Orchestrator is a deliberate, acknowledged deviation |
| Absolute Impartiality | Evaluation against a pure objective function | The Consensus Gateway returns a binary verdict from deterministic checks, not opinion |
| Stigmergic Collaboration | Units mutate a shared environment until equilibrium | The target architecture (§6) |

**Thesis:** *the philosophy is the compass, not the map.* Nothing in the engineering is justified by the
fiction; the fiction explains why we keep reaching for decentralization and shared state instead of a
bigger prompt.

> **Facilitator honesty note:** do not claim emergent intelligence, and do not claim the current system
> implements the axioms. Two of six (Resonance, Stigmergy) are explicitly violated by today's
> architecture — that tension is the subject of §6 and makes the talk stronger, not weaker.

---

## 2. Segment 1 — TDD, and the transition to BDD

### 2.1 TDD in one slide

The classic **Red-Green-Refactor** loop:

1. **Red** — write a test that fails for the intended reason.
2. **Green** — write the minimum code that makes it pass.
3. **Refactor** — improve structure with the test holding behavior steady.

The discipline's real payload is not "we have tests". It is **the test is written before the
implementation exists, so it cannot be shaped to agree with whatever the code happens to do.**

### 2.2 Why TDD alone is not enough with agents

Applied naively to AI coding, TDD degrades in a specific way: *an agent that owns both the test and the
patch can always reach Green.* It can weaken an assertion, narrow a fixture, or assert the buggy behavior
it just observed. Green then proves only internal consistency — not that the customer's problem is solved.

Two failure modes to name explicitly in the room:

- **Tautological Green** — the test encodes the implementation, so it can never fail for the reason
  that matters.
- **Unanchored Red** — the test fails, but from an environment problem (missing fixture, unavailable
  service), not from the defect. Accepting it as reproduction authorizes a fix for a bug nobody
  demonstrated.

### 2.3 The transition to BDD — and then to BDAD

**BDD** answers the gap by moving the contract up a level: instead of starting from a unit under test,
start from **observable behavior** expressed in stakeholder language.

```
Given  a simple product with "Maximum Qty Allowed in Shopping Cart" = 5
When   a guest adds quantity 6 via the addProductsToCart mutation
Then   user_errors returns the max-quantity message with a specific, non-UNDEFINED code
```

That scenario is *verifiable by someone who has never read the code* — which is exactly what makes it
resistant to an agent redefining success.

**BDAD = Behavior Driven Agentic Development** is BDD with the agentic additions that make it hold under
automation:

| BDD gives us | BDAD adds |
|---|---|
| Behavior stated before implementation | **Separation of authorship** — Test Author writes the test; Patch Generator writes the fix; neither may relax the other's artifact |
| Stakeholder-readable acceptance criteria | **Human approval of the criteria** before any test exists (Stop 1) |
| Executable specification | **Mechanically derived blast radius** (MIG) so "what else must still pass" is not guesswork |
| Red-Green cycle | **Independent validation** by a Consensus Gateway that only reports deterministic results |

### 2.4 Block scheme — the evolution

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  TDD                                                       unit of work: CODE │
│                                                                               │
│      ┌─────────┐      ┌──────────┐      ┌────────────┐                        │
│      │   RED   │─────▶│  GREEN   │─────▶│  REFACTOR  │                        │
│      │  test   │      │ minimal  │      │  structure │                        │
│      │  fails  │      │   code   │      │   cleanup  │                        │
│      └─────────┘      └──────────┘      └──────┬─────┘                        │
│           ▲                                    │                              │
│           └────────────────────────────────────┘                              │
│                                                                               │
│  Gives: executable specification, written before the implementation           │
│  Lacks: any statement of WHOSE behavior this is, in stakeholder language      │
└──────────────────────────────────────────────────────────────────────────────┘
                                   │
                                   │  lift the contract up one level:
                                   │  start from observable behavior
                                   ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│  BDD                                                   unit of work: BEHAVIOR │
│                                                                               │
│   ┌────────────────────┐                                                      │
│   │  GIVEN / WHEN /    │──┐                                                   │
│   │  THEN  scenario    │  │  binds to                                         │
│   │  (stakeholder      │  │                                                   │
│   │   language)        │  │     ┌─────────┐   ┌─────────┐   ┌──────────┐      │
│   └────────────────────┘  └────▶│   RED   │──▶│  GREEN  │──▶│ REFACTOR │      │
│                                 └─────────┘   └─────────┘   └────┬─────┘      │
│                                      ▲                           │            │
│                                      └───────────────────────────┘            │
│                                                                               │
│  Gives: success defined in terms a non-coder can verify                       │
│  Lacks: protection when ONE actor owns both the scenario and the patch        │
└──────────────────────────────────────────────────────────────────────────────┘
                                   │
                                   │  the agentic problem:
                                   │  an agent owning test + patch
                                   │  can always reach Green
                                   ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│  BDAD                                        unit of work: BEHAVIOR + EVIDENCE │
│                                                                               │
│   ┌──────────────┐   ✋STOP 1   ┌──────────────┐                               │
│   │ BEHAVIOR     │────────────▶│  BLAST       │   MIG: mechanically derived   │
│   │ CONTRACT     │   human     │  RADIUS      │   "what else must stay green" │
│   │ (Scribe)     │   approves  │  (Tracer)    │                               │
│   └──────────────┘             └──────┬───────┘                               │
│                                       ▼                                       │
│        ┌──────────────────────────────────────────────┐                       │
│        │  SEPARATION OF AUTHORSHIP                    │                       │
│        │                                              │                       │
│        │   ┌────────────┐  ✋STOP 2   ┌─────────────┐  │  ⟲ Loop-back A        │
│        │   │ TEST       │───────────▶│ PATCH       │  │   (inner TDD cycle)   │
│        │   │ AUTHOR     │◀───────────│ GENERATOR   │  │                       │
│        │   │  owns RED  │   may NOT  │  owns GREEN │  │                       │
│        │   └────────────┘   relax the└─────────────┘  │                       │
│        │                    test                      │                       │
│        └──────────────────────────┬───────────────────┘                       │
│                                   ▼  ✋STOP 3                                  │
│                        ┌─────────────────────┐                                │
│                        │ CONSENSUS GATEWAY   │  deterministic, binary         │
│                        │  green / not-green  │  ⟲ Loop-back B on NOT-GREEN    │
│                        └──────────┬──────────┘                                │
│                                   ▼  ✋STOP 4                                  │
│                        ┌─────────────────────┐                                │
│                        │ EGRESS (Scribe)     │                                │
│                        └─────────────────────┘                                │
│                                                                               │
│  Adds: separated authorship · derived blast radius · independent validation   │
│        · four human approval gates                                            │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Read the three boxes as answers to one escalating question — *what stops success from being
self-declared?***  TDD answers "a test written first." BDD answers "a scenario a stakeholder can check."
BDAD answers "a different agent, with deterministic evidence and a human gate."

**Thesis for the segment:**
> TDD makes behavior executable. BDD makes it *agreed and observable*. BDAD makes it *survive delegation
> to agents* by separating who defines success, who implements it, and who certifies it.

The Red-Green-Refactor engine is unchanged and still mandatory **[shipped]** — BDAD is a framing layer
above it, not a replacement.

---

## 3. Segment 2 — How mono was created, how it split, and what the split bought

### 3.1 The mono era

The first working setup was a single flat `CLAUDE.md`: **139 lines, every rule inline**, one session, one
context, one agent doing intake, tracing, testing, patching and validation. It worked — it shipped fixes.
Its problems were structural, not cosmetic:

- **No separation of authorship.** The same context that wrote the test wrote the patch.
- **One undifferentiated context.** Ticket prose, stack traces, diffs and validation output all competed
  in the same window; **98% of usage ran above 150k context in one unbroken session** **[measured]**.
- **Uniform context weight.** Mechanical validation (running `php -l`, PHPCS) was carried out in the same
  large, premium-tier context as root-cause archaeology, re-reading history it had no use for.
- **No enforceable stop points.** Rules existed as prose the single agent could gloss over.

### 3.2 The split

The monolith was decomposed into **six specialist Virtual Intelligences**, each a
`.github/agents/*.agent.md` profile that is the *authoritative, complete* source for its domain
**[shipped]**. `../.claude/CLAUDE.md` became a thin router — a Swarm table plus always-in-force
non-negotiables. A dispatched subagent loads **only its own profile plus the router**, not its siblings'.

```
          BEFORE — THE MONOLITH                      AFTER — THE SWARM
┌──────────────────────────────────┐    ┌──────────────────────────────────────────┐
│      .claude/CLAUDE.md           │    │   .claude/CLAUDE.md  (thin router)       │
│      139 lines, all rules inline │    │   Swarm table + non-negotiables only     │
│                                  │    └───────────────────┬──────────────────────┘
│   ┌──────────────────────────┐   │                        │ dispatches
│   │  ONE AGENT               │   │                        │ (subagent loads its own
│   │  ONE CONTEXT WINDOW      │   │                        │  profile + router ONLY)
│   │                          │   │        ┌───────────────┼───────────────┐
│   │   • intake               │   │        ▼               ▼               ▼
│   │   • tracing              │   │  ┌───────────┐  ┌────────────┐  ┌────────────┐
│   │   • test authoring       │   │  │ORCHESTRAT.│  │  SCRIBE    │  │ DEPENDENCY │
│   │   • patching             │   │  │ .agent.md │  │ .agent.md  │  │  TRACER    │
│   │   • validation           │   │  │  Sonnet   │  │  Sonnet    │  │ .agent.md  │
│   │   • egress               │   │  │           │  │            │  │   Opus     │
│   │                          │   │  │ workflow  │  │ intake +   │  │ blast      │
│   │  ▸ same context writes   │   │  │ state,    │  │ egress,    │  │ radius,    │
│   │    the test AND the fix  │   │  │ env/exec, │  │ brief,     │  │ MIG, root  │
│   │  ▸ 98% of usage >150k    │   │  │ gating    │  │ summaries  │  │ cause      │
│   │  ▸ 97.4M cache reads     │   │  └───────────┘  └────────────┘  └────────────┘
│   │  ▸ mechanical work pays  │   │        ┌───────────────┬───────────────┐
│   │    premium context rate  │   │        ▼               ▼               ▼
│   │  ▸ stop points = prose   │   │  ┌───────────┐  ┌────────────┐  ┌────────────┐
│   │    it could gloss over   │   │  │   TEST    │  │   PATCH    │  │ CONSENSUS  │
│   └──────────────────────────┘   │  │  AUTHOR   │  │ GENERATOR  │  │  GATEWAY   │
│                                  │  │ .agent.md │  │ .agent.md  │  │ .agent.md  │
│                                  │  │   Opus    │  │   Opus     │  │   Haiku    │
│                                  │  │           │  │            │  │            │
│                                  │  │ owns RED, │  │ owns GREEN │  │ php -l,    │
│                                  │  │ MFTF,     │  │ PSR-12, DI,│  │ PHPCS,     │
│                                  │  │ triage    │  │ Cloud, i18n│  │ static,MFTF│
│                                  │  └───────────┘  └────────────┘  └────────────┘
│                                  │        ▲               ▲
│                                  │        └───────────────┘
│                                  │     ✦ DIFFERENT CONTEXTS — the agent that
│                                  │       defines success is not the one that
│                                  │       achieves it
└──────────────────────────────────┘

   one context, six jobs            →    six contexts, one job each
   43.1M cache reads · 75% >150k    ←    (−56% cache reads vs mono)
```

**What the right-hand side buys, stated precisely:** not intelligence — **bounded authority** (each box
has one owner and one stop condition), **context partitioning** (54% of usage moved into subagent
windows), and **model tiering** (mechanical validation runs on Haiku, not Opus).

### 3.3 The measured gain — the ACP2E-5288 A/B

Both configurations ran the *identical* ticket from the *identical* starting commit, in isolated
worktrees and Docker stacks, with the configuration as the only independent variable **[measured]**:

| | mono | swarm |
|---|---|---|
| Cache-read tokens | **97.4M** | **43.1M (−56%)** |
| API time | 1h 35m 07s | 1h 33m 53s (~tied) |
| Context profile | 98% of usage >150k, one session | 75% >150k; 54% of usage partitioned into subagents |
| Root cause | ✅ found | ✅ same root cause, independently |
| Fix correctness | ✅ green | ✅ green |
| Test layers shipped | MFTF + unit | MFTF + unit + **integration** |
| Destructive incident | ⚠️ **yes** — ad-hoc DB mutation broke the instance | none observed |
| Test reuse discipline | reinvented inline MFTF steps | reused existing action groups + one new documented reusable group |

**Read the result honestly, because the honest read is more persuasive:**

- **Correctness was a tie.** Both found the same root cause in `SaveAction::execute()` and shipped
  near-identical guards. *Specialization did not make the model smarter.*
- **The gain was in token economics and process:** a **54.3M-token (−56%) drop in cache reads**, a
  materially larger safety net, better reuse discipline, and — the one that matters most operationally —
  **mono committed a destructive ad-hoc DB mutation that broke its instance; swarm did not.**
- **The mechanism is prompt-cache economics:** token consumption scales as *(context size × turns)* at the
  cache-read rate. A monolithic session grows one context and re-reads all of it every turn; routing
  structurally shrinks the *context-size* factor for most turns by pushing work into subagents with their
  own smaller windows.
- **Note the dilution:** cache reads fell 56% but overall consumption fell less, because swarm spent *more*
  fresh input seeding subagent contexts. The main session still ran 75% of usage above 150k — which is why
  `R-COST` and `R-DEDUP` remain in the active backlog.

**Thesis for the segment:**
> Splitting the monolith did not buy intelligence. It bought **bounded authority, context partitioning,
> a bigger safety net, and a blast radius for mistakes** — and it did so without sacrificing correctness
> or wall-clock time.

---

## 4. Segment 3 — Current workflow: Sequential Waterfall with a centralized Orchestrator

### 4.1 The canonical chain **[shipped]**

```
 ╔═══════════════════════════════════════════════════════════════════════════════╗
 ║  STEP 0 · PREREQUISITES                                                       ║
 ║  branch off 2.4-develop  ·  fresh session  ·  ticket.md in place              ║
 ╚═══════════════════════════════╤═══════════════════════════════════════════════╝
                                 ▼
 ┌───────────────────────────────────────────────────────────────────────────────┐
 │  STEP 1 · SCRIBE                                            [intake]          │
 │  ─────────────────────────────────────────────────────────────────────────    │
 │  IN   ticket.md (+ Glean ticket evidence)                                     │
 │  OUT  structured brief: Scenario / Preconditions / Steps / Expected vs Actual │
 │       + domain classification  + repro-class flag                             │
 └───────────────────────────────┬───────────────────────────────────────────────┘
                                 ▼
                    ╭────────────────────────────╮
                    │  ✋ STOP 1 — confirm brief  │   human approves SCOPE
                    │     scope + acceptance     │   before any test exists
                    ╰────────────┬───────────────╯
                                 ▼
 ┌───────────────────────────────────────────────────────────────────────────────┐
 │  STEP 2 · DEPENDENCY-TRACER                                 [blast radius]    │
 │  ─────────────────────────────────────────────────────────────────────────    │
 │  IN   approved brief                                                          │
 │  OUT  Phase 0 Blast-Radius Report (MIG)  + domain-adjacency fan-out           │
 │       plugins · observers · preferences · MFTF groups · stale mocks           │
 └───────────────────────────────┬───────────────────────────────────────────────┘
                                 ▼
 ┌───────────────────────────────────────────────────────────────────────────────┐
 │  STEP 3 · TEST-AUTHOR                                       [RED]             │◀─┐
 │  ─────────────────────────────────────────────────────────────────────────    │  │
 │  IN   brief + blast radius                                                    │  │
 │  OUT  FAILING test, executed with --filter                                    │  │
 │       ▸ must fail for the INTENDED reason, not an env error                   │  │
 └───────────────────────────────┬───────────────────────────────────────────────┘  │
                                 ▼                                                  │
                    ╭────────────────────────────╮                                  │
                    │  ✋ STOP 2 — Red verified   │                                  │
                    ╰────────────┬───────────────╯                                  │
                                 ▼                                                  │
 ┌───────────────────────────────────────────────────────────────────────────────┐  │
 │  STEP 4 · PATCH-GENERATOR                                   [GREEN]           │  │
 │  ─────────────────────────────────────────────────────────────────────────    │  │
 │  IN   failing test + blast radius                                             │──┘
 │  OUT  implementation until Green                                              │ ⟲ Loop-back A
 │       ▸ may NOT edit the acceptance test to get there                         │  (inner TDD:
 │       ▸ PSR-12 · strict typing · DI · Cloud-compat · i18n                     │   STEP 3 ⇄ 4)
 └───────────────────────────────┬───────────────────────────────────────────────┘
                                 ▼
                    ╭────────────────────────────╮
                    │  ✋ STOP 3 — Green verified │
                    ╰────────────┬───────────────╯
                                 ▼
 ┌───────────────────────────────────────────────────────────────────────────────┐
 │  STEP 5 · CONSENSUS-GATEWAY                                 [validate]        │
 │  ─────────────────────────────────────────────────────────────────────────    │
 │  php -l  ·  PHPCS  ·  static suite  ·  MFTF generation                        │
 │  cross-domain verification gate  ·  mock-surface sync                         │
 │                                                                               │
 │          ┌─────────────────┐              ┌─────────────────┐                 │
 │          │   NOT-GREEN     │              │     GREEN       │                 │
 │          └────────┬────────┘              └────────┬────────┘                 │
 └───────────────────┼────────────────────────────────┼──────────────────────────┘
                     │                                │
      ⟲ Loop-back B  │  rework — NOT a stop point.    │
      back to STEP 3 │  every iteration = a           │
      or STEP 4 ◀────┘  specialist miss (quality      │
                        signal, tracked)              ▼
 ┌───────────────────────────────────────────────────────────────────────────────┐
 │  STEP 6 · SCRIBE                                            [egress]          │
 │  ─────────────────────────────────────────────────────────────────────────    │
 │  OUT  Manual Steps to Reproduce · dev summary · release notes · retrospective │
 └───────────────────────────────┬───────────────────────────────────────────────┘
                                 ▼
                    ╭────────────────────────────╮
                    │  ✋ STOP 4 — green + docs   │
                    ╰────────────────────────────╯
```

**How to read it in the room:** the **solid downward spine is the waterfall** — strictly sequential, one
specialist at a time. The **two loop-backs are the only cycles**, and they mean different things:
**A** is healthy design iteration; **B** is a defect in the pipeline itself. The **four `✋` diamonds are
the only places the workflow can advance past a decision**, and each requires explicit human go-ahead.

**Four mandatory human stop points** — halt, surface, wait for explicit go-ahead. **Two loop-backs:**
**A** is the healthy inner Red⇄Green design cycle; **B** is gate rejection, where *every iteration is a
specialist miss* and its frequency is a deliberate quality signal.

**Two operating modes:** *top-level-conducted* (recommended — the human conducts) and *hands-off*
(delegate the whole ticket to the Orchestrator VI).

### 4.2 How the Orchestrator actually works today

It is a **Hub-and-Spoke router**: it receives one agent's output, holds it in its own context window, and
pastes it into the next agent's prompt. It owns workflow state, environment and execution commands,
Red-Green-Refactor sequencing, stop-point gating, and usage logging.

### 4.3 The three inherent flaws — name them, don't hide them

1. **Token overhead.** Because the Orchestrator accumulates the entire ticket history to pass it along,
   its context swells monotonically. Re-reading that accumulated state every turn is the measured driver
   behind the swarm's remaining cache-read volume — 43.1M tokens, with 75% of usage still above 150k
   context **[measured]**.
2. **Context serialization loss.** Passing structural codebase state as conversational prose is a game of
   telephone; structural nuance degrades at each hop.
3. **Philosophical contradiction.** A central routing manager violates the Resonance and Stigmergy axioms.
   It is a hierarchical pipeline wearing a swarm's name.

**Thesis for the segment:**
> Today's architecture is a *waterfall with gates* — deliberately chosen for auditability and human
> control while the method was being proven. Its token economics and its philosophy point at the same exit:
> remove the hub.

---

## 5. Segment 4 — MIG and blast radius, instead of blind grep

### 5.1 The motivating failure

This is the strongest segment of the workshop because it is grounded in a real regression. On
**ACP2E-5180**, the agent edited `Order\Item::getQtyToCancel()` and validated only what it could
*see by grep*. The dependency that broke was indirect:

```
Order\Item::getQtyToCancel()
   ├─(di.xml plugin)──▶ Bundle\...\Plugin\Item::afterGetQtyToCancel     ← grep-invisible
   └─(caller)─────────▶ Order::registerCancellation()
            └─(events.xml dispatch 'sales_order_item_cancel')
                     └─▶ InventorySales\...\CancelOrderItemObserver      ← grep-invisible
                              └─ module Magento_InventorySales
                                   └─ MFTF @group msi
                                        └─ AdminCancelOrderWith…AfterPartialShipmentTest  ← broken
```

`grep getQtyToCancel` never surfaces the observer or the MFTF test. A second regression was a unit test
whose strict mock didn't cover a newly-added collaborator (`priceCurrency->round()`).

### 5.2 Why this is structural, not carelessness

Magento's wiring is **declarative and indirect by design**. Plugins, preferences, virtual types and
observers connect classes through XML, not through call sites. Text search operates on identifiers;
the edges that break production are in `di.xml` and `events.xml`. **No amount of diligence makes grep
sound here** — the information is simply not in the text being searched.

### 5.3 What MIG does **[shipped — Phase 0]**

MIG (`dev/tools/impact-graph/`) is an **analytical, on-demand** tracer — deliberately *not* a persistent
whole-repo graph. Given a `git diff` or a symbol, it emits a Blast-Radius Report by reading the sources of
truth directly:

| Edge | Source of truth | Resolves |
|---|---|---|
| `INTERCEPTS` | `di.xml` `<plugin>` (438 sources) | plugin interception, incl. `before*/after*/around*` → subject method |
| `PREFERS` | `di.xml` `<preference>`, `<virtualType>` | interface → concrete resolution |
| `OBSERVES` | `events.xml` `<observer>` (121 sources) | event → observer class |
| `DISPATCHES` | AST scan of `eventManager->dispatch('x')` | symbol → event name |
| `MOCKS` | AST: `createMock` / `getMockBuilder` + `->method()` | **stale unit mocks** when a collaborator set changes |
| Test / group / module | MFTF index (2769 tests) + `ComponentRegistrar` | symlink-safe module attribution for EE/B2B/MSI |

Invocation: `php dev/tools/impact-graph/bin/impact-graph analyze --diff <git-ref> --format=json`
(verified 2026-11-08: `--diff` requires an explicit git-ref; omitting it yields an empty changed-set.
`--help` lists the other modes: `--event`, `--plugins-subject`, `--preference`, `--dispatches`,
`--mocks`, `--mock-impact`, `--mftf-group`, `--mftf-module`.)

Two design decisions worth calling out:

- **Recall-first, not minimal.** For `@api` / shared-model changes it deliberately over-includes. A false
  positive costs test minutes; a false negative ships a regression.
- **Definition of Done was a replay of a real failure** — run against the ACP2E-5180 diff it must return
  *both* the MSI acceptance test *and* flag the stale mock, before a line of fix is written.

### 5.4 Worked examples — run these live

All outputs below were executed against this branch on **2026-11-08** and are reproduced verbatim
(paths shortened). Running two or three of these live is more convincing than any slide; examples 1 and 2
are the recommended pair, because together they *are* the ACP2E-5180 regression.

#### Example 1 — `--event`: the observer grep could not find

```bash
php dev/tools/impact-graph/bin/impact-graph analyze --event sales_order_item_cancel
```
```
event: sales_order_item_cancel
observers:
  - observer=Magento\CatalogInventory\Observer\CancelOrderItemObserver       module=Magento_CatalogInventory
  - observer=Magento\InventorySales\Observer\CatalogInventory\CancelOrderItemObserver  module=Magento_InventorySales
```

**The point:** two observers, in two different modules, one of them in the MSI extension tree under
`ext/magento/inventory/`. Neither mentions `getQtyToCancel` anywhere in its text. A developer editing the
cancellation path and grepping for the method name sees **neither**.

#### Example 2 — `--plugins-method`: the interception grep could not find

```bash
php dev/tools/impact-graph/bin/impact-graph analyze --plugins-method getQtyToCancel
```
```
method: getQtyToCancel
plugins:
  - subject=Magento\Sales\Model\Order\Item
    plugin=Magento\Bundle\Model\Sales\Order\Plugin\Item
    methods=getQtyToCancel; isProcessingAvailable   module=Magento_Bundle
```

**The point:** this is the exact edge that broke ACP2E-5180, recovered in one deterministic query. The
connection lives in `Magento_Bundle`'s `di.xml`; the plugin method is `afterGetQtyToCancel`, so even
grepping the *method name* across the repo would not surface it by the name being changed.

> **Suggested live beat:** before running these, ask the room to guess how many modules are affected by a
> change to `getQtyToCancel`. The usual answer is "Sales". The real answer is Sales + Bundle +
> CatalogInventory + InventorySales — and three of those four are invisible to text search.

#### Example 3 — `--preference`: what does this interface actually resolve to?

```bash
php dev/tools/impact-graph/bin/impact-graph analyze --preference 'Magento\Quote\Api\CartRepositoryInterface'
```
```
for: Magento\Quote\Api\CartRepositoryInterface
concretes:
  - type=Magento\Quote\Model\QuoteRepository   module=Magento_Quote
```

**The point:** the everyday question "I'm changing this interface — whose code actually runs?" answered
from `di.xml` rather than by assuming the naming convention holds. In EE/B2B trees it frequently does not.

#### Example 4 — `--dispatches`: the outbound blast radius of one class

```bash
php dev/tools/impact-graph/bin/impact-graph analyze --dispatches app/code/Magento/Sales/Model/Order.php
```
```
dispatches:
  - event=sales_order_place_before  method=place    observers=
  - event=sales_order_place_after   method=place    observers=Magento\GiftRegistry\Observer\OrderPlaced;
      Magento\Sales\Observer\Frontend\AddVatRequestParamsOrderComment;
      Magento\NewRelicReporting\Model\Observer\ReportOrderPlaced;
      Magento\NewRelicReporting\Model\Observer\ReportOrderPlacedToNewRelic;
      Magento\LoginAsCustomerLogging\Observer\LogPlaceOrderObserver
  - event=order_cancel_after        method=cancel   observers=Magento\GiftCardAccount\Observer\SalesOrderCancelAfter;
      Magento\Reward\Observer\ReturnRewardPoints;
      Magento\CustomerBalance\Observer\RevertStoreCreditObserver
```

**The point:** two hops in one command — AST-scanned `dispatch()` calls, each already resolved to its
observers. Touching `Order::cancel()` reaches GiftCardAccount, Reward and CustomerBalance. Note also that
`sales_order_place_before` has **zero** observers: MIG distinguishes "no listeners" from "unknown", which
is what lets it *narrow* scope with confidence, not only widen it.

#### Example 5 — `--mocks`: the stale-mock surface

```bash
php dev/tools/impact-graph/bin/impact-graph analyze --mocks \
  app/code/Magento/Quote/Test/Unit/Model/Cart/AddProductsToCartErrorTest.php
```
```
mocks:
  - receiver=$renderer  class=RendererInterface
stubs:
  - receiver=$renderer  methods=render
```

**The point:** this is the ACP2E-5537 unit test from §9. Its entire mocked surface is one collaborator
stubbing one method. If a fix introduced a new dependency, this test would fail on an unstubbed call —
and `--mock-impact <git-ref>` flags that class of break *before* the suite is ever run.

#### Example 6 — `--diff`: the Phase 0 entry point the Dependency Tracer actually calls

```bash
php dev/tools/impact-graph/bin/impact-graph analyze --diff <git-ref> --format=json
```
```json
{
  "diff": "HEAD~1",
  "changed": [
    {
      "file": "app/code/Magento/Sales/ViewModel/Order/CanceledItemQty.php",
      "class": "Magento\\Sales\\ViewModel\\Order\\CanceledItemQty",
      "module": "Magento_Sales",
      "plugins": [], "dispatches": [], "observes": []
    }
  ]
}
```

**The point:** this is STEP 2 in machine-readable form — every changed class with its plugin,
dispatch and observer edges attached, ready to be written into a brief (and, later, onto the Blackboard).
**Mind the footgun you can demo honestly:** `--diff` *requires* an explicit git-ref. Omitting it returns
`{"diff": "", "changed": []}` — an empty result that reads exactly like "nothing is affected".

#### Example 7 — `--mftf-group`: from module to acceptance tests

```bash
php dev/tools/impact-graph/bin/impact-graph analyze --mftf-group msi
```
```
tests:
  - test=AdminCanSetOnlyXLeftThresholdForVirtualProductWithDefaultSourceTest
    groups=msi; multi_mode   module=Magento_Inventory
    file=ext/magento/inventory/Inventory/Test/Mftf/Test/…
  - … (full msi group)
```

**The point:** this closes the chain. Example 1 found the `InventorySales` observer; this converts that
module into the concrete MFTF tests that must stay green — the final link that was missing on ACP2E-5180.
Note the `file=` path resolves through `ext/magento/inventory/`, which is why symlink-safe module
attribution is a listed requirement and not an implementation detail.

**Thesis for the segment:**
> MIG converts "which tests should I run?" from an act of judgment into a **deterministic query against
> the wiring**. It is the single highest-leverage component, because it removes a class of error that
> neither a human nor a model can reliably avoid by reading harder.

---

## 6. Segment 5 — The agents

Each VI is a profile file, model-tiered by the kind of thinking it does: reasoning-heavy on Opus,
coordination/prose on Sonnet, mechanical validation on Haiku **[shipped]**.

| VI | Model | Owns | Stops when |
|---|---|---|---|
| **Orchestrator** | Sonnet | Router + workflow state; env/exec commands, `setup.sh`, Red-Green sequencing, stop-point gating, usage logging, verdict spot-check | A stop point is reached, or a prerequisite is missing |
| **Scribe** | Sonnet | Intake (structured brief, domain classification, concurrency flag) and egress (Manual Steps, dev summary, release notes, postmortem) | The brief has unresolved ambiguity — it surfaces, it does not invent acceptance criteria |
| **Dependency Tracer** | Opus | Phase 0 blast radius (MIG), root-cause archaeology, mock-surface sync, symlink-aware resolution, domain-map owner | The impacted surface is established — it does not design the fix |
| **Test Author** | Opus | Red-first tests, repro-vehicle selection, MFTF authoring + failure triage, invariant-first assertions | Red is demonstrated *for the intended reason* |
| **Patch Generator** | Opus | Green implementation under PSR-12 / strict typing / DI / Cloud-compat / i18n | Green is reached **without** modifying the acceptance test to get there |
| **Consensus Gateway** | Haiku | Deterministic validation: `php -l`, PHPCS, static suite, MFTF generation, cross-domain gate, mock-sync | It emits a **binary** green / not-green verdict — it never negotiates |

**Block scheme — the Orchestrator as master agent (Hub-and-Spoke) [shipped]:**

```
                        ┌───────────────────────────────┐
                        │          THE HUMAN            │
                        │  conducts · approves ✋ gates  │
                        └───────────────┬───────────────┘
                                        │ ticket + go-aheads
                                        ▼
        ╔═══════════════════════════════════════════════════════════════╗
        ║                    ORCHESTRATOR  (Sonnet)                     ║
        ║                       ✦ MASTER AGENT ✦                        ║
        ║                                                               ║
        ║   HOLDS:  workflow state · accumulated ticket history         ║
        ║   OWNS :  env & exec commands · setup.sh · Docker stack       ║
        ║           Red-Green sequencing · ✋stop-point gating           ║
        ║           usage logging · verdict spot-check                  ║
        ║                                                               ║
        ║   ┌─────────────────────────────────────────────────────┐     ║
        ║   │  CONTEXT WINDOW  — grows monotonically              │     ║
        ║   │  brief → MIG report → stack traces → diffs → verdict│     ║
        ║   │  re-read every turn  ▸ 75% of usage >150k           │     ║
        ║   └─────────────────────────────────────────────────────┘     ║
        ╚═══╤═══════════╤═══════════╤═══════════╤═══════════╤═══════════╝
            │           │           │           │           │
    dispatch│   dispatch│   dispatch│   dispatch│   dispatch│
    + paste │   + paste │   + paste │   + paste │   + paste │
    prior   │   prior   │   prior   │   prior   │   prior   │
    output  │   output  │   output  │   output  │   output  │
            ▼           ▼           ▼           ▼           ▼
     ┌───────────┐┌───────────┐┌──────────┐┌──────────┐┌───────────┐
     │  SCRIBE   ││DEPENDENCY ││   TEST   ││  PATCH   ││ CONSENSUS │
     │           ││  TRACER   ││  AUTHOR  ││GENERATOR ││  GATEWAY  │
     │  Sonnet   ││   Opus    ││   Opus   ││   Opus   ││   Haiku   │
     ├───────────┤├───────────┤├──────────┤├──────────┤├───────────┤
     │ intake    ││ blast     ││  RED     ││  GREEN   ││ php -l    │
     │ brief     ││ radius    ││  test    ││  patch   ││ PHPCS     │
     │ egress    ││ MIG       ││  MFTF    ││ PSR-12   ││ static    │
     │ docs      ││ root cause││  triage  ││ DI/Cloud ││ MFTF gen  │
     └─────┬─────┘└─────┬─────┘└────┬─────┘└────┬─────┘└─────┬─────┘
           │            │           │           │            │
           └────────────┴───────────┴───────────┴────────────┘
                                  │
                      results return to the HUB only
                      ✦ spokes never talk to each other ✦

     ┌─────────────────────────────────────────────────────────────────┐
     │  CONSEQUENCES OF THE HUB                                        │
     │  ✓ auditable: one place owns state, gating and sequencing       │
     │  ✗ every artifact is re-serialized as prose through the hub     │
     │  ✗ hub context grows with the ticket → cache-read volume        │
     │  ✗ violates Resonance + Stigmergy axioms (see §7)               │
     └─────────────────────────────────────────────────────────────────┘
```

**The load-bearing observation for §7:** the spokes are **isolated by construction** — the Test Author
never sees the Dependency Tracer's output except as prose the hub pasted in. That isolation is exactly
what gives us separated authorship, *and* exactly what forces every byte of shared state through one
growing context. The Blackboard keeps the first property and discards the second.

**The two rules that make specialization real** (and worth stating slowly):

1. **The Test Author and the Patch Generator are different agents with different contexts.** The one who
   defines success is not the one who achieves it.
2. **The Consensus Gateway reports deterministic results, not opinions.** Its verdict cannot be argued
   with — a NOT-GREEN sends work back through Loop-back B.

**Thesis for the segment:**
> Specialization here is not personality or prompt flavor. It is **a bounded decision, a required input,
> a concrete output artifact, and an explicit stop condition** per role. That is what makes the roles
> auditable — and what makes a missed defect attributable to a specific specialist.

---

## 7. Segment 6 — Future: from Sequential Waterfall to a Stigmergic Blackboard **[planned]**

### 7.1 The concept

A **Blackboard** is a shared, persistent workspace state on disk — e.g. `var/bdad/<TICKET>/state.json` —
with dedicated sections for the intake brief, the MIG blast-radius JSON, the failing test path, the
proposed diff, and the validation verdict.

Agents stop being *called in sequence* and become **self-triggering specialists that observe and mutate
shared state**:

- The Orchestrator's role shrinks to executing shell commands and synchronizing the Blackboard file.
- The Patch Generator writes a draft diff; the **Dependency Tracer independently notices** and appends a
  new plugin violation to the board.
- The Test Author sees that violation on the board and mutates the assertion accordingly.

**Block scheme — the Stigmergic Blackboard [planned]:**

```
                        ┌───────────────────────────────┐
                        │          THE HUMAN            │
                        │  approves ✋ gate transitions  │
                        └───────────────┬───────────────┘
                                        │ writes approval records
                                        ▼
   ╔═══════════════════════════════════════════════════════════════════════════╗
   ║            THE BLACKBOARD   var/bdad/<TICKET>/state.json                  ║
   ║                  ✦ shared environment — the ONLY channel ✦                ║
   ╠═══════════════════════════════════════════════════════════════════════════╣
   ║  brief          │ scenario · acceptance criteria · domain · repro-class   ║
   ║  blast_radius   │ MIG JSON: plugins · observers · MFTF groups · mocks     ║
   ║  red_test       │ path · filter · last run · fails-for-intended-reason    ║
   ║  diff           │ proposed patch (draft → candidate)                      ║
   ║  violations[]   │ appended by ANY node; each must be resolved or waived   ║
   ║  verdict        │ php -l · PHPCS · static · MFTF · cross-domain           ║
   ║  gates          │ stop_1..stop_4 : pending | approved(by,when)            ║
   ╚═══════════════════════════════════════════════════════════════════════════╝
         ▲    │          ▲    │          ▲    │          ▲    │          ▲    │
         │    │          │    │          │    │          │    │          │    │
    read │    │ write    │    │          │    │          │    │          │    │
   (poll)│    ▼ (mutate) │    ▼          │    ▼          │    ▼          │    ▼
   ┌──────────────┐ ┌──────────────┐ ┌──────────┐ ┌───────────┐ ┌──────────────┐
   │   SCRIBE     │ │ DEPENDENCY   │ │   TEST   │ │   PATCH   │ │  CONSENSUS   │
   │              │ │   TRACER     │ │  AUTHOR  │ │ GENERATOR │ │   GATEWAY    │
   ├──────────────┤ ├──────────────┤ ├──────────┤ ├───────────┤ ├──────────────┤
   │ trigger:     │ │ trigger:     │ │ trigger: │ │ trigger:  │ │ trigger:     │
   │  no brief /  │ │  brief new   │ │ radius   │ │ red_test  │ │ diff changed │
   │  verdict=    │ │  OR diff     │ │ ready OR │ │ ready AND │ │ AND no open  │
   │  green       │ │  changed     │ │ violation│ │ gate2 ok  │ │ violations   │
   ├──────────────┤ ├──────────────┤ ├──────────┤ ├───────────┤ ├──────────────┤
   │ writes:      │ │ writes:      │ │ writes:  │ │ writes:   │ │ writes:      │
   │  brief       │ │  blast_radius│ │ red_test │ │  diff     │ │  verdict     │
   │  (egress     │ │  violations[]│ │violations│ │           │ │  violations[]│
   │   docs)      │ │              │ │          │ │           │ │              │
   └──────────────┘ └──────────────┘ └──────────┘ └───────────┘ └──────────────┘

     ✦ no agent-to-agent edges exist in this picture by design —
       every arrow above terminates at the board, and a node learns what
       another node did ONLY by re-reading the board (stigmergy)

   ┌───────────────────────────────────────────────────────────────────────────┐
   │  ORCHESTRATOR  (demoted: executor, not router)                            │
   │  runs shell commands · syncs the board file · NO ticket history in context │
   │  target: <25k tokens for the entire lifecycle            [projection]      │
   └───────────────────────────────────────────────────────────────────────────┘

   ─────────────────────────────────────────────────────────────────────────────
   TERMINATION = EQUILIBRIUM, not a manager's decision:
        diff is non-empty  ∧  red_test now passes  ∧  verdict = green
        ∧  violations[] is empty  ∧  no node's trigger condition fires
   ─────────────────────────────────────────────────────────────────────────────
```

**Contrast it directly with the hub diagram in §6 — same five specialists, one structural change:**

| | Hub-and-Spoke (today) | Blackboard (planned) |
|---|---|---|
| Channel | prose pasted by the Orchestrator | structured file on disk |
| Trigger | the hub calls you | you observe a condition and act |
| Orchestrator context | grows with the ticket (75% >150k) | flat — executor only (<25k, projected) |
| Order | fixed sequence | emergent from trigger conditions |
| Done | hub declares it | state reaches equilibrium |
| Separated authorship | ✓ (spoke isolation) | ✓ (section ownership) |

**The critical read for the room:** the **violations[] array is what replaces the manager.** Any node may
append; no patch is done while one is open. That is Consensus by Resonance expressed as a data
structure — agreement is the *absence of an outstanding objection*, not a vote tallied by a hub.

### 7.2 Why it is beneficial

| Benefit | Mechanism |
|---|---|
| **Context collapse** | The Orchestrator no longer carries ticket history in conversational memory — it reads and writes a file. Target: **<25k tokens for the entire lifecycle** instead of a context that grows monotonically to >150k **[projection]**. Because consumption scales as *(context size × turns)*, collapsing the largest context should cut cache-read volume well below the current 43.1M **[projection — not yet validated]** |
| **Iterative convergence** | Rapid micro-loops of generation and critique replace rigid "Phase 1, then Phase 2" |
| **Evidence-based acceptance** | A patch advances because the shared state reaches equilibrium — code compiles, tests pass, no critic node can append a violation — not because a manager said so |
| **Philosophical alignment** | Restores the Resonance and Stigmergy axioms the hub currently violates |

### 7.3 Transition plan

1. Define the schema for `blackboard.json` / `blackboard.md`.
2. Strip routing instructions out of `orchestrator.agent.md`; rewrite it as the filesystem/terminal
   executor that synchronizes the Blackboard.
3. Update `patch-generator`, `test-author` and `dependency-tracer` to read inputs **exclusively from the
   Blackboard** via shell commands, not from conversational prompts.

### 7.4 Open risks to raise in the room (do not skip these)

The Blackboard replaces a sequencing problem with a **concurrency** problem. Three questions have no
answer yet, and saying so is better than being asked:

- **Ownership & write conflicts** — what prevents two agents mutating the same section, and what happens
  to a result published by an agent working from a stale read?
- **Termination** — "equilibrium" must be a decidable condition, or the swarm oscillates. What detects a
  live-lock where two critics keep re-raising each other's violations?
- **Gate preservation** — the four human stop points must survive the loss of the central sequencer. The
  Blackboard must make *advancing past a gate* a state transition that requires recorded human approval.

**Thesis for the segment:**
> Moving the state out of the Orchestrator's head and onto disk is the single change that attacks token volume,
> fidelity, and the architecture's philosophical contradiction at once — provided we solve ownership,
> termination and gate preservation first.

---

## 8. Segment 7 — Future MIG phases **[planned]**

| Phase | Scope | Value | Effort | Status |
|---|---|---|---|---|
| **0 — MVP** | `di.xml` + `events.xml` + MFTF group index + `MOCKS` edges + git-diff symbol extraction | Catches both ACP2E-5180 blind spots | ~1–2 wks | **shipped** |
| **1 — Call graph** | Reverse `CALLS` / `IMPLEMENTS` via `php-parser`, incremental cache keyed by file hash | Direct-caller impact; removes the domain-adjacency + grep fallback for one-hop edges | ~2–3 wks | backlog **R-MIG1** (P2, epic) |
| **2 — Coverage TIA** | `COVERS` edges from a clover baseline (test → line → symbol) | Ground-truth selection; kills false negatives | ~3 wks | planned |
| **3 — Orchestrator + gate** | Policy gate, CI status check, CI-failure → Red feedback | Closes the autonomy loop | ~2 wks | planned |

**Success metric to quote:** target **100% recall on the regression-replay corpus** for
acceptance-breaking changes — precision is explicitly the secondary metric. Known risk: dynamic DI and
`ObjectManager::get()` are not statically resolvable; the mitigation is to treat unresolved interface
calls as edges to *all* preferences and widen scope at low confidence.

**Thesis for the segment:**
> Phase 0 is a safety net. Phases 1–3 are what turn MIG into the **backbone of the autonomous loop** —
> note that Phase 3 is also what makes a Blackboard architecture safe to run less attended.

---

## 9. Segment 8 — Dry run: ACP2E-5537

### 9.1 The ticket

| Field | Value |
|---|---|
| **Key** | [ACP2E-5537](https://jira.corp.adobe.com/browse/ACP2E-5537) — *GraphQL addProductsToCart returns UNDEFINED for some cart validation errors* |
| **Type / Priority** | Backport Request · Normal · `p2e_commerce_escalation` |
| **Status** | In Development · Dev: Dmytro Yushkin · QE: Kateryna Muntianu · due 2026-11-27 |
| **Parent** | ACP2E-5536 (Customer Request, Cart & Checkout) — fixed upstream, **Deployment Queue** |
| **Why it's ours** | *"The backport tool was not able to successfully backport solution. Moving ticket for manual backport."* (2026-11-05) |
| **Source** | Glean-indexed Jira snapshot, updated 2026-11-06. Treat as indexed, not live state. |

**Why this is a good workshop ticket:** it is small enough to hold in one slide, it is a **real
configuration-mapping defect** (not a toy), its blast radius is genuinely non-obvious, and it is a
*backport* — which lets us show how the swarm behaves when a fix already exists upstream.

### 9.2 The defect

Validation messages from `addProductsToCart` are mapped to error codes by *substring match on the
message text*. Messages with no matching entry fall through to `UNDEFINED`. Clients that key on the code
— notably the Live Search PLP Widget — then show a generic "Something went wrong" instead of the
actionable message Commerce already produced.

**Reproduction** (from the ticket):
1. Simple product with sufficient stock.
2. Advanced Inventory → disable *Use Config Settings* for **Maximum Qty Allowed in Shopping Cart**, set **5**.
3. `mutation { createEmptyCart }`
4. `addProductsToCart(cartId: "…", cartItems: [{ sku: "<sku>", quantity: 6 }]) { user_errors { code message } }`

| | |
|---|---|
| **Expected** | `The requested qty exceeds the maximum qty allowed in shopping cart` with a specific code — triage proposed `INSUFFICIENT_STOCK` |
| **Actual** | Same message, `"code": "UNDEFINED"` |

### 9.3 STEP 1 — Scribe: intake brief ✋ **STOP 1**

The Scribe retrieves the ticket through Glean, then produces the structured brief — and, in BDAD framing,
the **behavior contract**:

```gherkin
Scenario: Max-qty validation returns an actionable error code
  Given  a simple product whose "Maximum Qty Allowed in Shopping Cart" is 5
  And    the product has sufficient stock
  When   a guest adds quantity 6 via the addProductsToCart mutation
  Then   user_errors contains the max-qty message
  And    its code is INSUFFICIENT_STOCK, not UNDEFINED
```

| Brief field | Value |
|---|---|
| **Domain** | Commerce Web APIs (GraphQL) — fix lands in Catalog/Cart wiring; **adjacent:** Inventory Management (MSI), Commerce Unified Experience (Live Search consumer) |
| **Repro class** | API-functional (GraphQL) — **not** a concurrency ticket, so no MFTF-only constraint |
| **Known ambiguity to surface, not invent** | The ticket lists **seven further unmapped messages** beyond the reported one. *Is the agreed scope the reported max-qty case only, or the full list?* |

> **This is the single most important beat in the dry run.** The Scribe must **not** silently pick a
> scope. A naive single-prompt agent maps the one message in the title and reports success; the
> stakeholder then reopens the ticket for the other seven. Stop 1 exists precisely to make the human
> answer this before any test is written.

**Workshop decision for the dry run:** scope = the reported max-qty message **plus** the other messages
listed in the ticket notes, each with an explicit code decision.

### 9.4 STEP 2 — Dependency Tracer: blast radius

Here the grep-vs-MIG contrast becomes concrete. The mapping is **data in `di.xml`**, consumed through
constructor injection — the relationship between the config entry and the class that reads it is exactly
the kind of edge text search does not carry.

Verified sites in this branch:

| Site | Evidence |
|---|---|
| Code that resolves the code | `app/code/Magento/Quote/Model/Cart/AddProductsToCartError.php:16` — `getErrorCode()` at `:61`, `ERROR_UNDEFINED = 'UNDEFINED'` at `:18` |
| The mapping being patched | `app/code/Magento/Quote/etc/di.xml:173-189` — `errorMessageCodesMapper` |
| The *other* mapping (do not confuse them) | `app/code/Magento/QuoteGraphQl/etc/graphql/di.xml:87-98` — `UpdateCartItems::messageCodesMapper`, which already catches this via its broader `"The requested qty"` entry at `:90` |
| Where the message originates | `app/code/Magento/CatalogInventory/Model/StockStateProvider.php:132` — `__('The requested qty exceeds the maximum qty allowed in shopping cart')` |
| MSI's parallel path | `ext/magento/inventory/InventorySales/Model/IsProductSalableForRequestedQtyCondition/IsCorrectQtyCondition.php:154` — `isMaxSaleQuantityCheckFailed()` |
| Non-obvious second consumer | `magento2b2b/.../PurchaseOrder/Model/AddItemsToCart.php:32-126` — injects `AddProductsToCartError` (**B2B is in the blast radius**) |
| Existing coverage to keep green | `app/code/Magento/Quote/Test/Unit/Model/Cart/AddProductsToCartErrorTest.php`; `dev/tests/api-functional/.../Quote/AddProductsToCartTest.php:97`; `.../CatalogInventory/UpdateCartItemsTest.php:85` |

**The matching mechanic is the trap.** `getErrorCode()` (`AddProductsToCartError.php:61-67`) does
`preg_replace('/\d+/', '%s', $message)` and then `stripos($message, $codeMessage)` — the mapper key must
be a **substring of the message**. The existing entry `"The requested qty is not available"`
(`di.xml:183`) therefore does **not** match `"The requested qty exceeds the maximum qty allowed…"`. That
is why the reported case still returns `UNDEFINED`.

**Three findings a grep-only pass would plausibly miss:**
1. **B2B's `PurchaseOrder\AddItemsToCart` consumes the same class** — a broadened mapping changes B2B
   behavior too.
2. **There are two independent mappers**; "fixing it" in the wrong file changes `updateCartItems`, which
   is not broken.
3. **Over-broadening is a real risk.** Shortening the key to `"The requested qty"` (as `updateCartItems`
   does) would silently re-classify *any* message starting that way. The ordering of
   `foreach ($this->errorMessageCodesMapper …)` means **first match wins** — so key specificity and
   ordering are part of the fix, not an afterthought.

### 9.5 STEP 3 — Test Author: Red first ✋ **STOP 2**

**Repro vehicle:** API-functional GraphQL test alongside
`dev/tests/api-functional/testsuite/Magento/GraphQl/Quote/AddProductsToCartTest.php`, which already
asserts `'code' => 'INSUFFICIENT_STOCK'` at `:97` for a neighbouring case — the pattern exists and should
be reused, not reinvented.

Assertions, invariant-first:
- `user_errors[0].code === 'INSUFFICIENT_STOCK'` for the max-qty scenario (**currently `UNDEFINED`**).
- The message is still returned unchanged and still translatable.
- One case per additional in-scope message from the ticket's list.
- **Guard assertion:** a message that *should* remain `UNDEFINED` still does — this is what stops the fix
  from degenerating into "map everything to INSUFFICIENT_STOCK".

**Red must fail for the right reason.** Demonstrate the actual assertion diff
(`UNDEFINED` vs `INSUFFICIENT_STOCK`) — not a fixture or environment error. In the room, this is the
moment to point back at §2.2: *this* is the check a single-agent loop quietly skips.

### 9.6 STEP 4 — Patch Generator: Green ✋ **STOP 3**

The expected change is **configuration, not logic** — add the missing keys to `errorMessageCodesMapper`
in `app/code/Magento/Quote/etc/di.xml:175-187`, choosing keys specific enough not to over-capture, with
codes per the ticket and triage (`INSUFFICIENT_STOCK` for the max-qty case).

Constraints the profile enforces: PSR-12, strict typing, DI, Cloud compatibility, i18n — and, critically,
**the Patch Generator may not edit the acceptance test to reach Green.**

> **Backport-specific beat:** the parent ACP2E-5536 fix exists upstream (PR referenced in Jira:
> `adobe-commerce-tier-4/magento2ce#914`) and the automated backport **failed**. The disciplined move is
> to use the upstream patch as *evidence of intended shape* while re-deriving it against this branch —
> the automation failed precisely because the contexts differ. Note that this branch's
> `AddProductsToCartError` already carries untranslated-`Phrase` handling (`:31-33`, `:45`), so the
> branches are **not** identical and a blind cherry-pick is exactly the wrong instinct.

### 9.7 STEP 5 — Consensus Gateway: deterministic validation

`php -l` · PHPCS · static suite · MFTF generation · **cross-domain gate** (Web APIs → MSI + B2B, per the
domain adjacency map, because of the `PurchaseOrder` consumer) · **mock-surface sync** (does
`AddProductsToCartErrorTest` still cover the mapper's shape?). Verdict is binary; NOT-GREEN returns the
work via Loop-back B.

### 9.8 STEP 6 — Scribe: egress ✋ **STOP 4**

Dev summary, release notes, Manual Steps for QE (Kateryna Muntianu) mirroring the reproduction, and the
postmortem with flow trace. Two egress rules apply directly here:

- **R-SECSCRUB** — not security-sensitive in this ticket, but state the rule: shipped artifacts describe
  *what the code does*, never *what attack it defeats*.
- **Documentation** — triage explicitly asked that
  `developer.adobe.com/commerce/webapi/graphql/schema/cart/mutations/add-products#errors` be updated.
  That is a real deliverable and must not fall off the end of the pipeline.

### 9.9 What the dry run demonstrates — the closing slide

| Beat | What it proved |
|---|---|
| Stop 1 scope question | The swarm surfaces ambiguity instead of inventing acceptance criteria |
| Two mappers, B2B consumer | MIG-style tracing finds consumers grep's text search does not connect |
| `stripos` substring semantics | Reading the actual resolution code prevents a plausible-but-wrong fix |
| Guard assertion at Red | Separated authorship prevents "make everything INSUFFICIENT_STOCK" |
| Failed auto-backport | Re-derive against the real branch; automation failed because contexts differ |
| Docs deliverable at Stop 4 | Egress is part of done, not an afterthought |
