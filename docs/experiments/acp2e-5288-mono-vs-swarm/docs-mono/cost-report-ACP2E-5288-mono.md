# Cost Report — ACP2E-5288

Source: Claude Code `/cost` (this machine's recent activity ≈ this task's session).
Captured: 2026-08-27.

---

## Raw figures

| Metric | Value |
|---|---|
| **Total cost** | **$76.35** |
| Total duration (API) | 1h 35m 7s |
| Total duration (wall) | 1d 21h 28m |
| Code changes | 1002 lines added, 190 lines removed |

### Usage by model

| Model | Input | Output | Cache read | Cache write | Cost |
|---|---|---|---|---|---|
| claude-haiku-4-5 | 946 | 14 | 0 | 0 | $0.0010 |
| claude-opus-4-8 | 28.6k | 397.1k | 97.4m | 1.8m | $76.35 |

### Context / limits notes (from `/cost`)

- **98% of usage was at >150k context** (last 24h).
- One-time Claude Code & Cowork credit: **37% used** (expires Sep 2).
- Usage credits: $0.00 / $600.00 (resets Sep 1, America/Chicago).

## Interpretation

- Cost is dominated by **~97.4M cache-read tokens**, not output (397k) or fresh input (28.6k). Cache reads are far
  cheaper per token than fresh input, but at ~97M they drive essentially the whole bill.
- The `>150k context` flag is the cause: this was one long, unbroken session that accumulated a large live context
  (the EE staging codebase plus every exploratory reproduction trace) and re-read it every turn.
- **The wandering reproduction phase cost twice:** the test/analysis runs themselves, *and* the inflated per-turn
  cache-read bill from keeping the whole investigation hot in context.
- The fix itself (small, well-scoped) and its validation were comparatively cheap; the expense was the search for
  a faithful reproduction.

## Levers for next time

1. **`/compact` once the reproduction is nailed**, and `/clear` or `/compact` at phase boundaries
   (investigate → fix → validate → docs) so stale exploration isn't re-read every turn.
2. **Match the tool to the bug class up front** — a multi-request / two-tab bug belongs in MFTF (§8); ~10
   integration variants on a bug the harness can't represent inflated both time and context.
3. Wall time (~1d 21h) is mostly pauses; the meaningful compute figure is **1h 35m API time**.

---

*Local-only working artifact (with the postmortem, retrospective, and diff) — not staged or committed.*
