# Post-Mortem: AI-Driven TDD Workflow — ACP2E-5203

*Principal AI Engineer assessment. Objective: harden this into a bulletproof, highly autonomous Behavior Driven Agentic Development (BDAD) environment for Adobe Commerce core.*

| | |
|---|---|
| **Current version** | v1.1 |
| **Last updated** | 2026-09-10 |
| **Scope** | ACP2E-5203 (CMS WYSIWYG false-positive "Invalid HTML content provided" on ordinary anchor links) |
| **Status legend** | ✅ done · 🟡 partial · ⬜ not started · 🔎 resolved by investigation |

### Revision history
| Version | Date | Change |
|---|---|---|
| v1.0 | 2026-08-12 | Initial record: clean Red→Green fix of an over-broad security regex, root-cause archaeology into the original XSS defense (AC-15683), cross-domain gate (CE+EE), and the ticket-ingestion friction this run surfaced. Companion to [`postmortem-ACP2E-5180.md`](../ACP2E-5180/retrospective.md) and [`postmortem-ACP2E-5216.md`](../postmortem-ACP2E-5216.md). |
| v1.1 | 2026-09-10 | Added the follow-up callout below: the `maskQuotedAttributeValues()` approach recorded here was later found to be a security regression and was re-implemented in **ACP2E-5351**. The "This run was clean" framing in §0/§2 is corrected accordingly. |

> ⚠️ **Superseded in part by [ACP2E-5351](../ACP2E-5351/retrospective.md).** The fix recorded in §I introduced `maskQuotedAttributeValues()`, which blanked **any** quoted string before the malformed-syntax filter `$contentFiltrationPattern` ran. A later code review found this blinds the filter: a payload wrapped in quotes — or straddled by a stray apostrophe in prose — is hidden from the check while the DOM is still parsed from the original, unmasked content. ACP2E-5351 **re-implements the masking step** (it is not a revert and not a separate layer): the blanket mask is replaced by a quote-aware start-tag-scoped mask that only blanks values inside a genuine start tag and preserves smuggled `<\w+` tag-opens, so the filter still fires. ACP2E-5203's own regression tests (the four `anchor-*` cases) are retained and stay green. Read §0/§2's "this run was clean / preserved the original security property exactly" claims in that light — the false-positive fix was correct, but its masking mechanism reopened a bypass. See [`docs/retrospectives/ACP2E-5351/retrospective.md`](../ACP2E-5351/retrospective.md). **Backport implication:** the P0 backport tracked in PART VI item 2 (**ACP2E-5204**, 2.4.9) carries this same regression if it shipped the v1.0 masking — the ACP2E-5351 re-implementation must be backported alongside it.

> **How to read this doc:** §I is the fixed record of the ACP2E-5203 fix. §II is the ticket-ingestion friction this run surfaced — the closest thing to a "blind spot" here, since the fix itself hit none. §III–§VI carry MIG context, BDAD improvements, rules status, and the action plan.

---

# PART I — The Fix (ACP2E-5203, fixed record)

## 0. Executive Summary

A regex introduced by AC-15683 ("Cms content improvement," first shipped in 2.4.9) to block **unquoted attribute-smuggling** (e.g. `<img/onerror=alert(1)>`) was written broadly enough that it also matched **any quoted URL containing a slash followed by another attribute** — i.e. essentially every real-world `<a href="..." target="_blank">` link. Every ordinary CMS block/page save with a normal external link triggered a false "Invalid HTML content provided" warning, or an outright save failure with `cms/wysiwyg/force_valid=1`.

This run was **clean**: no regressions, no broken cross-domain test, no mocking-boundary surprise. The fix was scoped to preserve the original security property exactly (verified by re-running the pre-existing malicious-pattern test cases after the change) rather than simply loosening the regex until the customer's sample passed.

## 1. The Execution Journey (Red → Green → Refactor)

| Phase | Action | Outcome |
|---|---|---|
| Phase 0 — Blast radius | `ConfigurableWYSIWYGValidator` is DI-wired as `DefaultWYSIWYGValidator` and consumed via `WYSIWYGValidatorInterface` by `Cms\Model\Block`, `Cms\Model\Page`, `Catalog\Model\Attribute\Backend\DefaultBackend` (WYSIWYG EAV attributes), `Variable\Model\Variable`, and (EE) `Banner\Model\Banner`. No B2B consumer. | ✅ Contained to CE+EE, gate scoped accordingly |
| R1 — Reproduce outside the suite first | Ran the production regex directly against the ticket's 4-row trigger table via a standalone `php -r` snippet, before touching any test file. | ✅ Confirmed the ticket's claims against actual code, not just its prose |
| R2 — Red | Added 4 data-provider cases to the existing `ConfigurableWYSIWYGValidatorTest::getConfigurations()` (augment, not a new file) mirroring the trigger table, incl. one deliberate control case (href as last attribute) expected to already pass. | ❌→✅ 3/4 failed exactly as predicted; the control case passed exactly as predicted — reproduction locked with a falsifiable prediction, not just "it's red" |
| R3 — Root-cause archaeology | `git show` on the exact commit (`bd1e9dfd410`, AC-15683) that introduced the pattern, to recover *intent* before editing: the 3rd/1st alternations exist to catch the HTML "slash-as-attribute-separator" parsing quirk in **unquoted** attribute smuggling, not to inspect quoted URL content at all. | 🔎 resolved by investigation — this is what kept the fix surgical instead of a blanket loosen |
| R4 — Green | Added `maskQuotedAttributeValues()`: blank out quoted attribute-value contents before running the malformed-syntax regex; the DOM is still loaded from the *original*, unmodified content. | ✅ 25/25 unit cases, including the pre-existing unquoted-smuggling cases, which must still fail closed |
| R5 — Cross-Domain Gate | `Cms/Wysiwyg/ValidatorTest`, `Catalog/.../DefaultBackendTest`, `Variable/VariableTest`, EE `Banner/BannerTest` (32/32); integration `WysiwygRestrictCommandTest` (3/3). | ✅ No consumer regressed |
| R6 — Validate | `php -l` + PHPCS clean on both files; static suite (Validator/LiveCode/CopyPaste filter) 6/6, 1 unrelated skip. | ✅ |

## 2. Why this stayed clean (worth naming explicitly)

Three choices did the work, and each maps to an existing CLAUDE.md rule:

1. **Blast-radius enumeration before editing** (Rule A) caught that this is a 5-consumer, 2-BU (CE+EE) shared class *before* the first line of code changed, so the cross-domain gate was planned, not improvised after a surprise failure (contrast ACP2E-5180 R2).
2. **Reproducing the bug against the real regex before writing a test** turned the ticket's prose trigger-table into a falsifiable, executable claim, which then became the RED test's exact expected values — no guessing at what "should" fail.
3. **Reading the git history of the exact line being changed** before writing the fix. A regex that's "too aggressive" invites the lazy fix of deleting or loosening it wholesale; recovering that it was purpose-built against a specific parser quirk (unquoted `/`-as-separator smuggling) made "quote-mask before matching" the obviously-correct, minimal-diff fix — and gave a concrete thing to keep testing (the pre-existing `on\w+=` cases) rather than just re-running the new cases.

None of these are new rules — they're Rule A applied *before* writing any code, plus a "read the blame before you touch the line" habit that isn't yet codified anywhere. See T1 in Part IV.

---

# PART II — Ticket-Ingestion Friction (the closest thing to a blind spot this run)

The ticket wasn't handed over as clean text — it was a **browser-saved Jira page** (`ACP2E-5203.html`, ~2000 lines, ~100KB+ of Jira/Atlassian JS/CSS/webresource boilerplate per page-load). The actual Description field didn't appear until line 1486.

**What worked:** grepping for structural anchors (`id="description-val"`, `id="summary-val"`, `id="type-val"`, `rowForcustomfield_*`) rather than reading the file top-to-bottom, page by page. This found the real content in 3 targeted greps instead of ~8 sequential 250-line page reads (a >100KB truncation cap made naive reading a non-starter).

**What was noise, and had to be identified as such:** the "Resolution Summary" and "Technical Problem Description" custom fields — which *sound* like they could carry acceptance criteria — were unfilled Jira templates (literal placeholder text like "Yes / No / N/A", "Brief technical summary of the fix"). Treating them as signal would have wasted a lookup cycle per field; they had to be individually opened and recognized as empty scaffolding.

**Net effect:** no wrong conclusion was drawn — the real Description field already contained an unusually complete source of truth (preconditions, steps, expected/actual result, a self-verified trigger-characterization table, and root-cause pointers to the exact file and commit). But that completeness was a property of *this* reporter's ticket, not of the ingestion path. A less careful ticket in the same raw-HTML format, or one relying on the templated custom fields for acceptance criteria, would be much easier to misread.

This is the same class of gap as the MIG discussion in the companion docs — just at the *input* boundary instead of the *code* boundary: there is no structured, mechanical way to pull "Description / Expected Result / Acceptance Criteria" out of a ticket; it currently depends on an agent successfully grepping past a Jira page's DOM scaffolding and recognizing template placeholders as noise.

---

# PART III — Retrospective in MIG context

- **No acceptance-test gap was pre-existing here** (unlike ACP2E-5216's file-option regression) — the fix landed with net-new unit coverage on first attempt. But the underlying **absence of any integration/MFTF coverage** for `DefaultWYSIWYGValidator` content rules (only the `force_valid` config toggle was covered, in `WysiwygRestrictCommandTest`) is itself the recurring gap: a shared, security-relevant validator with 5 consumers across CE/EE had **zero** acceptance-level regression protection before this ticket. MIG's blast-radius view (docs/mig/impact-graph-proposal.md) would have flagged this as a high-fan-in, low-coverage node independent of any specific bug report.
- **Ticket-ingestion is outside MIG's current scope** (Part II above) but is the same "make the implicit graph explicit" instinct — here applied to Jira's DOM instead of `di.xml`/`events.xml`.

---

# PART IV — How to improve BDAD (from this ticket)

| # | Improvement | Addresses |
|---|---|---|
| T1 | **Codify "read the blame before you touch the line."** When a fix targets a regex/heuristic/allowlist that was added for a named security reason (commit message references a security ticket, e.g. `AC-15683`), `git show`/`git log -S` that exact addition before editing, and re-verify its original test cases still fail closed after the fix — not just that the new cases pass. | Prevents "fix by loosening" that silently reopens the original vulnerability |
| T2 | **Prefer a structured ticket export (Jira REST API / plain-text "Export → Text") over a saved-page HTML dump** when available. If only HTML is available, grep by Jira's stable DOM ids (`#description-val`, `#summary-val`, `#type-val`, `[id^="rowForcustomfield_"]`) rather than reading sequentially. | Ticket-ingestion friction (Part II) |
| T3 | **Flag templated custom fields as empty scaffolding automatically** — Jira templates reliably contain giveaway placeholder tokens (`Yes / No / N/A`, `<e.g. ...>`, `EXAMPLE_PR_`). A quick pattern check before treating a custom field as signal avoids a wasted read. | Ticket-ingestion friction (Part II) |
| T4 | **Add an integration/MFTF smoke test for `DefaultWYSIWYGValidator`** (save a CMS block with representative safe *and* malicious content through the real DI chain) so this shared validator has acceptance-level coverage independent of any one bug ticket — closing the exact gap this ticket's absence of prior coverage exposed. | Recurring "shared/high-fan-in class, zero acceptance coverage" pattern (echoes ACP2E-5216 T1) |

---

# PART V — CLAUDE.md / Rules impact (status)

- ✅ **Rule A (Blast-Radius)** applied cleanly and pre-emptively — planned the cross-domain gate before writing code, not after a failure.
- ✅ **"Search & Augment First"** honored — new cases added to the existing `ConfigurableWYSIWYGValidatorTest` data provider rather than a new test file.
- 🔎 **No rule currently exists for T1** ("read the blame on security-motivated code before editing it"). Candidate for a new lightweight rule alongside Rule A in §4, scoped to regex/allowlist/heuristic changes specifically.
- ⬜ **No rule exists for ticket-ingestion hygiene** (T2/T3) — out of scope for CLAUDE.md's code-focused rules today; more of an operating habit than a coding standard.

---

# PART VI — Prioritized Action Plan (status)

| # | Item | Addresses | Status |
|---|---|---|---|
| 1 | Land the fix (already done this run): `ConfigurableWYSIWYGValidator.php` + `ConfigurableWYSIWYGValidatorTest.php`, all gates green. | The reported bug | ✅ **Done** |
| 2 | Apply the identical patch to the linked backport, **ACP2E-5204** (2.4.9 backport, currently `Blocked`). | Customer-facing 2.4.9 fix | ⬜ **P0, next** |
| 3 | Add integration/MFTF smoke coverage for `DefaultWYSIWYGValidator` (T4). | Recurring shared-validator coverage gap | ⬜ |
| 4 | Propose a CLAUDE.md addition for "read the blame before editing security-motivated regex/heuristics" (T1). | Prevent future "fix by loosening" | ⬜ |
| 5 | Note Jira-DOM-id grep pattern (T2/T3) as an operating habit (not a code rule) — candidate for a short internal runbook rather than CLAUDE.md. | Ticket-ingestion friction | ⬜ |
| 6 | MIG Phase 0 (tracked in ACP2E-5180 ledger L15) would have surfaced the pre-existing coverage gap on `DefaultWYSIWYGValidator` independent of this ticket. | Systemic, cross-ticket | ⬜ (tracks ACP2E-5180 L15) |

**Net:** the ACP2E-5203 fix is complete, tested, and validated with zero regressions. The durable output beyond the fix itself is a named, reusable habit (T1 — check the blame before touching security-motivated code) and a concrete backfill target (T4) for the coverage gap the ticket exposed.
