#!/bin/bash
# CoreFlux Module Updater
# Pulls latest changes from all module subtree remotes

set -e

echo "=================================================="
echo "  CoreFlux Module Updater"
echo "=================================================="
echo ""

# Check if we're in a git repo
if [ ! -d ".git" ]; then
    echo "Error: Not in a git repository. Run from CoreFlux root."
    exit 1
fi

# Function to update a module
update_module() {
    local prefix=$1
    local remote=$2
    local branch=${3:-main}
    
    echo "📦 Updating $remote -> $prefix..."
    
    # Check if remote exists
    if ! git remote | grep -q "^${remote}$"; then
        echo "   ⚠️  Remote '$remote' not found. Skipping."
        return 1
    fi
    
    # Pull subtree
    if git subtree pull --prefix="$prefix" "$remote" "$branch" --squash -m "chore: Update $prefix from $remote"; then
        echo "   ✅ Updated successfully"
    else
        echo "   ❌ Failed to update"
        return 1
    fi
}

echo "Fetching all remotes..."
git fetch --all

echo ""
echo "Updating modules..."
echo ""

# Update each module
# Add new modules here as they're created
update_module "modules/people" "people-module" "main"
update_module "modules/accounting" "accounting-module" "main"

echo ""
echo "=================================================="
echo "  All modules updated!"
echo "=================================================="
