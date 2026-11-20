# STOP 4 decisions — ACP2E-5597 (human, 2026-11-19)

Stage 4 ended at STOP 4 with Consensus Gateway verdict **NOT-GREEN** (reference run: `reference/`). Every check that was
run passed. Two items blocked green: the §B list coverage (17 untracked files) and the dependent-domain suites.

| # | Decision requested at STOP 4 | Human decision |
|---|---|---|
| 1 | Dependent-domain suites (Cart & Checkout, Order Management & Delivery, B2B Commerce) | **Deferred to CI.** The local evidence is accepted for this cost-only fix: the cart-rule discount Invariant, `NewsletterTest` 5/5, and the bulk-path tests (`SegmentMatchConsumerTest`, `SegmentMatchConsumerWithoutRealTimeCustomerSegmentValidationTest`, `Model/CustomerTest`). The three dependent-domain suites are **DEFERRED (human, STOP 4)** and are listed in the egress as "not run locally". |
| 2 | §B coverage: 17 untracked CE PHP files | **Outside the ticket.** They are MIG tool sources and an ACP2E-5537 retrospective artifact. The ticket's declared change set is the two files below; §B coverage is judged against that set only (profile v2.24). |
| 3 | Docblock hunk in `Newsletter.php` (loop-back 1) | **Approved.** It is part of the fix diff from now on. |
| 4 | Test isolation approach (`_resetState()` in `setUp()`) | **Accepted as is.** No bisect required. |
| 5 | Go-ahead | **Re-run the Consensus Gateway, then STEP 6 Scribe egress on green.** |

Profile changes since Stage 4 (changelog v2.24):
- §B coverage is checked against the ticket's declared change set; other dirty files are info, never blocking.
- Cross-Domain gate: a human stop-point decision may defer named dependent-domain suites. The verdict is then
  **GREEN with deferrals**, only if every check that was run passes.

## Declared change set

| File | State |
|---|---|
| `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php` | Stage 3 fix + approved docblock line (uncommitted) |
| `magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php` | Approved test, 5 methods, order-independent (untracked) |

The approved diffs are `reference/newsletter.diff` (production) and `reference/NewsletterTest-after.php.txt` (test).

Unchanged from STOP 3: MFTF §C is not applicable (no `Test/Mftf/*.xml` changed); egress goes only to the artifact
directory, and `docs/retrospectives/ACP2E-5597/` stays untouched; at most 2 Gateway loop-backs.
