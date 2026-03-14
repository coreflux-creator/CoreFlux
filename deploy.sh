#!/bin/bash

# CoreFlux Deployment Script
# Run this from your Cloudways server: ~/public_html/deploy.sh

set -e  # Exit on any error

echo "========================================"
echo "  CoreFlux Deployment Script"
echo "========================================"
echo ""

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "[1/5] Pulling latest code from Git..."
git pull origin main

echo ""
echo "[2/5] Installing frontend dependencies..."
cd frontend
yarn install --frozen-lockfile 2>/dev/null || yarn install

echo ""
echo "[3/5] Building frontend..."
yarn build

echo ""
echo "[4/5] Deploying to /app directory..."
cd ..
rm -rf app/assets app/index.html app/favicon.svg 2>/dev/null || true
cp -r frontend/dist/* app/

echo ""
echo "[5/5] Setting permissions..."
chmod -R 755 app/

echo ""
echo "========================================"
echo "  Deployment Complete!"
echo "========================================"
echo ""
echo "Test your deployment:"
echo "  - Marketing site: https://corefluxapp.com/"
echo "  - React app:      https://corefluxapp.com/app/"
echo "  - API:            https://corefluxapp.com/api/"
echo ""
