#!/usr/bin/env bash
# Usage: ./attach-swarm.sh /path/to/adobe-commerce-root

TARGET_AC_ROOT="$1"
SWARM_ROOT="$(pwd)"

if [ -z "$TARGET_AC_ROOT" ] || [ ! -d "$TARGET_AC_ROOT" ]; then
  echo "Error: Must provide a valid path to the Adobe Commerce root."
  echo "Usage: ./attach-swarm.sh /path/to/adobe-commerce-root"
  exit 1
fi

echo "Attaching BDAD Swarm infrastructure to: $TARGET_AC_ROOT"

# 1. Surgically inject GitHub Copilot Agents into AC's existing .github folder
mkdir -p "$TARGET_AC_ROOT/.github"
ln -sfn "$SWARM_ROOT/.github/agents" "$TARGET_AC_ROOT/.github/agents"
ln -sfn "$SWARM_ROOT/.github/hooks" "$TARGET_AC_ROOT/.github/hooks"
ln -sfn "$SWARM_ROOT/.github/copilot-instructions.md" "$TARGET_AC_ROOT/.github/copilot-instructions.md"

# 2. Inject Claude Code configs and MIG tooling
ln -sfn "$SWARM_ROOT/.claude" "$TARGET_AC_ROOT/.claude"
mkdir -p "$TARGET_AC_ROOT/dev/tools"
ln -sfn "$SWARM_ROOT/tools/impact-graph" "$TARGET_AC_ROOT/dev/tools/impact-graph"
ln -sfn "$SWARM_ROOT/setup-swarm.sh" "$TARGET_AC_ROOT/setup-swarm.sh"

# 3. Cloak the swarm from Adobe Commerce's Git tracking
# We use .git/info/exclude so we don't modify the tracked AC .gitignore
EXCLUDE_FILE="$TARGET_AC_ROOT/.git/info/exclude"

cloak_path() {
    grep -qxF "$1" "$EXCLUDE_FILE" || echo "$1" >> "$EXCLUDE_FILE"
}

cloak_path ".github/agents"
cloak_path ".github/hooks"
cloak_path ".github/copilot-instructions.md"
cloak_path ".claude"
cloak_path "dev/tools/impact-graph"
cloak_path "setup-swarm.sh"

echo "Swarm successfully attached and cloaked from Git."
