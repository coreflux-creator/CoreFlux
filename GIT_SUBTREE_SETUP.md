# CoreFlux Git Subtree Setup Guide

## Overview

This guide explains how to set up Git Subtree for CoreFlux modules. Each module lives in its own repository but is pulled into the main CoreFlux repository for unified deployment.

**Your Repositories:**
- `CoreFlux` (main) - The core application shell
- `coreflux-people` - People module repository
- `coreflux/accounts` (or `coreflux-accounts`) - Accounting module repository

---

## Architecture

```
CoreFlux (main repo)
├── core/              # Core shell, shared services
├── assets/            # Shared assets, CSS, icons
├── modules/
│   ├── people/        ← git subtree from coreflux-people
│   ├── accounting/    ← git subtree from coreflux/accounts
│   └── ...            # Future modules
├── dashboard.php
└── login.php
```

**Key Concept:** The `/modules/` directory in the main repo contains subtrees pulled from each module's repository. When you deploy, everything is in one repo.

---

## Initial Setup (One-Time)

### Step 1: Add Remotes for Each Module

From your local `CoreFlux` repository:

```bash
# Navigate to your CoreFlux repo
cd /path/to/CoreFlux

# Add the People module as a remote
git remote add people-module git@github.com:YOUR_ORG/coreflux-people.git

# Add the Accounting module as a remote
git remote add accounting-module git@github.com:YOUR_ORG/coreflux-accounts.git
```

> Replace `YOUR_ORG` with your actual GitHub organization or username.

### Step 2: Pull Modules as Subtrees

**Option A: Fresh start (module directories are empty/new)**

```bash
# Pull People module into /modules/people/
git subtree add --prefix=modules/people people-module main --squash

# Pull Accounting module into /modules/accounting/
git subtree add --prefix=modules/accounting accounting-module main --squash
```

**Option B: Existing code in module directories**

If you already have code in `/modules/people/` or `/modules/accounting/`, you have two choices:

1. **Overwrite:** Delete the existing directory, commit, then run `git subtree add`
2. **Migrate:** Push the existing code to the module repo first, then set up subtree

```bash
# Example: Migrate existing people module code to its own repo
cd /path/to/coreflux-people
cp -r /path/to/CoreFlux/modules/people/* .
git add .
git commit -m "Initial migration from CoreFlux main"
git push origin main

# Then in CoreFlux main repo, remove and re-add as subtree
cd /path/to/CoreFlux
rm -rf modules/people
git add .
git commit -m "Remove people module for subtree setup"
git subtree add --prefix=modules/people people-module main --squash
```

---

## Day-to-Day Workflow

### Working on a Module (Inside CoreFlux)

When you work on a module directly in the main CoreFlux repo:

```bash
# 1. Make your changes in modules/people/...
# 2. Commit normally
git add modules/people/
git commit -m "feat(people): Add employee search"

# 3. Push to the module's own repo (split and push)
git subtree push --prefix=modules/people people-module main
```

### Working on a Module (In Its Own Repo)

When you develop directly in the module's repo:

```bash
# 1. Work in coreflux-people repo
cd /path/to/coreflux-people
# Make changes...
git commit -m "feat: Add timesheet approval"
git push origin main

# 2. Pull changes into CoreFlux main
cd /path/to/CoreFlux
git subtree pull --prefix=modules/people people-module main --squash
```

### Pulling All Module Updates

Create a helper script `/scripts/update-modules.sh`:

```bash
#!/bin/bash
# Update all module subtrees from their remotes

echo "Updating People module..."
git subtree pull --prefix=modules/people people-module main --squash

echo "Updating Accounting module..."
git subtree pull --prefix=modules/accounting accounting-module main --squash

echo "All modules updated!"
```

---

## Recommended Module Repository Structure

Each module repo should have this structure:

```
coreflux-people/
├── manifest.php       # Module manifest (required)
├── index.php          # Module entry point
├── views/             # View files
│   ├── overview.php
│   ├── employee_list.php
│   └── ...
├── api/               # API endpoints (optional)
│   └── employees.php
├── assets/            # Module-specific assets (optional)
│   ├── css/
│   └── js/
├── includes/          # PHP includes/helpers
└── README.md          # Module documentation
```

> **Important:** Modules should NOT include `/core/` files. They reference the core via relative paths like:
> ```php
> require_once __DIR__ . '/../../core/components/ui.php';
> ```

---

## GitHub Actions Considerations

### Main Repo CI/CD

The main CoreFlux repo's GitHub Actions workflow deploys everything as one unit. No special subtree handling is needed in CI—just deploy the entire repo.

### Module Repo CI/CD

Each module repo can have its own CI for:
- Running module-specific tests
- Linting PHP code
- Triggering a webhook to update the main repo

**Example: Auto-update main repo when module changes**

In `.github/workflows/notify-main.yml` of the module repo:

```yaml
name: Notify Main Repo

on:
  push:
    branches: [main]

jobs:
  notify:
    runs-on: ubuntu-latest
    steps:
      - name: Trigger CoreFlux Update
        run: |
          curl -X POST \
            -H "Accept: application/vnd.github+json" \
            -H "Authorization: token ${{ secrets.COREFLUX_PAT }}" \
            https://api.github.com/repos/YOUR_ORG/CoreFlux/dispatches \
            -d '{"event_type":"module_updated","client_payload":{"module":"people"}}'
```

---

## Subtree Commands Reference

| Command | Description |
|---------|-------------|
| `git subtree add --prefix=path remote branch --squash` | Add a new subtree |
| `git subtree pull --prefix=path remote branch --squash` | Pull updates from module repo |
| `git subtree push --prefix=path remote branch` | Push changes to module repo |
| `git remote add name url` | Add a remote for a module |
| `git remote -v` | List all remotes |

> **--squash** condenses the module's history into a single commit in the main repo, keeping the main history cleaner.

---

## Troubleshooting

### "prefix already exists"
You're trying to `add` a subtree to a directory that already exists. Either:
- Delete the directory first, commit, then add
- Or use `git subtree merge` instead

### Merge conflicts when pulling
```bash
git subtree pull --prefix=modules/people people-module main --squash
# If conflicts occur, resolve them normally:
git mergetool
git commit
```

### "Working tree has modifications"
Commit or stash your changes before subtree operations:
```bash
git stash
git subtree pull ...
git stash pop
```

---

## Quick Start Checklist

- [ ] Add remotes for all module repos (`git remote add ...`)
- [ ] Run `git subtree add` for each module
- [ ] Test that the app works with modules pulled in
- [ ] Create update script (`scripts/update-modules.sh`)
- [ ] Document the workflow for your team

---

## Questions?

If you need help with a specific step, feel free to ask. The key commands are:
- `git subtree add` - First time setup
- `git subtree pull` - Get updates from module
- `git subtree push` - Send changes to module
