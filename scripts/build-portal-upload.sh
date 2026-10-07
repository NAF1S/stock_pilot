#!/usr/bin/env bash
#
# Build portal-upload.zip — a self-contained bundle for deploying the StockPilot
# portal to a remote or shared hosting server.
#
# It creates a clean staging copy, installs production composer dependencies,
# sets up the storage skeleton, cleans dev state, and generates portal-upload.zip.
#
# Usage:
#   ./scripts/build-portal-upload.sh              # Builds frontend (npm run build) and packages zip
#   ./scripts/build-portal-upload.sh --skip-build # Reuses existing public/build without rebuilding frontend
#
# Optional environment overrides:
#   COMPOSER_BIN="composer" ./scripts/build-portal-upload.sh
#   SKIP_BUILD=1            ./scripts/build-portal-upload.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORTAL="$ROOT/portal"
OUT="$ROOT/portal-upload.zip"

COMPOSER_BIN="${COMPOSER_BIN:-composer}"
SKIP_BUILD="${SKIP_BUILD:-0}"

if [ "${1:-}" = "--skip-build" ]; then
    SKIP_BUILD=1
fi

STAGING_PARENT="$(mktemp -d)"
STAGING="$STAGING_PARENT/portal"
cleanup() { rm -rf "$STAGING_PARENT"; }
trap cleanup EXIT

echo "=========================================================="
echo "  StockPilot Portal - Production Package Builder"
echo "=========================================================="
echo "  Source : $PORTAL"
echo "  Output : $OUT"
echo ""

# ---------------------------------------------------------------------------
# 1. Build the React frontend
# ---------------------------------------------------------------------------
echo "[1/5] Checking / building React frontend..."
if [ "$SKIP_BUILD" = "1" ]; then
    echo "  -> Skipping frontend build (flag set)."
    if [ ! -f "$PORTAL/public/build/manifest.json" ]; then
        echo "  [WARNING] No manifest.json found at $PORTAL/public/build/manifest.json!"
        echo "  Run 'npm run build' in portal/ first if assets are missing."
    else
        echo "  -> Reusing existing built assets in public/build/."
    fi
else
    echo "  -> Running npm run build in portal/..."
    (
        cd "$PORTAL"
        npm run build
    )
    echo "  -> Frontend build completed."
fi

# ---------------------------------------------------------------------------
# 2. Stage the source tree (clean copy, no node_modules or .env)
# ---------------------------------------------------------------------------
echo ""
echo "[2/5] Staging source files..."
mkdir -p "$STAGING"

# Copy core application directories (omit node_modules)
for item in app bootstrap config cron database public resources routes vendor; do
    if [ -d "$PORTAL/$item" ]; then
        cp -R "$PORTAL/$item" "$STAGING/"
    fi
done

# Copy root deployment files
for f in artisan composer.json composer.lock package.json package-lock.json \
         vite.config.js tsconfig.json .env.example; do
    if [ -e "$PORTAL/$f" ]; then
        cp "$PORTAL/$f" "$STAGING/"
    fi
done

# ---------------------------------------------------------------------------
# 3. Create fresh storage skeleton
# ---------------------------------------------------------------------------
echo ""
echo "[3/5] Setting up empty storage directory skeleton..."
mkdir -p "$STAGING/storage/app/public" \
         "$STAGING/storage/framework/cache/data" \
         "$STAGING/storage/framework/sessions" \
         "$STAGING/storage/framework/testing" \
         "$STAGING/storage/framework/views" \
         "$STAGING/storage/logs"

touch "$STAGING/storage/app/public/.gitkeep" \
      "$STAGING/storage/framework/cache/data/.gitkeep" \
      "$STAGING/storage/framework/sessions/.gitkeep" \
      "$STAGING/storage/framework/testing/.gitkeep" \
      "$STAGING/storage/framework/views/.gitkeep" \
      "$STAGING/storage/logs/.gitkeep"

# ---------------------------------------------------------------------------
# 4. Install production PHP dependencies and clean runtime state
# ---------------------------------------------------------------------------
echo ""
echo "[4/5] Preparing production vendor dependencies & cleaning dev state..."

# If composer exists, ensure staging vendor is optimized for production (--no-dev)
if command -v composer >/dev/null 2>&1 || [ -n "$COMPOSER_BIN" ]; then
    echo "  -> Running composer install (--no-dev --optimize-autoloader)..."
    (
        cd "$STAGING"
        $COMPOSER_BIN install --no-dev --no-interaction --no-progress --optimize-autoloader
    )
fi

# Remove dev SQLite database if present
rm -f "$STAGING/database/database.sqlite"

# Clean any existing logs & framework caches
rm -f "$STAGING/storage/logs"/*.log
find "$STAGING/storage/framework" -type f ! -name '.gitkeep' -delete
rm -f "$STAGING/bootstrap/cache"/*.php

# Pre-generate APP_KEY so remote host never needs 'php artisan key:generate' via terminal
PROD_KEY="base64:getpW8JHqBFHrgD7YXKcZ4ynTj/+l7BxU32rdImDL+I="
sed -i "s|^APP_KEY=.*|APP_KEY=$PROD_KEY|" "$STAGING/.env.example" 2>/dev/null || true

# Provide a ready-to-edit production .env directly in the zip
cp "$STAGING/.env.example" "$STAGING/.env"
sed -i 's/^APP_ENV=.*/APP_ENV=production/' "$STAGING/.env" 2>/dev/null || true
sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' "$STAGING/.env" 2>/dev/null || true
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=mysql/' "$STAGING/.env" 2>/dev/null || true
sed -i 's/^SP_WEBHOOK_MODE=.*/SP_WEBHOOK_MODE=sync/' "$STAGING/.env" 2>/dev/null || true

# Normalize permissions for web server (directories 755, files 644)
find "$STAGING" -type d -exec chmod 755 {} +
find "$STAGING" -type f -exec chmod 644 {} +

# ---------------------------------------------------------------------------
# 5. Create the archive (portal/ at root of archive)
# ---------------------------------------------------------------------------
echo ""
echo "[5/5] Creating zip archive..."
rm -f "$OUT"

if command -v zip >/dev/null 2>&1; then
    ( cd "$STAGING_PARENT" && zip -qr "$OUT" portal )
elif command -v python3 >/dev/null 2>&1; then
    ( cd "$STAGING_PARENT" && python3 - "$OUT" <<'PY'
import os, sys, zipfile
out, root = sys.argv[1], "portal"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for dirpath, _dirs, files in os.walk(root):
        for f in files:
            full = os.path.join(dirpath, f)
            z.write(full, os.path.join("portal", os.path.relpath(full, root)))
PY
    )
else
    echo "Error: Neither 'zip' nor 'python3' was found to create the archive." >&2
    exit 1
fi

SIZE="$(du -h "$OUT" 2>/dev/null | cut -f1 || stat -c %s "$OUT")"

echo ""
echo "=========================================================="
echo "  [SUCCESS] Portal package ready!"
echo "  Location : $OUT"
echo "  Size     : $SIZE"
echo "=========================================================="
echo "Deployment steps on remote host:"
echo "  1. Upload and extract '$OUT' on your hosting server."
echo "  2. Point your web server (Apache/Nginx) document root to: portal/public"
echo "  3. Copy .env.example to .env and configure database credentials."
echo "  4. Run 'php artisan key:generate' and 'php artisan migrate --force'."
echo ""
