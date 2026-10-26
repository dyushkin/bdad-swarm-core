#!/bin/bash

# Default configurations
TICKET_NAME=""
WITH_EE=0
WITH_B2B=0
WITH_MSI=0
FAST_MODE=0

# Parse command-line arguments
while [[ "$#" -gt 0 ]]; do
    case $1 in
        --ee) WITH_EE=1 ;;
        --b2b) WITH_B2B=1 ;;
        --msi) WITH_MSI=1 ;;
        --fast) FAST_MODE=1 ;;
        -*) echo "❌ Error: Unknown parameter: $1"; exit 1 ;;
        *)
            if [ -z "$TICKET_NAME" ]; then
                TICKET_NAME=$1
            else
                echo "❌ Error: Multiple ticket names provided."
                exit 1
            fi
            ;;
    esac
    shift
done

# Validation
if [ -z "$TICKET_NAME" ]; then
  echo "❌ Error: Please provide a ticket branch name."
  echo "Usage: ./setup-swarm.sh TICKET-123 [--ee] [--b2b] [--msi] [--fast]"
  exit 1
fi

if [ "$WITH_B2B" -eq 1 ] && [ "$WITH_EE" -eq 0 ]; then
    echo "❌ Error: B2B requires EE. Please include the --ee flag when using --b2b."
    exit 1
fi

echo "🚀 Pre-flight check: Verifying branch synchronization..."

check_branch() {
    local DIR=$1
    if [ -d "$DIR" ]; then
        (
            cd "$DIR" || exit 0
            if git show-ref --verify --quiet refs/heads/"$TICKET_NAME"; then
                echo 1
            else
                echo 0
            fi
        )
    else
        echo -1
    fi
}

# Build a list of only the required repositories based on flags
REQUIRED_REPOS=(".")
[ "$WITH_EE" -eq 1 ] && REQUIRED_REPOS+=("magento2ee")
[ "$WITH_B2B" -eq 1 ] && REQUIRED_REPOS+=("magento2b2b")
[ "$WITH_MSI" -eq 1 ] && REQUIRED_REPOS+=("ext/magento/inventory")

# Check synchronization across requested repositories
BRANCH_EXISTS_COUNT=0
for repo in "${REQUIRED_REPOS[@]}"; do
    HAS_BRANCH=$(check_branch "$repo")
    if [ "$HAS_BRANCH" -eq 1 ]; then
        ((BRANCH_EXISTS_COUNT++))
    fi
done

if [ "$BRANCH_EXISTS_COUNT" -gt 0 ] && [ "$BRANCH_EXISTS_COUNT" -lt "${#REQUIRED_REPOS[@]}" ]; then
    echo "❌ Error: Branch '$TICKET_NAME' exists in some requested repositories but is missing in others. Repositories must be synchronized."
    exit 1
fi

echo "✅ Branch check passed. Proceeding with environment setup..."

# ---------------------------------------------------------
# SERVICE READINESS HELPERS
# ---------------------------------------------------------
ensure_docker_services() {
    echo "🐳 Ensuring Docker infrastructure is running..."
    if ! docker info >/dev/null 2>&1; then
        echo "   Docker daemon not reachable — attempting to start Docker Desktop..."
        open -a Docker >/dev/null 2>&1 || true
        local retries=60
        while [ "$retries" -gt 0 ] && ! docker info >/dev/null 2>&1; do
            sleep 2
            retries=$((retries - 1))
        done
        if ! docker info >/dev/null 2>&1; then
            echo "❌ Error: Docker daemon did not start in time. Start Docker Desktop manually and re-run."
            exit 1
        fi
    fi
    # No -f: picks up the default docker-compose.yml (persistent), never the opt-in tmpfs override.
    docker compose up -d
}

wait_for_db() {
    ensure_docker_services
    echo "⏳ Waiting for MariaDB (127.0.0.1:3306)..."
    php -r '
    $r = 30;
    while ($r-- > 0) {
        try { new PDO("mysql:host=127.0.0.1;port=3306", "magento", "magento"); exit(0); }
        catch (Exception $e) { sleep(1); }
    }
    fwrite(STDERR, "timeout" . PHP_EOL);
    exit(1);
    ' || { echo "❌ Error: MariaDB connection timeout at 127.0.0.1:3306."; exit 1; }
}

wait_for_opensearch() {
    echo "⏳ Waiting for OpenSearch (127.0.0.1:9200)..."
    local retries=30
    while [ "$retries" -gt 0 ]; do
        if curl -fsS "http://127.0.0.1:9200/_cluster/health" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
        retries=$((retries - 1))
    done
    echo "❌ Error: OpenSearch not reachable at 127.0.0.1:9200."
    exit 1
}

# ---------------------------------------------------------
# PHASE 1: FILE SYSTEM & GIT SYNCHRONIZATION
# ---------------------------------------------------------
echo "🧹 1. Resetting environment (Unlinking previous modules)..."
if [ -f "magento2ee/dev/tools/build-ee.php" ]; then
    php magento2ee/dev/tools/build-ee.php --command=unlink --exclude=true --ee-source="magento2ee" --ce-source="." 2>/dev/null || true
    if [ -d "magento2b2b" ]; then
        php magento2ee/dev/tools/build-ee.php --command=unlink --exclude=true --ee-source="magento2b2b" --ce-source="." 2>/dev/null || true
    fi
fi

echo "🌿 2. Managing git branches for '$TICKET_NAME'..."

sync_repo() {
    local DIR=$1
    local BASE_BRANCH=$2
    if [ -d "$DIR" ]; then
        cd "$DIR" || return
        echo "   -> Syncing repository: $DIR (Base: $BASE_BRANCH)"

        git fetch origin

        if git show-ref --verify --quiet refs/heads/"$TICKET_NAME"; then
            echo "   -> Local branch found. Checking out without pulling from origin."
            git checkout "$TICKET_NAME"
        else
            echo "   -> New ticket detected. Creating branch from origin/$BASE_BRANCH."
            git checkout "$BASE_BRANCH"
            git reset --hard origin/"$BASE_BRANCH"
            git checkout -b "$TICKET_NAME"
        fi
        cd - > /dev/null || return
    fi
}

sync_repo "." "2.4-develop"
[ "$WITH_EE" -eq 1 ] && sync_repo "magento2ee" "2.4-develop"
[ "$WITH_B2B" -eq 1 ] && sync_repo "magento2b2b" "develop"
[ "$WITH_MSI" -eq 1 ] && sync_repo "ext/magento/inventory" "develop"

# ---------------------------------------------------------
# PHASE 2: DEPENDENCIES & SYMLINKS
# ---------------------------------------------------------
echo "📦 3. Managing Composer dependencies..."
composer config --global process-timeout 2000

if [ "$WITH_EE" -eq 1 ] || [ "$WITH_B2B" -eq 1 ]; then
    cp magento2ee/composer.json .
    cp magento2ee/composer.lock . 2>/dev/null || true
fi

if [ "$WITH_MSI" -eq 1 ]; then
    composer config minimum-stability dev
    composer config repositories.ext path "./ext/*/*/*"

    INVENTORY_META="ext/magento/inventory/_metapackage/composer.json"
    if [ -f "$INVENTORY_META" ]; then
        sed -i.bak '/magento\/inventory-composer-installer/d' "$INVENTORY_META"
        rm -f "${INVENTORY_META}.bak"
    fi
fi

composer install

if [ "$WITH_MSI" -eq 1 ]; then
    composer require magento/inventory-metapackage
fi

git checkout composer.json composer.lock

echo "🔗 4. Linking requested modules..."
if [ "$WITH_EE" -eq 1 ]; then
    php magento2ee/dev/tools/build-ee.php --command=link --exclude=true --ee-source="magento2ee" --ce-source="."
fi
if [ "$WITH_B2B" -eq 1 ]; then
    php magento2ee/dev/tools/build-ee.php --command=link --exclude=true --ee-source="magento2b2b" --ce-source="."
fi

# ---------------------------------------------------------
# FAST MODE BYPASS
# ---------------------------------------------------------
if [ "$FAST_MODE" -eq 1 ]; then
    echo "⚡ Fast mode engaged. Skipping database wipes and Magento reinstall."
    wait_for_db
    echo "🗑️  Cleaning basic cache..."
    rm -rf var/cache/* var/page_cache/* var/view_preprocessed/* pub/static/*
    rm -rf generated/code/* generated/metadata/*
    bin/magento cache:flush
    echo "✅ Fast environment setup complete for $TICKET_NAME!"
    exit 0
fi

# ---------------------------------------------------------
# PHASE 3: MAIN APPLICATION SETUP
# ---------------------------------------------------------
echo "🗑️ 5. Cleaning cache and config files..."
rm -rf var/cache/* var/page_cache/* var/view_preprocessed/* pub/static/*
rm -rf generated/code/* generated/metadata/*
rm -f app/etc/config.php app/etc/env.php

echo "⏳ 6. Waiting for infrastructure services to be ready..."
wait_for_db
wait_for_opensearch

echo "⚙️ 7. Running Magento Setup (with database cleanup)..."
bin/magento setup:install \
  --base-url="http://magento1.com/" \
  --backend-frontname="admin" \
  --cleanup-database \
  --db-host="127.0.0.1" \
  --db-name="magento" \
  --db-user="magento" \
  --db-password="magento" \
  --search-engine="opensearch" \
  --opensearch-host="127.0.0.1" \
  --opensearch-port="9200" \
  --admin-firstname="Admin" \
  --admin-lastname="User" \
  --admin-email="admin@example.com" \
  --admin-user="admin" \
  --admin-password="123123qq" \
  --language="en_US" \
  --currency="USD" \
  --timezone="UTC" \
  --use-rewrites="1"

echo "🔧 8. Applying Developer & MFTF Configurations..."
bin/magento config:set admin/security/admin_account_sharing 1
bin/magento config:set admin/security/use_form_key 0
bin/magento config:set admin/security/session_lifetime 31536000
bin/magento config:set web/seo/use_rewrites 1
bin/magento config:set cms/wysiwyg/enabled disabled

# Disable 2FA to allow basic MFTF admin logins without authenticators
bin/magento module:disable Magento_TwoFactorAuth

bin/magento deploy:mode:set developer
bin/magento cache:flush

# ---------------------------------------------------------
# PHASE 4: INTEGRATION TEST PRIMING
# ---------------------------------------------------------
echo "🧹 9. Clearing Integration Test Sandbox..."
rm -rf dev/tests/integration/tmp/sandbox-*
rm -rf dev/tests/integration/tmp/var/*
rm -rf dev/tests/integration/tmp/generated/*

# Template-DB cloning is handled natively by the integration framework: a clean install stores
# setup_dump_magento_integration_tests.sql under dev/tests/integration/tmp/.../var and later runs
# (TESTS_CLEANUP=disable) restore from it. Clearing the sandbox in step 9 keeps that dump fresh.
echo "🏗️ 10. Priming Integration Test Database..."
php -r "
try {
    \$db = new PDO('mysql:host=127.0.0.1', 'magento', 'magento');
    \$db->exec('DROP DATABASE IF EXISTS magento_integration_tests;');
    \$db->exec('CREATE DATABASE magento_integration_tests;');
} catch (Exception \$e) {
    echo 'DB Reset Failed: ' . \$e->getMessage();
    exit(1);
}
"

cp dev/tests/integration/phpunit.xml.dist dev/tests/integration/phpunit.xml
# The framework reads TESTS_CLEANUP via getAsBoolean(): only the literal "enabled" is truthy.
# Prime with cleanup ON (clean install + stored dump), then switch to "disabled" so subsequent
# test runs reuse the primed DB (lightning-fast loop).
sed -i.bak 's/name="TESTS_CLEANUP" value="disabled"/name="TESTS_CLEANUP" value="enabled"/' dev/tests/integration/phpunit.xml

vendor/bin/phpunit -c dev/tests/integration/phpunit.xml --testsuite "Memory Usage Tests" > /dev/null 2>&1

sed -i.bak 's/name="TESTS_CLEANUP" value="enabled"/name="TESTS_CLEANUP" value="disabled"/' dev/tests/integration/phpunit.xml
rm -f dev/tests/integration/phpunit.xml.bak

# ---------------------------------------------------------
# PHASE 5: MFTF (ACCEPTANCE TEST) PRIMING
# ---------------------------------------------------------
echo "🧪 11. Setting up Functional Testing Framework (MFTF)..."

# Force indexers into realtime mode for synchronous UI testing
bin/magento indexer:set-mode realtime

# Reindex so storefront/catalog data is queryable immediately (prevents product-not-visible flakes)
bin/magento indexer:reindex

# MFTF 6.0.1's CleanProjectCommand passes a Finder SplFileInfo to Symfony\Filesystem::remove(),
# which Symfony 7 rejects (TypeError), crashing `build:project` whenever leftover generated suite
# group classes exist from a prior build. Pre-remove them (every *.php there is generated, per the
# framework's own .gitignore) so the clean step has nothing to iterate. placeholder.txt is kept.
MFTF_GROUP_DIR="vendor/magento/magento2-functional-testing-framework/src/Magento/FunctionalTestingFramework/Group"
[ -d "$MFTF_GROUP_DIR" ] && rm -f "$MFTF_GROUP_DIR"/*.php

# Build the project (This inherently runs MFTF reset/CleanProjectCommand first)
if ! vendor/bin/mftf build:project; then
    echo "❌ Error: 'mftf build:project' failed. Acceptance tests will not be runnable."
    exit 1
fi

# build:project regenerates .env from the framework's .env.example, which ships
# SELENIUM_CLOSE_ALL_SESSIONS=true. That triggers RemoteWebDriver::getAllSessions()
# (GET /wd/hub/sessions) in suite teardown — a JSONWire endpoint removed in Selenium 4,
# which errors as "Unable to find handler for (GET) /wd/hub/sessions". Disable it so suites
# (e.g. SharedCatalog) tear down cleanly against Selenium 4+.
if [ -f "dev/tests/acceptance/.env" ]; then
    sed -i.bak 's/^SELENIUM_CLOSE_ALL_SESSIONS=true/#SELENIUM_CLOSE_ALL_SESSIONS=true/' dev/tests/acceptance/.env
    rm -f dev/tests/acceptance/.env.bak
fi

# Ensure CLI command routing is enabled via htaccess
if [ -f "dev/tests/acceptance/.htaccess.sample" ]; then
    cp dev/tests/acceptance/.htaccess.sample dev/tests/acceptance/.htaccess
fi

# IMPORTANT: Write the credentials file AFTER build:project, otherwise the build command deletes it
cat << 'EOF' > dev/tests/acceptance/.credentials
magento/MAGENTO_ADMIN_PASSWORD=123123qq
EOF

# Disable WYSIWYG to ensure Selenium can interact with simple text areas
bin/magento config:set cms/wysiwyg/enabled disabled

# Flush configuration caches so MFTF picks up the security and WYSIWYG changes
bin/magento cache:clean config full_page

# Report Selenium readiness (runs natively; only required for `mftf run:test`, not build)
if curl -fsS "http://127.0.0.1:4444/wd/hub/status" >/dev/null 2>&1; then
    echo "🧭 Selenium is warm at 127.0.0.1:4444."
else
    echo "🧭 Selenium not detected at 127.0.0.1:4444. Start it before 'mftf run:test':"
    echo "   selenium-server standalone"
fi

echo "✅ Environment setup complete for $TICKET_NAME!"

