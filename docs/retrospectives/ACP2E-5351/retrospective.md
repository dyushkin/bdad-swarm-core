# Post-Mortem: AI-Driven TDD Workflow — ACP2E-5351

*Principal AI Engineer assessment. Objective: harden this into a bulletproof, highly autonomous Behavior Driven Agentic Development (BDAD) environment for Adobe Commerce core.*

| | |
|---|---|
| **Current version** | v1.1 |
| **Last updated** | 2026-09-10 |
| **Scope** | ACP2E-5351 ("Investigate fix and comments from ACP2E-5203") — a security regression introduced by the ACP2E-5203 quote-masking fix in `ConfigurableWYSIWYGValidator` |
| **Status legend** | ✅ done · 🟡 partial · ⬜ not started · 🔎 resolved by investigation |

### Revision history
| Version | Date | Change |
|---|---|---|
| v1.0 | 2026-09-10 | Initial record: a code-review finding on the ACP2E-5203 fix (commit `867ff1551a2`) is confirmed as a real XSS-filter bypass, root-caused, and closed with a quote-aware, tag-scoped masking pass — without reopening the original ACP2E-5203 false-positive. Two Green course-corrections happened mid-run, both caught before the Consensus Gateway. Companion to [`postmortem-ACP2E-5203.md`](../ACP2E-5203/retrospective.md). |
| v1.1 | 2026-09-10 | Added the "Relationship to the reporter's interim revert" subsection to §0, clarifying that this fix supersedes both the original flawed mask and the reporter's temporary revert (it is a re-implementation of the masking step, not a revert or an added layer). |

> **How to read this doc:** §I is the fixed record of the ACP2E-5351 fix. §II is the two in-flight course-corrections this run required — the most valuable part of this record, since ACP2E-5203 itself needed none. §III–§VI carry retrospective/MIG context, BDAD improvements, rules status, and the action plan.

---

# PART I — The Fix (ACP2E-5351, fixed record)

## 0. Executive Summary

The ACP2E-5203 fix masked quoted attribute values with a **context-free string operation** — any `"…"` or `'…'` pair anywhere in the content was blanked before the malformed-syntax security regex (`$contentFiltrationPattern`) ran, regardless of whether that quote pair was really inside an HTML start tag. A code reviewer identified this as a **security regression**: an attacker could hide a payload by wrapping it in quotes (`<div data-x="<img/onerror=alert(1) a=">`), and even an incidental apostrophe in ordinary prose (`<p>It's fine</p>`) could straddle and mask an adjacent payload. Because the DOM was still loaded from the *original*, unmasked content, a payload the filter could no longer see could still be parsed by libxml into a live, executable element.

This ticket confirmed the bypass, deepened it with one case the reviewer hadn't found (an unbalanced in-text quote mispairing across markup, not just within an attribute), and replaced the blanket mask with a **quote-aware start-tag scanner**: quoted values are now only masked when they occur inside a genuine start tag, and smuggled tag-open sequences (`<body`, `<img`) inside a masked value are preserved so the filter still sees them. Text-context quotes and apostrophes are left untouched, closing the bypass without reopening the ACP2E-5203 false-positive on quoted URLs.

### Relationship to the reporter's interim revert (what this fix supersedes)

The ticket comment states the reporting side would *"for now… revert this piece just to get the filter coverage back,"* then asks for *"another pass so the fix doesn't blind the checks."* Those describe two distinct actions, and this fix is the second one — it supersedes **both** the original flaw **and** the stopgap:

- **The interim revert is a temporary stopgap, not the target end state.** Removing `maskQuotedAttributeValues()` outright does restore `$contentFiltrationPattern` coverage immediately, but at a cost: it reopens the *original* ACP2E-5203 false-positive (legitimate quoted-URL anchors are wrongly rejected again). It trades the security hole back for the usability bug — acceptable as a short-lived safety measure, not as the fix.
- **This change is not a revert, and not a layer added beside ACP2E-5203.** It branched from the ACP2E-5203 state (mask present) and **edited the masking in place** — blanket mask → quote-aware, start-tag-scoped mask. In git terms the diff is a *modification* of the ACP2E-5203 code, not a revert commit plus a new fix. See the "re-implementation, not addition/revert" characterization in the companion [`postmortem-ACP2E-5203.md`](../ACP2E-5203/retrospective.md) v1.1 callout.
- **End state supersedes both.** The final code holds both properties simultaneously — filter coverage restored **and** no false positive — which is exactly what neither the original flawed mask nor the bare revert achieves alone. If the interim revert has already landed upstream when this integrates, the merge/rebase simply reconciles to this corrected version; the net result is the corrected mask either way. This fix therefore makes the interim revert unnecessary and should replace it (and any reverted-to-unmasked state) on integration.

The run was **not** clean end-to-end (unlike ACP2E-5203): two Green attempts were rejected before Consensus, both caught by adversarial batteries run against the fix prior to the gate — see Part II.

## 1. The Execution Journey (Red → Green → Refactor)

| Phase | Action | Outcome |
|---|---|---|
| Phase 0 — Blast radius | Re-ran the same enumeration as ACP2E-5203: `ConfigurableWYSIWYGValidator` DI-wired as `DefaultWYSIWYGValidator`, consumed via `WYSIWYGValidatorInterface` by `Cms\Model\Block`, `Cms\Model\Page`, `Catalog\Model\Attribute\Backend\DefaultBackend`, `Variable\Model\Variable`, (EE) `Banner\Model\Banner`. No B2B consumer. Both touched methods (`maskQuotedAttributeValues`, and the new `$startTagPattern` constant) remain `private`; only `validate()` is `@api`. | ✅ Contained to CE+EE, unchanged from ACP2E-5203; no mock-surface sync needed |
| R1 — Confirm the regression outside the suite first | Ran `maskQuotedAttributeValues()` + `$contentFiltrationPattern` directly against the reviewer's two cases (quote-wrapped payload, stray-apostrophe masking) via a standalone script, before touching any test file. Both wrongly passed validation. | ✅ Regression confirmed against actual post-ACP2E-5203 code, not just the reviewer's prose |
| R2 — Deepen the repro | Adversarially explored the masking function beyond the reviewer's two cases and found: (3) a quoted `<body>` inside an attribute value dropping the multiple-`<body>` counter below the threshold, and (4) an **unbalanced in-text quote** (`<p>x="</p><img/onerror=alert(1) title="y">`) mispairing an `="` in plain text against a later attribute quote — deeper than either reviewer case, since it isn't confined to a single tag's boundaries. | 🔎 resolved by investigation — case (4) became the hardest constraint the fix had to satisfy |
| R3 — Red | Added 8 data-provider cases to the existing `ConfigurableWYSIWYGValidatorTest::getConfigurations()` (augment, not a new file): 4 must-reject security cases (quote-hidden `<body>` ×2 quote styles, stray-apostrophe event-handler masking, unbalanced-in-text-quote/apostrophe event-handler masking, unbalanced-in-text-quote multiple-`<body>`) and 2 must-accept false-positive guards for legitimate `attr="value"`-shaped prose. | ❌→✅ All 6 new must-reject-shaped cases failed exactly as predicted (wrongly accepted); both must-accept cases already passed as a control |
| R4 — Green attempt 1 (rejected pre-gate) | Narrowed the mask by anchoring it to `=` so only genuine `attr="value"` pairs were blanked. | ❌ Left the unbalanced-in-text-quote bypass (case 4) open — an adversarial battery run through the full validator caught it before Consensus; looped back as a **NOT-GREEN loop-back**, not a stop point |
| R5 — Green attempt 2 (rejected pre-gate) | Added a `hasAttributeSyntaxInText()` check to reject any `="` appearing in text content, closing case 4. | ❌ Over-corrected: reintroduced the exact ACP2E-5203 false-positive class on legitimate content (e.g. `<p>Use attr="value" in your HTML</p>`) — caught by a legitimate-content battery before Consensus; also traced to a malformed Red case (see Part II.c) |
| R6 — Green (final) | Introduced `$startTagPattern` — a quote-aware regex matching a complete start tag together with its quoted attribute values (`'/<\w+(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/'`). `maskQuotedAttributeValues()` now runs the existing quote-blanking pass *only inside* text matched by `$startTagPattern`, still preserving smuggled `<\w+` tag-opens inside each masked value. Quotes/apostrophes in text outside a start tag are left untouched entirely — no separate text-rejection check needed. | ✅ 33/33 SUT unit cases (25 pre-existing + 8 new), all Red cases now correctly reject, both false-positive guards still accept |
| R7 — Cross-Domain Gate | `Cms\Wysiwyg\ValidatorTest`, `Catalog\...\DefaultBackendTest`, `Variable\VariableTest`, EE `Banner\BannerTest` — 32/32, no regressions. | ✅ |
| R8 — Validate | `php -l` + PHPCS clean on both files; static suite (Validator/LiveCode/CopyPaste filter) 6/6, 1 unrelated skip. Total 74 tests across static + SUT unit + cross-domain, 0 failures, 0 regressions. | ✅ |
| R9 — Re-verify original invariants | Re-ran the pre-existing AC-15683 defenses (unquoted `javascript:` attribute value, `<script>` disallowed-tag rejection, unquoted attribute-smuggling `on\w+=`) and the ACP2E-5203 false-positive guard (`<a href="https://new.abb.com/drives" target="_blank" rel="noopener">Drives</a>`) — all still hold. | ✅ Neither the original XSS defense nor the ACP2E-5203 fix regressed |

## 2. Manual Steps to Reproduce

**Preconditions:** admin session able to save WYSIWYG-validated content (e.g. CMS Block content field), with the editor in source/HTML mode so the raw string reaches `ConfigurableWYSIWYGValidator::validate()` unmodified. Single request; no concurrency involved (`repro-class: single-session`).

**Security bypass — each of the following was wrongly ACCEPTED before this fix (should be REJECTED with "Invalid HTML content provided"):**

1. **Quote-wrapped payload:** save a CMS block containing
   `<div data-x="<img/onerror=alert(1) a=">`
   — the payload is hidden from `$contentFiltrationPattern` because it sits inside a quote pair that the ACP2E-5203 mask blanks unconditionally.
2. **Stray-apostrophe masking:** save
   `<p>It's</p><img/onerror=alert(1) title='x'>`
   — the apostrophes in "It's" and in `title='x'` straddle and mask the `<img/onerror=` construct.
3. **Multiple-`<body>` hidden in an attribute value:**
   `<body>ok</body><p title="<body>">x</p>`
   — the second `<body>` (inside the quoted `title`) is blanked, so the multiple-body counter drops from 2 to 1 and the check that guards against a smuggled second `<body>` never fires.
4. **Unbalanced in-text quote (found during this investigation, deeper than the reviewer's cases):**
   `<p>x="</p><img/onerror=alert(1) title="y">`
   — the `="` inside plain text mispairs against a later, unrelated quote, masking `</p><img/onerror=…` across a tag boundary. libxml still promotes `<img/onerror=…>` to a live DOM element. Same defect via apostrophe: `<p>x='</p><img/onerror=alert(1) title='y'>`.

**ACP2E-5203 false-positive regression guard — must remain ACCEPTED:**
`<a href="https://new.abb.com/drives" target="_blank" rel="noopener">Drives</a>` and other quoted-URL anchors.

**New false-positive guard (must remain ACCEPTED) — legitimate `="` appearing in text, not an attribute:**
`<p>Use attr="value" in your HTML</p>`
`<p>Write style='color:red' like this</p>`

---

# PART II — Two In-Flight Course-Corrections (the valuable part of this record)

Unlike ACP2E-5203, which landed clean on the first Green attempt, this ticket needed **two rejected Green attempts**, both caught by adversarial test batteries run against the candidate fix *before* it reached the Consensus Gateway — i.e. both are NOT-GREEN loop-backs internal to the Red→Green loop, not violations of a human stop point.

**(a) Attempt 1 under-corrected.** Anchoring the mask to `=` (only blanking `attr="value"`-shaped spans) closed the reviewer's two reported cases but left case 4 — an unbalanced in-text quote mispairing across a tag boundary — open. This is a materially harder case than either of the reviewer's: it doesn't require the payload to be inside any single tag's attribute list at all, only that a stray `="` or `='` appear somewhere earlier in the text. It was found by deliberately going beyond the reviewer's report and running a fuller adversarial battery against the changed function, not by trusting that the reported cases defined the full bug surface.

**(b) Attempt 2 over-corrected.** To close case 4, a `hasAttributeSyntaxInText()` check was added that rejected *any* `="` appearing in text content, regardless of what followed. This reintroduced the precise false-positive class ACP2E-5203 exists to prevent — ordinary documentation-flavored CMS content like `<p>Use attr="value" in your HTML</p>` started failing again. A legitimate-content battery caught this before Consensus.

**(c) Root cause of the over-correction — a malformed Red case.** One of the coordinator-authored Red cases asserted that a *single* injected `<body>` tag must be rejected. But `$contentFiltrationPattern`'s multiple-body guard was only ever designed to catch **more than one** `<body>` (`$valueCounts['<body'] > 1`) — a lone injected `<body>` was never part of the original security invariant. That bad expectation is what pushed attempt 2 toward the overly broad text-rejection check, since a narrower, tag-scoped fix couldn't satisfy an assertion that didn't correspond to any real defense. Once the malformed case was identified and corrected to match the actual pre-existing invariant, the tag-scoped `$startTagPattern` approach (R6) satisfied every genuine case without an extra rejection layer.

**Lesson:** before treating a new "bypass" case as in-scope, verify it reflects an invariant the code actually enforced pre-regression (re-read what `$contentFiltrationPattern` historically caught), not an invariant that sounds plausible. This is the same discipline as ACP2E-5203's T1 ("read the blame before touching the line") applied one step earlier — to *authoring* the Red case, not just to writing the fix.

---

# PART III — Retrospective in MIG context

- **Blast radius was unchanged from ACP2E-5203** — same CE+EE, same 5 consumers, same "no B2B" conclusion, both touched methods still `private`. Phase 0 for a follow-up ticket on a class already mapped by a prior ticket's Phase 0 is cheap to re-confirm and worth re-confirming explicitly rather than assumed, since a fix that changes call signatures or visibility would invalidate the prior map silently.
- **The ACP2E-5203 postmortem's own action item T1** ("read the blame before touching security-motivated code, and re-verify original test cases still fail closed") was reinforced here in a stronger form: it's not enough to re-verify the *original* AC-15683 cases after a fix — the *previous* ticket's own fix (ACP2E-5203's quote-mask) needed the same blame-reading + original-invariant-reverification discipline applied to it, and hadn't received it at the time, which is exactly how this regression shipped.
- **MIG's blast-radius view would not have caught this class of regression** — this was a logic/security-invariant defect internal to one already-identified node, not a new caller or a widened blast radius. This is a coverage-depth problem (adversarial test batteries against a security-relevant regex), not a coverage-breadth problem MIG's impact graph targets.
- Part IV's T4 action item from ACP2E-5203 (integration/MFTF smoke coverage for `DefaultWYSIWYGValidator`) remains open and would have caught this regression at a higher level than unit tests alone, independent of a manual code review catching it first.

---

# PART IV — How to improve BDAD (from this ticket)

| # | Improvement | Addresses |
|---|---|---|
| U1 | **Treat a security-motivated fix itself as security-motivated code for T1 purposes.** ACP2E-5203's T1 ("read the blame before touching a security regex") should extend to: after landing *any* fix to a security-relevant regex/heuristic, schedule (or prompt) a follow-up adversarial pass against the new code specifically, not just against the original invariant it replaced. | Prevents a fix-of-a-fix regression shipping unreviewed until an external code reviewer catches it |
| U2 | **Verify a new Red case against the actual pre-existing invariant before trusting it as in-scope.** When authoring a Red case for a reported bypass, re-derive what the original security check was designed to catch (not what "sounds like" it should catch) before writing the assertion — a plausible-but-wrong Red case can drive a correct-but-narrow fix toward an over-broad one. | Root cause of course-correction (b)/(c) in Part II |
| U3 | **Adversarial-battery the fix before the Consensus Gateway, not just the reported cases.** Both course-corrections in this run were caught this way, not by the Gateway itself (which only checks green/red, not case coverage). Make "go one level beyond the reported/reviewer cases" an explicit step before requesting Green sign-off on security-relevant changes. | Generalizes what R2 (deepen the repro) and the two adversarial batteries in Part II did ad hoc |
| U4 | **Add the ACP2E-5203 T4 integration/MFTF smoke test now**, extended to cover this ticket's security cases as well as ACP2E-5203's false-positive cases in the same suite. Still open from the prior ticket; this regression is a second, concrete instance of the exact gap it was meant to close. | Recurring "shared/high-fan-in class, zero acceptance-level coverage" pattern (echoes ACP2E-5203 T4, ACP2E-5216 T1) |

---

# PART V — CLAUDE.md / Rules impact (status)

- ✅ **Rule A (Blast-Radius)** re-applied and re-confirmed unchanged from ACP2E-5203 rather than assumed stale-safe.
- ✅ **"Search & Augment First"** honored — all 8 new cases added to the existing `ConfigurableWYSIWYGValidatorTest` data provider, no new test file.
- ✅ **TDD Red-Green-Refactor** honored strictly, including two legitimate NOT-GREEN loop-backs — neither was allowed to bypass the "confirm Green before Consensus" discipline, and neither was treated as a stop point requiring a fresh human checkpoint (the loop-backs happened inside the existing Red→Green cycle).
- 🔎 **No rule currently exists for U1/U2/U3** (follow-up adversarial review of a just-landed security fix; validating a Red case against the true original invariant before trusting it; mandatory adversarial battery pre-Gate for security-relevant diffs). Strong candidates for a lightweight addition alongside ACP2E-5203's T1, scoped specifically to security-relevant regex/heuristic changes.
- ⬜ **T4 from ACP2E-5203** (integration/MFTF smoke coverage for `DefaultWYSIWYGValidator`) is still not implemented, and this ticket is direct evidence of the cost of leaving it open.

---

# PART VI — Prioritized Action Plan (status)

| # | Item | Addresses | Status |
|---|---|---|---|
| 1 | Land the fix (already done this run): `ConfigurableWYSIWYGValidator.php` + `ConfigurableWYSIWYGValidatorTest.php`, all gates green (74 tests, 0 regressions). | The reported security regression | ✅ **Done** |
| 2 | Apply the identical patch to any 2.4.9/backport branch carrying the ACP2E-5203 fix (mirrors ACP2E-5203's own open backport item, ACP2E-5204). | Customer-facing backport parity | ⬜ **P0, next** |
| 3 | Add integration/MFTF smoke coverage for `DefaultWYSIWYGValidator` covering both ACP2E-5203's false-positive guards and this ticket's security cases (T4 / U4). | Recurring shared-validator coverage gap, now hit twice | ⬜ |
| 4 | Propose a CLAUDE.md addition: mandatory adversarial battery against a security-relevant diff before requesting Green sign-off (U3), and re-deriving a Red case against the actual original invariant before trusting it (U2). | Prevent a repeat of this run's two course-corrections | ⬜ |
| 5 | Extend ACP2E-5203's T1 to explicitly cover "the fix itself is now security-motivated code" (U1) — schedule a follow-up adversarial pass after any security-regex fix lands. | Prevents future fix-of-a-fix regressions shipping unreviewed | ⬜ |

**Net:** the ACP2E-5351 fix is complete, tested, and validated with zero regressions across 74 tests, and closes a real XSS-filter bypass introduced by ACP2E-5203 without reopening ACP2E-5203's own false-positive. The durable output beyond the fix itself is a sharpened form of ACP2E-5203's T1 (a fix to security-motivated code is itself security-motivated code) and a concrete illustration of why a malformed Red case can drive a correct fix into over-correction — both now candidates for codification.
