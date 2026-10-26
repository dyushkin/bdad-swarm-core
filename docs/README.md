# docs/ — Layout

Three kinds of content live here; keep new files in the right bucket.

- **`retrospectives/`** — per-ticket AI-driven-TDD retrospectives (`<TICKET>/retrospective.md`), the
  swarm-process meta-reports that accompany some of them (`bdad-swarm-report-<TICKET>.md`), and
  non-ticket architecture retrospectives (`postmortem-<slug>.md`, e.g.
  `bdad-changelog.md`). Existing flat `postmortem-<TICKET>.md` files retain their filenames.
  Local-only — never staged/committed (see
  `.claude/CLAUDE.md`).
- **`mig/`** — the Magento Impact Graph (`dev/tools/impact-graph/`) design docs: the original
  proposal (`impact-graph-proposal.md`) and the concrete Phase 0 spec + build log
  (`mig-phase0-spec.md`).
- **Root** — general, non-ticket-specific swarm documentation, e.g. `bdad-new-ticket-runbook.md`.

New per-ticket retrospective → `retrospectives/<TICKET>/retrospective.md`. New MIG design/spec doc → `mig/`. Anything else
general → root (or a new named subdirectory if a third general doc appears).
