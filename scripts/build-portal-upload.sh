#!/usr/bin/env bash
#
# Build portal-upload.zip — a self-contained bundle for deploying the StockPilot
# portal to a remote or shared hosting server.
#
# Usage:
#   ./scripts/build-portal-upload.sh              # Builds frontend (npm run build) and packages zip
#   ./scripts/build-portal-upload.sh --skip-build # Reuses existing public/build without rebuilding frontend
#
set -euo pipefail

# Ensure standard system binary search paths are present
export PATH="/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin:$PATH"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORTAL="$ROOT/portal"
OUT="$ROOT/portal-upload.zip"

# Auto-detect PHP binary
PHP_BIN="${PHP_BIN:-}"
if [ -z "$PHP_BIN" ]; then
    for candidate in php php85 php84 php83; do
        if command -v "$candidate" >/dev/null 2>&1; then
            PHP_BIN="$(command -v "$candidate")"
            break
        fi
    done
fi
PHP_BIN="${PHP_BIN:-php}"

# Auto-detect Composer binary
COMPOSER_BIN="${COMPOSER_BIN:-}"
if [ -z "$COMPOSER_BIN" ]; then
    for candidate in composer composer.phar; do
        if command -v "$candidate" >/dev/null 2>&1; then
            COMPOSER_BIN="$(command -v "$candidate")"
            break
        fi
    done
fi
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

# Auto-detect NPM binary
NPM_BIN="${NPM_BIN:-}"
if [ -z "$NPM_BIN" ]; then
    if command -v npm >/dev/null 2>&1; then
        NPM_BIN="$(command -v npm)"
    fi
fi
NPM_BIN="${NPM_BIN:-npm}"

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
echo "  Source   : $PORTAL"
echo "  Output   : $OUT"
echo "  PHP      : $PHP_BIN ($("$PHP_BIN" -v 2>/dev/null | head -n1 || echo 'unknown'))"
echo "  Composer : $COMPOSER_BIN"
echo "  NPM      : $NPM_BIN"
echo ""

# ---------------------------------------------------------------------------
# 1. Build the React frontend
# ---------------------------------------------------------------------------
echo "[1/5] Checking / building React frontend..."
if [ "$SKIP_BUILD" = "1" ]; then
    echo "  -> Skipping frontend build (flag set)."
    if [ ! -f "$PORTAL/public/build/manifest.json" ]; then
        echo "  [WARNING] No manifest.json found at $PORTAL/public/build/manifest.json!"
        echo "  Run '$NPM_BIN run build' in portal/ first if assets are missing."
    else
        echo "  -> Reusing existing built assets in public/build/."
    fi
else
    echo "  -> Running $NPM_BIN run build in portal/..."
    (
        cd "$PORTAL"
        "$NPM_BIN" run build
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

if command -v "$COMPOSER_BIN" >/dev/null 2>&1; then
    echo "  -> Running composer install (--no-dev --optimize-autoloader)..."
    (
        cd "$STAGING"
        "$COMPOSER_BIN" install --no-dev --no-interaction --no-progress --optimize-autoloader
    )
fi

# Remove dev SQLite database if present
rm -f "$STAGING/database/database.sqlite"

# Clean any existing logs & framework caches
rm -f "$STAGING/storage/logs"/*.log
find "$STAGING/storage/framework" -type f ! -name '.gitkeep' -delete
rm -f "$STAGING/bootstrap/cache"/*.php

# Pre-configure production .env
PROD_KEY="base64:wM5npyDLFZDcF+SY7nuIIrtYk4EBS3hjdMlxHCN9ALE="

cat <<EOF > "$STAGING/.env"
APP_NAME=StockPilot
APP_ENV=production
APP_KEY=$PROD_KEY
APP_DEBUG=false
APP_URL=https://apigateway.live

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

# Supabase Managed PostgreSQL
DB_CONNECTION=pgsql
DB_HOST=aws-0-ap-southeast-1.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.gpbutuibewyrfgcjhjqr
DB_PASSWORD=prottoybainchod
DB_SSLMODE=require

# Sessions & Cookies
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=apigateway.live
SESSION_SECURE_COOKIE=true

# Queue & Cache
QUEUE_CONNECTION=database
CACHE_STORE=database
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

# Sync webhook execution (no supervisor worker required on shared hosting)
SP_WEBHOOK_MODE=sync

# Sanctum SPA Auth
SANCTUM_STATEFUL_DOMAINS=apigateway.live

# Mail (Resend SMTP)
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=smtp.resend.com
MAIL_PORT=587
MAIL_USERNAME=resend
MAIL_PASSWORD=re_4xjzthFB_BBmgtbPnXH1YRufgyHqKo4my
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="stockpilot@apigateway.live"
MAIL_FROM_NAME="\${APP_NAME}"
EOF

# Copy production .env to .env.example inside archive as well
cp "$STAGING/.env" "$STAGING/.env.example"

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
echo "  1. Upload and extract '$OUT' to your web root."
echo "  2. Ensure your web server document root points to: portal/public"
echo "  3. In cPanel -> Select PHP Version -> Extensions, ensure 'pdo_pgsql' is enabled."
echo "  4. Visit https://apigateway.live — database is already live on Supabase!"
echo ""
