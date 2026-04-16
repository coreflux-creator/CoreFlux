#!/bin/bash
# =====================================================
# STEP-BY-STEP: Extract Accounting Module to Submodule
# =====================================================
# 
# Run these commands on YOUR LOCAL MACHINE (not here)
# after cloning the latest version of coreflux
#
# =====================================================

# STEP 1: Create a new GitHub repo
# ---------------------------------
# Go to GitHub → New Repository → Name it "coreflux-accounting"
# DO NOT initialize with README (we already have one)
# Copy the repo URL (e.g., https://github.com/YOURORG/coreflux-accounting.git)


# STEP 2: On your local machine, clone the main repo (if not already)
# --------------------------------------------------------------------
git clone https://github.com/YOURORG/coreflux.git
cd coreflux


# STEP 3: Copy accounting module to a temporary location
# -------------------------------------------------------
cp -r modules/accounting ../coreflux-accounting-temp
cd ../coreflux-accounting-temp


# STEP 4: Initialize git and push to the new repo
# ------------------------------------------------
git init
git add .
git commit -m "Initial commit - Accounting module extracted from core"
git branch -M main
git remote add origin https://github.com/YOURORG/coreflux-accounting.git
git push -u origin main


# STEP 5: Go back to main repo and remove the accounting folder
# --------------------------------------------------------------
cd ../coreflux
rm -rf modules/accounting
git add modules/accounting
git commit -m "Remove accounting module (will add as submodule)"


# STEP 6: Add accounting as a submodule
# --------------------------------------
git submodule add https://github.com/YOURORG/coreflux-accounting.git modules/accounting
git commit -m "Add accounting module as submodule"
git push


# STEP 7: Clean up temp folder
# ----------------------------
rm -rf ../coreflux-accounting-temp


# =====================================================
# DONE! Your structure is now:
#
# coreflux/                    ← Main repo (core)
# ├── core/
# ├── modules/
# │   └── accounting/          ← Submodule → coreflux-accounting
# └── .gitmodules
#
# =====================================================


# DEPLOYMENT ON CLOUDWAYS
# -----------------------
# First time:
#   git clone --recursive https://github.com/YOURORG/coreflux.git public_html
#
# Updates:
#   cd public_html
#   git pull
#   git submodule update --init --recursive
