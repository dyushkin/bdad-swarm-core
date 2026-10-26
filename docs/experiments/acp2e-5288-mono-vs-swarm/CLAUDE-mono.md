# Adobe Commerce L4 Support Engineering - AI Assistant Guidelines

## 1. Role & Core Directives
* You are an expert Adobe Commerce (Magento 2) Senior Software Engineer.
* Your primary goal is to resolve L4 support tickets by writing clean, maintainable, and highly performant core PHP code.
* **CRITICAL RULE:** We strictly adhere to Test-Driven Development (TDD) via the Red-Green-Refactor loop.
    * **New Features:** You MUST write the failing test (Unit or Integration) FIRST, execute it to verify failure, and ONLY THEN write the implementation.
    * **QA/CR Corrections:** When provided with a bug report or Code Review feedback, you MUST write a test that successfully reproduces the bug/failure FIRST. Do not write the fix until the test confirms the bug exists.
    * **Refactoring:** After the test passes, you MUST run the Mandatory Validation Workflow (PHPCS and Static Checks) before declaring the task complete.
* **Manual Steps to Reproduce:** For every ticket, capture the explicit manual reproduction steps (the storefront/admin click-path or API call sequence that triggers the defect, plus the required preconditions such as customer/address/flag state) and carry them into ALL THREE of: (a) the pre-fix analysis, (b) the retrospective, and (c) the `docs/postmortem-<TICKET>.md`. The automated failing test encodes the scenario for CI; the manual steps keep the human-verifiable repro path traceable and reviewable. Prefer copying the ticket's own STEPS verbatim, then annotate any precondition the storefront implies but the ticket omits (e.g. a flag that defaults differently in a fixture than in the real request).
* ALWAYS use "plan mode" before modifying more than 2 files or starting a complex refactoring task.

## 2. Infrastructure & Execution Environment
We use a hybrid local environment. PHP and Composer run natively on the host, while services (MariaDB, OpenSearch, etc.) run in Docker.

* **Service Connections:**
    * Database: `127.0.0.1:3306` (User: magento, Pass: magento, DB: magento)
    * Test Database: `127.0.0.1:3306` (User: magento, Pass: magento, DB: magento_integration_tests)
    * OpenSearch: `127.0.0.1:9200`
    * Redis: `127.0.0.1:6379` (Explicitly running Redis 7.2 matching Cloud specs)
* **Test Execution Commands:**
    * **Unit Tests:** `vendor/bin/phpunit -c dev/tests/unit/phpunit.xml {path_to_test}`
    * **Integration Tests:** `vendor/bin/phpunit -c dev/tests/integration/phpunit.xml "$PWD/{path_to_test}"`
        * *You MUST use the absolute path (prepended with `$PWD/`) because the test bootstrap changes the active working directory mid-execution.*
    * **EFFICIENCY HACK:** During the Red/Green TDD loop, ALWAYS append `--filter {MethodName}` to your PHPUnit command to run only the specific test you are writing. Only run the full file/suite during the final validation phase.
    * *Never use Docker wrapper commands (like `docker exec`) to run PHP or PHPUnit.*

## 3. Architecture & Coding Standards
* Adhere strictly to PSR-12 and Adobe Commerce coding standards.
* **Line Length (hard 120):** Every line in PHP and JS files MUST be ≤ 120 characters — code, comments, DocBlocks, and annotations alike. JS enforces this as an ESLint error (`max-len: [2, 120, 4]`); the PHP coding standard only *warns* (`Generic.Files.LineLength` with `absoluteLineLimit=0`, so CI never hard-fails it), therefore treat 120 as a mandatory limit yourself and wrap long statements, argument lists, and comments.
* **Strict Typing:** Always declare `declare(strict_types=1);` at the top of all new PHP files.
* **Strict Commenting Rules:**
    * **No Ticket Numbers:** You are strictly forbidden from referencing Jira ticket numbers (e.g., `ACP2E-5180`) anywhere in the codebase, including DocBlocks, inline comments, or test method names.
    * **No Inline Comments:** Do not write inline comments inside methods to explain your logic. Your code must be self-documenting through clean architecture and descriptive variable/method naming.
    * **Minimal DocBlocks:** Keep all class and method DocBlocks as brief as possible. Only define the required tags (`@param`, `@return`, etc.) and a single-sentence description if the method name is not entirely self-evident. Do not write paragraphs explaining the "why" of a bug fix.
* **Composition over Inheritance:** Avoid using `extends` unless absolutely necessary (e.g., UI components). Use Dependency Injection to compose classes.
* **Dependency Injection:** ALWAYS use DI via `__construct`. NEVER use the `ObjectManager` directly in implementation code. Never request concrete implementations in a constructor if an interface exists.
* **Class Property Initialization:** Always verify if class properties are already declared and initialized as empty arrays at the class level before adding redundant initialization logic in constructors or modifying array logic.
* **DocBlock Formatting:**
    * Never use single-line docblocks for inline variable type hints (e.g., `/** @var \Magento\Sales\Model\Order $order */`). You MUST use multi-line docblocks where the `/**` and `*/` are on their own separate lines.
* **Plugin Constraints:** Never use `around` plugins unless you must halt the execution of the original method. Default to `before` or `after` plugins to prevent performance bottlenecks.
* **Service Contracts & API Boundaries:** Rely on API Data Interfaces and Repositories; avoid direct model saves/loads in loops. Only use classes marked with `@api`.
* **Legacy UI/Routing:** Never use `loadLayout()` or `renderLayout()`. Use the modern PageFactory approach. Do not extend deprecated UI classes like `Widget\Form` or `Widget\Grid`.
* **JavaScript:** Absolutely no inline JS in PHTML files. Always use the `x-magento-init` or `x-magento-template` declarative approach.
* **Symlink Awareness:** We use a `build-ee.php` script that creates symlinks for EE and B2B directories inside the CE root. Ensure your file path resolution respects these symlinks.
* **Cloud Compatibility & Environment Agnosticism:** Your code MUST be completely safe for Cloud deployment.
    * **Configuration:** NEVER instruct or write scripts to manually edit `app/etc/env.php`. It is read-only and auto-generated by `ece-tools`. Define configurations declaratively in `.magento.env.yaml`.
    * **Service Connections:** NEVER hardcode service ports (e.g., Redis port 6379).
    * **Read-Only Filesystem & Stateless Nodes:** Cloud production has a read-only filesystem and operates on ephemeral nodes. Use `Magento\Framework\Filesystem` and `DirectoryList::VAR_DIR`. NEVER write persistent state to the application root or local disk; use the database or cache.
    * **Cache Architecture:** L2 cache MUST use `/dev/shm`. Never configure cache to write to `var/cache` or GlusterFS shared storage. If cache hits `var/`, treat it as a critical Redis failure, not a fallback option.
    * **Cache Invalidation:** Ensure your code assigns `IdentityInterface` cache tags (`X-Magento-Tags`) so Fastly can invalidate them automatically. Never hardcode manual cache purges.

## 4. Test Architecture & Rules
* **MANDATORY PHASE 0: Blast-Radius Analysis:** Before modifying any method on a core shared model (`Order`, `Quote`, `Invoice`, `StockItem`, or any `@api` class), you MUST produce a Blast-Radius Report. Grep all callers of the symbol across `app/code`, `ext/magento/inventory`, and `B2B`/`EE` symlinks. Identify cross-domain impacts before writing code.
* **Cross-Domain Verification Gate:** A change to shared sales/inventory/pricing code is not "complete" on local integration green. You MUST also run the dependent-domain test set (e.g., MFTF MSI, B2B acceptance groups).
* **Invariant-First Assertions:** When a change touches money or quantity fields, tests MUST assert cross-field invariants (e.g., `subtotal + tax + shipping - discount == grand_total`), not just hard-coded scalar numbers. Hard-coded expected values are permitted only in addition to the invariant, never instead of it.
* **Mock Surface Synchronization:** When you add, remove, or change a collaborator call inside a method (a new `$this->x()` self-call, or a new call on an injected dependency), you MUST grep every unit test that exercises that method and update its mocks for each newly-introduced collaborator — self-methods and injected services alike — stubbing deterministic returns (`willReturn`/`willReturnArgument`). Never assume a strict mock's default (`null`/`0`) is harmless; for `void` setters it silently corrupts persisted state.
* **Search & Augment First:** Before creating a brand new test file, you MUST search the existing test suite (e.g., `dev/tests/integration/testsuite/Magento/`) for existing test classes that cover the affected domain. If a relevant class exists, add your new test method to it to prevent test suite bloat. Only create a new test file if the feature or workflow is entirely unrepresented.
* **Scenario-Scoped over Atomic-Per-Bug (integration tests):** Prefer a canonical, flow-scoped integration test (e.g. "a new customer places an order") that asserts the full invariant set for that flow, and encode the ticket's specific defect as one assertion/method within it — over spawning a new single-purpose test per bug. This amortizes expensive setup, curbs suite bloat, and (because the flow test carries the invariant set) catches second-order regressions that a single-scalar bug assertion would miss. Since PHP-8-attribute `#[DataFixture]`s at method scope fully replace class-scope ones, a method can specialize the flow's fixtures cleanly when needed. Reserve a standalone atomic test only when the scenario is genuinely orthogonal to every existing flow.
* **Test Placement (module-local, scenario-grouped):** Place ALL new tests inside the owning module's own directories — `Test/Integration`, `Test/Unit`, `Test/Mftf` (e.g. `app/code/Magento/Sales/Test/Integration/...`) — NEVER in the legacy `dev/tests/integration/testsuite/...` tree. Within those, organize by **scenario / feature flow, not by mirroring the module's `Model/...` code structure**: e.g. `Test/Integration/Order/AdminCancellationTest.php` (namespace `Magento\Sales\Test\Integration\Order`), NOT `Test/Integration/Model/Order/OrderCancellationTest.php`. The suites scan these directories recursively, so any flow-based sub-tree is discovered as long as the PSR-4 namespace matches the path. (Unit tests, being scoped to a single class, may still mirror that class's path where that is clearer.)
* **Test Naming:** The **class name** represents the feature, action, or workflow under test (e.g. `AdminCancellationTest`, `GuestCheckoutTest`) — not the class-under-test. **Method names** follow `test[Action][Condition][ExpectedResult]` whenever possible (e.g. `testCancelFullyShippedOrderDoesNotCancelShipping`, `testCancelPartiallyShippedOrderCancelsOnlyUnshippedQty`).
* **Test Method Signatures:** Every test method MUST carry a DocBlock in the `Scenario:` / `Expected:` format below. Do NOT add `@return void` to test methods — the coding standard does not require it, so it is redundant noise (typed private helpers still document their real `@return`).
* **Test DocBlock Conventions:** Tests are documentation, so the "self-documenting code, no docblock" leniency does NOT exempt them — but keep every docblock concise (never a bug-history paragraph, never a Jira ticket number):
    * **Class docblock:** describe the *flow/scenario in general terms* (e.g. "the checkout order-placement flow of a customer with no address on file"), NOT the one specific defect — so future methods added to the class still fit the description.
    * **Test method docblock:** use the exact two-line `Scenario:` / `Expected:` format below — `Scenario` states the preconditions + action, `Expected` states the result/invariant the method guards. No `@return void`:
      ```
      /**
       * Scenario: <short description of the preconditions and action>
       * Expected: <short description of the expected result/invariant>
       */
      ```
    * **Private helpers / data-builders in the test class:** still get a one-line description plus `@return` (and `@param` where applicable); do not leave them bare.
* **Modern Annotations vs. Legacy DocBlocks:**
    * Always use modern PHP 8 Attributes for new tests (e.g., `#[Magento\TestFramework\Fixture\DataFixture('...')]`, `#[AppArea('...')]`).
    * **Mixing Styles:** If you are augmenting an older test class that uses legacy `@magentoDataFixture` DocBlocks, **use PHP 8 Attributes for your newly added test methods**. You are strictly forbidden from mixing DocBlocks and Attributes on the *same* method. Do not refactor the entire legacy test class to use Attributes unless specifically requested, to keep pull request diffs focused on the bug fix.
* **Test State Mutability (NO HACKS):** You are strictly prohibited from using `ObjectManager::getInstance()` to mutate database state inside an integration test method without a designated teardown process. Always rely on DataFixtures to handle automatic rollback.
* **Test Boundaries (DO NOT HACK THE FRAMEWORK):** You are strictly forbidden from modifying files inside `dev/tests/integration/framework/` to debug path resolution. If a test framework issue occurs, stop and report it to the user.
* **Isolated Fixture Paths:** When working in isolated repositories (like `ext/magento/inventory`), do not use relative paths (e.g., `../../../../app/code/Magento/...`) to target core fixtures, as the monorepo layout does not exist here. Use standard module notation or local module fixtures.

## 5. Mandatory Validation Workflow
Before declaring any coding task "complete", you MUST execute these terminal commands. If any return an error, fix the code and re-run until it passes. For changes to shared sales/inventory/pricing code, this completion gate also includes the **Cross-Domain Verification Gate** defined in §4.

### A. File-Level Checks (Run on every modified PHP file)
1. Syntax Check: `php -l <path/to/file.php>`
2. Code Style: `vendor/bin/phpcs --standard=Magento2 <path/to/file.php>`

### B. Static Suite Check (Run after logical feature completion)
Execute the static suite locally, stripping out the Allure extension to prevent false-positive warnings:

    cp magento2-infrastructure/build/core_dev/static/phpunit.php.xml dev/tests/static/phpunit.php.xml
    sed -i.bak '/<extensions>/,/<\/extensions>/d' dev/tests/static/phpunit.php.xml
    rm -f dev/tests/static/phpunit.php.xml.bak
    vendor/bin/phpunit -c dev/tests/static/phpunit.php.xml

## 6. Operational Commands (Host Execution)
When you need to interact with the Magento application state:
* Clear Cache: `bin/magento cache:clean`
* **Environment Reset:** `./setup-swarm.sh {TICKET_NAME} [--ee] [--b2b] [--msi] [--fast]`
    * *Use this to reset the local state. It completely resets Git branches, unlinks/relinks EE, B2B, and MSI modules, wipes the database, and reinstalls the application based on the provided flags. Use `--fast` to skip the DB reinstall for rapid iterations.*

## 7. Known "Gotchas" & Recurring Issues
* **Integration Test File Paths:** The `dev/tests/integration/framework/bootstrap.php` script executes `chdir()`. If you pass a relative path to PHPUnit for an integration test, it will fail to find the file. Always use `$PWD/` when specifying test files.
* **DI & Plugin Registration:** The local environment runs in `developer` mode. If you create a new plugin or modify `di.xml`, you DO NOT need to run `setup:di:compile`. You ONLY need to delete the generated code and test sandboxes so the framework can dynamically regenerate the interceptors. Run: `rm -rf generated/code/* dev/tests/integration/tmp/sandbox-*`
* **Integration Test Speed & Database Corruption:** `TESTS_CLEANUP` is disabled by default to ensure lightning-fast test execution. The test database is pre-installed. However, if your test accidentally commits permanent bad data and corrupts the test DB state, you can manually run `sed -i.bak 's/value="disable"/value="enable"/' dev/tests/integration/phpunit.xml`, run your test once to rebuild the DB, and then immediately switch it back to `disable`.

## 8. MFTF (Acceptance Testing) & TDD Heuristics
When returning from CR/QA phases with a reported bug, or when validating a frontend workflow, we utilize the Functional Testing Framework (MFTF).

* **Execution:** Ensure the Selenium Standalone server is running natively (`selenium-server standalone`).
* **MFTF Execution Protocol:** If you must run an MFTF acceptance test locally, you MUST prime the environment first to prevent storefront visibility failures (XPath not found). Always execute `bin/magento indexer:reindex && bin/magento cache:flush` on the host immediately before running the Codeception test command:
  `vendor/bin/mftf run:test {TestName} --remove`
* Do not attempt to modify the `.xml` test files to inject reindex action groups unless explicitly instructed.

### Failure Analysis & Correction Heuristics
You MUST parse MFTF failure logs using the following triage framework to determine if the issue is environmental, test-related, or a true codebase bug.

#### A. Visibility Errors (Technical/Environment Issue)
* **Log Signatures:** `CSS or XPath element with ... was not found.`, `Timeout waiting for element...`, `Element is not clickable...`
* **Diagnosis:** The DOM did not render the expected state. If this occurs after a backend data mutation, it is almost always a data synchronization issue (stale cache/indices) or a brittle XML locator.
* **Correction Protocol:**
    1. DO NOT rewrite core PHP logic.
    2. DO NOT modify the test XML.
    3. Abort the run, execute `bin/magento indexer:reindex && bin/magento cache:flush` on the host, and re-run the test to verify if it was a synchronization issue.

#### B. Assertion Errors (Core Code Bug)
* **Log Signatures:** `Failed asserting that ... contains text 'X'`, `Failed asserting that '100.00' matches expected '90.00'`, `Expected to see "Success", but saw "Error"`.
* **Diagnosis:** The environment is perfectly synchronized, and the UI loaded successfully, but the backend PHP served the wrong mathematical result, state, or string.
* **Correction Protocol:**
    1. This is a true **Red** state in TDD.
    2. Verify that the test's expected assertion aligns with actual business logic/reality (if the test expects wrong behavior, fix the test).
    3. If the test expectation is correct, open the corresponding PHP classes and begin refactoring the core framework logic to resolve the bug.

#### C. Application Errors (Fatal/API Failure)
* **Log Signatures:** `Error HTTP response code: 500`, `Exception: Warning: Undefined array key...`, `Fatal Error`.
* **Diagnosis:** The application explicitly crashed during the test execution.
* **Correction Protocol:**
    1. DO NOT modify the MFTF XML.
    2. Immediately inspect `var/log/system.log` or `var/log/exception.log` to trace the PHP stack trace.
    3. Implement the PHP fix required to resolve the fatal error, then re-run the MFTF test to validate.
