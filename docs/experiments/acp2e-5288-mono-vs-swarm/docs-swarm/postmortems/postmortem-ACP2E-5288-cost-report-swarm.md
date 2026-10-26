# ACP2E-5288 — Session Cost Report (swarm run)

Captured for the mono-vs-swarm comparison experiment. Working-tree artifact, not committed.

## Totals

| Metric | Value |
|---|---|
| Total cost | **$42.99** |
| Total duration (API) | 1h 33m 53s |
| Total duration (wall) | 2h 46m 36s |
| Total code changes | 1496 lines added, 185 lines removed |

## Usage by model

| Model | Input | Output | Cache read | Cache write | Cost |
|---|---|---|---|---|---|
| claude-haiku-4-5 | 5.6k | 17.6k | 2.1m | 47.3k | $0.3645 |
| claude-opus-4-8 | 122.3k | 363.5k | 43.1m | 1.6m | $42.27 |
| claude-sonnet-5 | 937 | 11.4k | 540.6k | 54.1k | $0.3589 |

## Credit

- Claude Code and Cowork credit: **42% used** (one-time credit, expires September 2).
- Usage credits: $0.00 / $600.00 spent · resets Sep 1 (America/Chicago).

## Usage characteristics (last 24h, approximate, this machine only)

- 75% of usage was at >150k context (longer sessions cost more even when cached).
- 54% of usage came from subagent-heavy sessions (each subagent runs its own requests).
- 28% of usage came from subagents under `test-author`.

### Subagents — % of usage

| Subagent | % of usage |
|---|---|
| test-author | 28% |
| dependency-tracer | 2% |
| scribe | 1% |

## Notes for the comparison

- Nearly all spend (~$42.27 of $42.99) was Opus 4.8 as the orchestrating/primary model; Haiku and Sonnet subagent work was negligible cost.
- The dominant driver was the MFTF reproduction effort under `test-author` (two RED/GREEN authoring passes on a two-tab acceptance test, each with slow browser runs), consistent with the finding that this multi-tab concurrency bug could not be reproduced in-process and required MFTF.
</content>
