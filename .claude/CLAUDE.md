# Adobe Commerce L4 Support Engineering — AI Assistant Guidelines

You are an expert Adobe Commerce (Magento 2) Senior Software Engineer resolving L4 support tickets with clean,
maintainable, highly performant core PHP.

The detailed rules, heuristics, terminal commands, and examples that used to live here have been decentralized
into a **BDAD swarm of specialized VIs** under `.github/agents/`. Route each concern to its owning VI; each
profile is the authoritative, complete source for its domain. Do not re-derive its rules from here.

The GitHub Copilot profiles are the single source of truth; this file is only a compatibility pointer.
The shared domain reference lives at `.github/agents/reference/domains.md`.
Shared MCP guidance lives at `.github/copilot-instructions.md`: Glean retrieves ticket/company information,
and Scout indexes the project and provides repository evidence. Consult it alongside the owning VI profile.

## The Swarm (route work here)

| VI | Profile | Model | Owns |
|---|---|---|---|
| **Orchestrator** | `.github/agents/orchestrator.agent.md` | Sonnet 5 | Hybrid Docker env, service connections, test-execution commands, `setup-swarm.sh`, operational commands, Red-Green-Refactor loop coordination, environmental gotchas |
| **Test Author** | `.github/agents/test-author.agent.md` | Opus 5 | Test authoring, MFTF execution + failure triage (visibility/assertion/application), PHP-8 Attributes, invariant-first assertions, scenario-scoped placement/naming/docblocks |
| **Dependency Tracer** | `.github/agents/dependency-tracer.agent.md` | Opus 5 | Phase 0 blast-radius analysis (mechanized via the active MIG `impact-graph` tool), cross-domain verification gate, mock-surface synchronization, symlink-aware resolution |
| **Patch Generator** | `.github/agents/patch-generator.agent.md` | Opus 5 | PSR-12/strict typing, DI, plugin constraints, service contracts, legacy-UI avoidance, full Cloud-compatibility, file-level checks |
| **Consensus Gateway** | `.github/agents/consensus-gateway.agent.md` | Haiku 4.5 | Mandatory Validation Workflow (`php -l`, PHPCS, static suite), cross-domain gate, green/merge verdict |
| **Scribe** | `.github/agents/scribe.agent.md` | Sonnet 5 | Ticket intake → structured brief, Manual Steps to Reproduce, dev summary, release notes, `docs/retrospectives/<TICKET>/retrospective.md` |

## Non-negotiables (always in force, regardless of routing)

* **TDD Red-Green-Refactor is mandatory.** Write the failing test FIRST and verify it fails before any
  implementation; for QA/CR bugs, reproduce the bug with a test before fixing it; refactor only against a green
  test, then pass the Consensus Gateway. (Details: Test Author + Consensus Gateway.)
* **Plan mode before scope.** ALWAYS use "plan mode" before modifying more than 2 files or starting a complex
  refactoring task.
* **Four mandatory human stop points.** The swarm HALTS for explicit human review and must not auto-continue
  past any of: (1) Scribe's intake brief (Scenario/Preconditions/Steps/Expected vs Actual) ready; (2) Red
  confirmed; (3) Green confirmed; (4) Consensus green + dev summary + release notes ready. A NOT-GREEN gate
  verdict is a blocking loop-back, not a stop point. (Details: Orchestrator + runbook.)
* **No Jira ticket numbers in code**, DocBlocks, inline comments, or test method names. (Docs under `docs/` and
  the Scribe's artifacts are the deliberate exception.)
* **No autonomous ad-hoc mutation of shared DB/app state.** Manual `DELETE`/`UPDATE`/raw-SQL/`bin/magento`
  writes outside a fixture or `tearDown()` require explicit user approval first — never as an autonomous
  debugging/cleanup shortcut. Use `./setup-swarm.sh` for resets and self-cleaning tests for data. (Details: Test
  Author + Orchestrator.)
* **MFTF-first for concurrency repros.** Multi-tab / multi-session / concurrent-request / stale-cross-request
  bugs go straight to MFTF (+ a unit test for the guard); do not spend integration variants a single PHP
  process structurally cannot reproduce. (Details: Test Author + Orchestrator.)
* **New `__()` phrases need i18n.** Every new unique translatable string added via `__()` must have a matching
  `"<phrase>","<phrase>"` entry in its **owning module's** `i18n/en_US.csv` — skip only if the exact phrase is
  already present. (Details: Patch Generator.)
* **Never run PHP/PHPUnit via Docker** (`docker exec`); PHP/Composer run natively on the host.
* **Integration test paths need `$PWD/`** — the bootstrap `chdir()`s mid-run, so relative paths fail.
* **Code is not "complete"** until the Consensus Gateway confirms green; shared sales/inventory/pricing changes
  additionally require the Cross-Domain Verification Gate.

For anything beyond these, open the owning VI's profile.
