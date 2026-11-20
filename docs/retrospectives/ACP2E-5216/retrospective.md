# Post-Mortem: AI-Driven TDD Workflow - ACP2E-5216

| Field | Value |
|---|---|
| Current version | v1.0 |
| Last updated | 2026-11-09 |
| Scope | Newly uploaded product image loses role-specific alt text when its destination filename changes |
| Ticket | https://jira.corp.adobe.com/browse/ACP2E-5216 |
| Glean snapshot indexed | 2026-09-03T15:03:53Z |
| Component / priority | Catalog / Minor |
| Final verdict | **GREEN WITH CAVEATS** |
| Evidence basis | Supplied ticket snapshot, root-cause analysis, fix description, test results and swarm handoff; no independent rerun during documentation |

### Revision history

| Version | Date | Change |
|---|---|---|
| v1.0 | 2026-11-09 | Fresh egress record of the filename-remapping defect, regression coverage, validation loop-backs and remaining verification gaps. |

---

# PART I - The Fix (ACP2E-5216, fixed record)

## 0. Executive Summary

A newly uploaded product image could retain its media-gallery label but lose the role labels `image_label`, `small_image_label` and `thumbnail_label`. The failure depended on the upload receiving a different destination filename, such as when a destination file already existed. Triage reported that updating an existing image worked.

The fix reconciles the moved filename with the original-keyed new-images collection before the existing role-label lookup. A real-resource integration regression was RED with all three role labels null before the fix and GREEN afterward. The final validation verdict is **GREEN WITH CAVEATS**, not a claim of complete storefront or Enterprise coverage.

## 1. Manual Steps to Reproduce

**Preconditions:** Admin access, permission to create products and upload images, and a storefront where the saved product can be found through search. For deterministic regression reproduction, arrange a destination filename collision so the uploaded image is renamed when moved from the media tmp directory. The supplied ticket does not specify the exact collision filename or additional search-visibility configuration.

1. Admin > Catalog > Products > Add Product.
2. Set SKU, Price and Quantity.
3. In Images and Videos, upload one image. Because it is the only image, it is automatically assigned Base Image, Small Image and Thumbnail.
4. Set its Alt Text.
5. Save the product.
6. Search for the product on the storefront and inspect the search-result image.

**Expected:** The search-result image uses the supplied alt text.

**Actual:** The role-label rows `image_label`, `small_image_label` and `thumbnail_label` were never written, although the media-gallery label saved correctly. Admin and the product detail page therefore still showed the label.

**Verification boundary:** Direct inspection of the storefront-rendered `alt` attribute was not performed. The storefront image helper falls back to the product name when a role label is empty, which can mask the missing role-label persistence.

## 2. Root Cause and Implementation

In `app/code/Magento/Catalog/Model/Product/Gallery/CreateHandler.php`, a newly uploaded image is moved out of the tmp directory. Its entry remains keyed by the **original filename** in the new-images collection, while `new_file` records its moved destination.

`processMediaAttributes` updates each role's value before its label. Consequently, `processMediaAttributeLabel` receives a role attribute already holding the destination filename. Looking up that value directly in the original-keyed collection fails whenever the destination differs from the original, preventing the role-label row from being written.

The fix changes `processMediaAttributeLabel`: if the role value is a string and is not already a key in the new-images collection, match it against the entries' `new_file` values, guarded by `isset`, before the existing label lookup. Original-key precedence, non-string role values, cleared images and `no_selection` behavior remain unchanged. No new dependencies were introduced.

## 3. The Execution Journey (Red -> Green -> Refactor)

| Phase | Action and result |
|---|---|
| Intake | Scribe normalized the Catalog ticket and manual reproduction path. |
| Phase 0 | Dependency Tracer used the impact-graph tool before implementation. The supplied handoff does not include the full graph report. |
| Red | Test Author added `testSaveNewProductWithRenamedUploadPreservesRoleLabels` to the existing integration `CreateHandlerTest`; all three role labels were null before the fix. |
| Green | Patch Generator added destination-to-original resolution before the role-label lookup. The regression passed. |
| First gateway loop-back | Consensus Gateway returned NOT-GREEN for an unguarded `new_file` access and a PHPMD `CouplingBetweenObjects` violation in the test class. Both were addressed. |
| Second gateway loop-back | Gateway again returned NOT-GREEN: coupling was 13, while the ruleset requires under 13. A further correction resolved it. |
| Final gateway | Validation returned **GREEN WITH CAVEATS**, with the environmental and coverage limits recorded below. |

## 4. Regression and Validation Evidence

The new method is in `dev/tests/integration/testsuite/Magento/Catalog/Model/Product/Gallery/CreateHandlerTest.php`. It creates a brand-new product, places a freshly uploaded image in the media tmp directory, forces a destination collision, supplies a non-empty label and points all three roles at the original filename. It saves through the real resource path, reloads the product and asserts the persisted role paths, all three role labels and the gallery label.

The following results are supplied handoff evidence, not commands rerun for this document. Full command invocations were not supplied.

| Check | Reported result |
|---|---|
| `php -l` | Pass |
| PHPCS, Magento2 standard | Pass |
| PHPMD, repository ruleset | Exit 0 on both changed files |
| Configured static suite | 6 tests, 5 assertions, 1 skipped |
| New regression | 1 test, 11 assertions; RED before fix, GREEN after |
| `CreateHandlerTest` | 15 tests, 46 assertions |
| `UpdateHandlerTest` | 20 tests, 167 assertions |
| Dependent-domain Swatches image check | 6 tests, 48 assertions |

**Limits:** The CatalogStaging EE check could not run because local dump restoration failed with a mysqldump SELECT-command-denied error. This was an environmental limitation; no code defect was implicated by that failure. MFTF/acceptance coverage was not run. The configured static suite is narrow. Storefront-rendered alt text was not inspected directly.

---

# PART II - Blind Spots and Loop-Backs

The important trigger was not merely adding an image or setting a label: it was changing the filename during upload persistence. Admin and product-detail visibility were insufficient evidence because the gallery label and role labels were persisted separately.

The first gateway review caught a missing-key risk in the proposed `new_file` lookup and excessive test-class coupling. The second caught an exact threshold mistake: 13 did not satisfy a requirement of under 13. Both NOT-GREEN decisions led to corrections before the final verdict. The handoff does not describe the coupling-reduction implementation, so this record does not infer it.

---

# PART III - Retrospective in MIG Context

The supplied process records impact-graph Phase 0 and a dependent-domain Swatches image check. Those provide evidence of impact-aware validation, but not a complete dependency inventory: the detailed graph was not supplied, and the CatalogStaging EE path remained unverified.

The integration regression connects the original upload key, renamed destination, role paths and label persistence through the real save/reload boundary. It closes the demonstrated persistence gap without proving the final storefront rendering path.

---

# PART IV - How to Improve BDAD

| Improvement | Reason |
|---|---|
| Make filename transformation explicit in image-persistence reproduction briefs. | An unchanged filename does not exercise this lookup mismatch. |
| Assert both role labels and gallery labels after reloading. | A saved gallery label alone can conceal missing role-label rows. |
| Check optional array keys and exact static-analysis thresholds before gateway submission. | Both caused avoidable NOT-GREEN loop-backs. |
| Preserve environmental failures and untested presentation paths in the final verdict. | Passing persistence tests must not be presented as complete EE or storefront verification. |

---

# PART V - Rules and Process Impact

| Area | Recorded status |
|---|---|
| Impact assessment before patching | Phase 0 performed using the impact-graph tool; detailed report not supplied. |
| Behavior-driven regression | Confirmed RED before the fix and GREEN afterward through real persistence. |
| Reuse of existing tests | Regression added to the existing `CreateHandlerTest`. |
| Consensus gate | Two NOT-GREEN loop-backs resolved before final acceptance. |
| Scope control | Fix limited to role-label filename resolution; compatibility behavior preserved as described in the handoff. |
| Documentation boundary | This egress creates only this retrospective; it does not change code, tests or repository rules. |

---

# PART VI - Prioritized Action Plan

| Priority | Item | Status |
|---|---|---|
| Complete | Correct renamed-upload role-label resolution and add the integration regression. | Reported GREEN after both gateway loop-backs. |
| Complete | Run the supplied static, Catalog gallery and dependent-domain Swatches checks. | Results recorded in Part I, with one static-suite skip. |
| Follow-up | Restore an authorized local dump and run the CatalogStaging EE check. | Blocked in this run by SELECT-command-denied during dump restoration. |
| Follow-up | Perform the manual storefront reproduction and inspect the rendered `alt` attribute directly. | Not performed; product-name fallback can obscure missing role labels. |
| Follow-up | Add or run relevant MFTF/acceptance coverage. | Not run in this validation. |

## Developer Summary

Resolve renamed upload destinations back to their original new-images entries before persisting role labels. This preserves alt text for Base Image, Small Image and Thumbnail when a new product image is renamed during save, without changing original-key precedence or cleared-image handling. A collision-forcing integration regression verifies paths and both role and gallery labels after reload.

## Customer-Facing Release Notes

Fixed an issue where alt text for a newly added product image was not saved for its image roles when the uploaded file was renamed during saving.
