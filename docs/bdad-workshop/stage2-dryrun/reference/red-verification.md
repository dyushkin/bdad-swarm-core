# Red verification (Orchestrator), ACP2E-5597

Run independently by the Orchestrator after Test Author returned. This was a single re-run of the Defect method only.

**Command** (repo root `/Users/dyushkin/Projects/Adobe/Magento-1`, branch `ACP2E-5597` @ `90f60c1e8ae`):
```
T="$PWD/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php"
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$T" --filter testValidateSubscribedCustomerExaminesOnlyThatCustomerRecord
```

**Relevant PHPUnit output (verbatim):**
```
PHPUnit 12.5.14 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.12
Configuration: /Users/dyushkin/Projects/Adobe/Magento-1/dev/tests/integration/phpunit.xml

F                                                                   1 / 1 (100%)

Time: 00:02.239, Memory: 117.00 MB

There was 1 failure:

1) Magento\CustomerSegment\Model\Segment\Condition\Customer\NewsletterTest::testValidateSubscribedCustomerExaminesOnlyThatCustomerRecord
Expected only customer 17's record to be examined, got 6 rows (of 6 customers): [12, 13, 14, 15, 16, 17]
SQL: SELECT `root`.`entity_id` FROM `customer_entity` AS `root` WHERE (SELECT 1 FROM `newsletter_subscriber` AS `main` WHERE (main.customer_id = :customer_id) AND (main.subscriber_status = 1) AND (main.store_id IN ('1')) LIMIT 1)
Failed asserting that false is true.

/Users/dyushkin/Projects/Adobe/Magento-1/magento2ee/dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php:106
/Users/dyushkin/Projects/Adobe/Magento-1/vendor/bin/phpunit:122

FAILURES!
Tests: 1, Assertions: 4, Failures: 1.
```

## Verdict: RED confirmed, failing for the right reason

- **Right failure point.** The failure is the row-volume assertion at `NewsletterTest.php:106`, which is assertion 4 of 4.
  - The three earlier assertions passed: `validateCustomer` returned true, queries were captured, and the table held more than 5 customers.
  - This rules out a fixture or setup error.
- **Mechanism confirmed.** The captured SQL is the outer `customer_entity AS root` select with no `root.entity_id` filter and no `LIMIT`. This matches the Scout-confirmed override at `magento2ee/app/code/Magento/CustomerSegment/Model/Segment/Condition/Customer/Newsletter.php:198-204`. The customer filter appears only inside the `newsletter_subscriber` subquery.
- **Whole table returned.** The query returned all 6 rows in the table for 1 checked customer.
- **Fixtures self-cleaned.** Test Author's run used customer ids 3–8, and this run used ids 12–17. Both runs saw exactly 6 customers, so the earlier run's fixtures were rolled back.
- **No timing.** No wall-clock assertion is involved.

**Repository state after the run.**
- `git status --short` in the CE root shows only `?? .scout/` (pre-existing) and `?? dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/`.
- In `magento2ee`, it shows only `?? dev/tests/integration/testsuite/Magento/CustomerSegment/Model/Segment/Condition/Customer/NewsletterTest.php`.
- Nothing is staged or committed.
