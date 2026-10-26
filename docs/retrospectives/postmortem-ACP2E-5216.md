# Post-Mortem: AI-Driven TDD Workflow — ACP2E-5216

*Principal AI Engineer assessment. Objective: harden this into a bulletproof, highly autonomous Behavior Driven Agentic Development (BDAD) environment for Adobe Commerce core.*

| | |
|---|---|
| **Current version** | v1.0 |
| **Last updated** | 2026-08-12 |
| **Scope** | ACP2E-5216 (catalog media-gallery alt-text persistence) + a regression-triage exercise on 3 MFTF file-option tests |
| **Status legend** | ✅ done · 🟡 partial · ⬜ not started · 🔎 resolved by investigation |

### Revision history
| Version | Date | Change |
|---|---|---|
| v1.0 | 2026-08-12 | Initial record: the alt-text fix journey (clean Red→Green), the regression-triage of 3 file-option MFTF failures, root-cause + attribution, Allure framework-noise finding, MIG/BDAD improvements, action plan. |

> **How to read this doc:** §I is the fixed record of the ACP2E-5216 fix itself. §II is the regression-triage exercise (the file-option MFTF failures) — *the main learning content of this ticket*. §III–§VI carry MIG context, framework-noise handling, and the action plan. Bump the version + add a revision row whenever status changes. Companion to [`postmortem-ACP2E-5180.md`](ACP2E-5180/retrospective.md).

---

# PART I — The Fix (ACP2E-5216, fixed record)

## 0. Executive Summary

Two things happened under this ticket:

1. **The fix shipped clean.** Alt text (label) was silently dropped for a newly added product image whenever the uploaded file was **renamed on save due to a filename collision**. Root cause: in `CreateHandler::processMediaAttributes()`, `processMediaAttribute()` remaps the role attribute (`image`/`small_image`/`thumbnail`) to the moved `new_file` **before** `processMediaAttributeLabel()` runs, and the label lookup keyed by the *original* file name then misses. Fixed with a surgical key-resolution helper. Full RED→GREEN via an augmented integration test; all validation gates + the alt-text MFTF acceptance test green.
2. **A regression-triage request exposed a pre-existing core regression** in a *different* subsystem (custom-option file uploads). The headline result: **our change was mechanically exonerated**, and the true culprit was an already-merged library swap. That triage is the valuable part of this ticket and is recorded in §II.

## 1. The Execution Journey (Red → Green → Refactor)

| Phase | Action | Outcome |
|---|---|---|
| Phase 0 — Blast radius | `CreateHandler` is `@api`; only external hook is `ProductVideo`'s plugin (unrelated to labels); no EE/B2B/MSI override. Confirmed the change surface is a `private` method. | ✅ Contained |
| R1 — Reproduce | Augmented `CreateHandlerTest` with a brand-new-product test. First pass **green** (no collision) — proved the happy path works. | ✅ Correct negative control |
| R2 — True Red | Forced a filename collision (pre-seeded target media file) → label persisted as `null`, exactly matching the customer's DB symptom (`*_label` rows missing while `image`/`small_image`/`thumbnail` present). | ❌→ reproduction locked |
| R3 — Green | Added `resolveNewImageKey()` to match the role value by original key **or** its `new_file`; label persists for all roles. | ✅ |
| R4 — Validate | `php -l` + PHPCS; `CreateHandlerTest` (15) + `UpdateHandlerTest` (20); related gallery integration (24); static suite (fixed a coupling violation the new test introduced); MFTF `AdminProductImagesDefaultScopeInheritanceTest` (66 assertions). | ✅ All green |

**No blind spots in the fix itself** — the §4 discipline (Blast-Radius, augment-existing-test, invariant/DB-level assertion, static gate) held. The learning is in §II.

---

# PART II — Regression Triage Exercise (the main learning)

**Request:** three MFTF tests started failing in Jenkins and reproduce locally; determine whether *code changes* are the cause.

- `StoreFrontUseEmptyFileAsACustomOptionOfFileTypeTest` — `no such element: //div[@data-ui-id='message-error']/div`
- `StorefrontConfigurableProductWithFileCustomOptionTest` — "The file 'lorem_ipsum.docx' … has an invalid extension"
- `StorefrontSwatchProductWithFileCustomOptionTest` — same as above

## 2a. Verdict — our change exonerated (mechanically, not by assertion)

| | ACP2E-5216 (ours) | The failing tests |
|---|---|---|
| Subsystem | Product **media gallery** | Product **custom options (file type)** |
| Class | `Gallery/CreateHandler.php` | `Option/Type/File/ValidatorFile.php` + `Framework/File/Http.php` |
| Trigger | Save product with image + label | Storefront **add-to-cart** with uploaded file |

Disjoint code paths, zero overlap. Our commit touches only `CreateHandler.php` + its test — it cannot emit a "file is empty" / "invalid extension" symptom. **Exoneration was proven by code-path enumeration, not by "our tests are green."** (This is Rule A applied *defensively* — the same blast-radius discipline that finds what you broke also proves what you didn't.)

## 2b. Root cause (empirically confirmed on test #1)

The failing step waits for **"The file is empty. Select another file and try again."**, which never appears — the empty file is **accepted** and added to cart. Mechanism:

```
ValidatorFile::validate()
  └─ $upload = httpFactory->create()  ──▶ Magento\Framework\File\Http   ← REWRITTEN by AC-13573
        └─ getFileInfo() returns raw $_FILES 'tmp_name'  =  OS temp dir
                                    (php upload_tmp_dir EMPTY → /var/folders/.../T)
  └─ empty-file guard (ValidatorFile.php:199-208) runs only if:
        $sysTmpDir->isReadable( $sysTmpDir->getRelativePath( tmp_name ) )
        with $sysTmpDir = <magento>/var/tmp  → relative path ESCAPES root → isReadable = FALSE
  └─ guard SKIPPED → 0-byte file accepted → no error → test fails
```

The empty-file guard has been unchanged since 2022; what changed is the **object under it**. `AC-13573` (laminas/laminas-file removal) rewrote `Framework\File\Http` and dropped the `Rename` filter, so the validated file now stays in the OS temp dir instead of a Magento-managed path — bypassing a `var/tmp`-relative readability check. It only manifests when `upload_tmp_dir` ≠ Magento `var/tmp`, which is the default on local **and** Jenkins → both reproduce.

## 2c. Attribution

| Commit | Date | Relationship |
|---|---|---|
| `AC-13573` — replace laminas/laminas-file (rewrote `Framework\File\Http`) | 2025-02-20 | **Behavior-changing commit** for the failing upload path |
| `AC-16153` — product options improvement (`File.php`/`ValidatorInfo.php`, new `ProductOptionValidator`) | 2026-03-09 | Most recent change in the same area; in the regression window |
| `ACP2E-5216` (ours) | 2026-08-12 | **Unrelated subsystem** |

**MFTF data is not the cause** — allowed extensions are still correct (`png, jpg, gif` / `txt`); this is genuine validation behavior, not a test-data drift.

**Confidence:** test #1 confirmed empirically to the failing step. Tests #2/#3 share the `ValidatorFile`/`Http` path and their symptom (invalid file accepted → the "invalid extension" `<see>` not satisfied) is consistent with the same regression; they were **not** run to the failing step in this exercise (each ~8 min) — a follow-up run is queued.

## 2d. The end-of-run exception (Allure line 30) — framework noise, not root cause

`AllureHelper::addAttachmentToCurrentStep()` does `serialize()` on the Selenium WebDriver exception (non-serializable curl/closure), the serialize throws, and line 30 re-throws — reformatting the real failure into a top-level ERROR. It **masks**, it doesn't cause.

- **Do not patch** `vendor/…/magento2-functional-testing-framework` (CLAUDE.md §4 "do not hack the framework"; Composer would overwrite). File upstream: serialize a `Throwable` safely (`(string)$data`).
- **Real resolution:** fix the underlying validation regression — then the exception path is never entered.
- **Interim, to see the clean error:** run with `--debug` or read `dev/tests/acceptance/tests/_output/*.fail.html` / `.txt`.

---

# PART III — Retrospective in MIG context

- **What MIG would have caught:** a library swap (laminas/laminas-file) is a **high-blast-radius framework change**. A Cross-Domain Verification Gate running the file-option MFTF group (the three tests above + ImportExport upload) against the merged branch would have flagged `AC-13573`/`AC-16153` pre-merge. MIG isn't wired, so that blast radius went un-exercised — the *same class of gap* as ACP2E-5180 L15, now seen from the "framework primitive" angle rather than the "cross-domain plugin/observer" angle.
- **Environment-parity gap (new):** the regression is invisible on any box where `upload_tmp_dir` happens to be Magento `var/tmp`, and visible everywhere else. Fast loops that don't pin upload env can flip green/red on identical code — a determinism hole MIG-adjacent tooling must close.

---

# PART IV — How to improve BDAD (from this ticket)

| # | Improvement | Addresses |
|---|---|---|
| T1 | **Phase-0 blast radius must include library/framework swaps.** When a PR removes a package or rewrites a `lib/internal` primitive, enumerate every consumer (custom options, import/export, product video, theme upload) and require their integration+MFTF coverage in the gate — not just the PR's own tests. | The AC-13573 miss |
| T2 | **Push validation coverage down to integration level.** An integration test driving `ValidatorFile::validate()` with a 0-byte and a wrong-extension upload gives a deterministic RED independent of `upload_tmp_dir` and MFTF/Selenium. | Env-sensitive MFTF |
| T3 | **Invariant-first for validators:** assert "invalid input ⇒ rejected" decoupled from temp-path plumbing, so a plumbing change can't silently disable a security check. | Rule C, generalized to validators |
| T4 | **Pin `upload_tmp_dir` to Magento `var/tmp`** in the test container so SYS_TMP-relative code behaves like prod/CI. | Environment parity |
| T5 | **Known-framework-noise triage rule** (extend CLAUDE.md §8C): the AllureHelper line-30 `serialize()` re-throw = "framework masking — read the `.fail` artifact for the true error," not an application crash. | Faster triage |
| T6 | **Defensive-exoneration as a first-class triage output:** when asked "did we break X," produce the disjoint-code-path proof explicitly (like §2a) instead of relying on "our suite is green." | Trust in the workflow |

---

# PART V — CLAUDE.md / Rules impact (status)

- ✅ **Rule A (Blast-Radius)** proved useful *defensively* to exonerate our change and *offensively* to attribute the real regression.
- 🟡 **§8C triage** — proposed extension (T5) to name the AllureHelper serialize masking as known framework noise; not yet written into CLAUDE.md.
- ⬜ **Validator invariant guidance** (T3) — Rule C currently scoped to "money or quantity"; propose widening to "security/validation decisions."
- ⬜ **Env-parity note** (T4) — add `upload_tmp_dir` pin to the infra/`setup-swarm.sh` runbook.

---

# PART VI — Prioritized Action Plan (status)

| # | Item | Addresses | Status |
|---|---|---|---|
| 1 | **File a separate ticket** for the file-option upload regression (empty + wrong-extension accepted). Do **not** fold into ACP2E-5216. | Core regression | ⬜ **P0, next** |
| 2 | Proposed fix direction for that ticket: replace the SYS_TMP-relative readability gate with `$fileInfo['size'] === 0` (or absolute `is_readable()`+`filesize()` on `tmp_name`); verify new `Http::isValid()` still runs `Extension`/`FilesSize` for the docx case. Lead with an integration-level RED (T2). | Core regression | ⬜ |
| 3 | Run tests #2/#3 to the failing step to lock docx attribution. | Confidence | 🟡 queued |
| 4 | MIG Phase-0 to include framework-swap consumers + MFTF file-option group (T1). | Prevent recurrence | ⬜ (tracks ACP2E-5180 L15) |
| 5 | CLAUDE.md edits: §8C framework-noise rule (T5), validator invariant (T3), `upload_tmp_dir` pin (T4). | Codify learnings | ⬜ |
| 6 | Upstream MFTF bug: AllureHelper serialize a `Throwable` safely. | Remove masking noise | ⬜ |

**Net:** the ACP2E-5216 fix is complete and validated. The triage's durable output is a **proven exoneration method** and a **located, attributed core regression** that belongs to `AC-13573`/`AC-16153`, to be fixed under its own ticket with an integration-level RED first.
