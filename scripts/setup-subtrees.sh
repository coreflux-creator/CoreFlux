#!/bin/bash
# CoreFlux Module Setup
# Initial setup to add module remotes and pull as subtrees

set -e

echo "=================================================="
echo "  CoreFlux Git Subtree Initial Setup"
echo "=================================================="
echo ""

# Check if we're in a git repo
if [ ! -d ".git" ]; then
    echo "Error: Not in a git repository. Run from CoreFlux root."
    exit 1
fi

# Configuration - Update these with your actual repo URLs
GITHUB_ORG="YOUR_GITHUB_ORG"  # Change this!

# Module definitions: name|prefix|remote_name|repo_name
MODULES=(
    "People|modules/people|people-module|coreflux-people"
    "Accounting|modules/accounting|accounting-module|coreflux-accounts"
)

echo "This script will set up Git Subtree for your modules."
echo ""
echo "Before running, update the GITHUB_ORG variable in this script."
echo "Current org: $GITHUB_ORG"
echo ""
read -p "Continue? (y/n) " -n 1 -r
echo ""

if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Aborted."
    exit 1
fi

# Add remotes
echo ""
echo "Adding remotes..."
for module in "${MODULES[@]}"; do
    IFS='|' read -r name prefix remote repo <<< "$module"
    
    if git remote | grep -q "^${remote}$"; then
        echo "  ⏭️  Remote '$remote' already exists"
    else
        echo "  ➕ Adding remote: $remote -> git@github.com:${GITHUB_ORG}/${repo}.git"
        git remote add "$remote" "git@github.com:${GITHUB_ORG}/${repo}.git"
    fi
done

# Fetch remotes
echo ""
echo "Fetching remotes..."
git fetch --all

# Add subtrees
echo ""
echo "Adding subtrees..."
for module in "${MODULES[@]}"; do
    IFS='|' read -r name prefix remote repo <<< "$module"
    
    if [ -d "$prefix" ] && [ "$(ls -A $prefix)" ]; then
        echo "  ⚠️  Directory '$prefix' exists and is not empty."
        echo "      You may need to manually migrate or delete it first."
        echo "      See GIT_SUBTREE_SETUP.md for instructions."
    else
        echo "  📦 Adding subtree: $prefix from $remote"
        mkdir -p "$(dirname $prefix)"
        git subtree add --prefix="$prefix" "$remote" main --squash
    fi
done

echo ""
echo "=================================================="
echo "  Setup complete!"
echo "=================================================="
echo ""
echo "Next steps:"
echo "  1. Verify modules are pulled correctly"
echo "  2. Test the application"
echo "  3. Use scripts/update-modules.sh to pull future updates"
