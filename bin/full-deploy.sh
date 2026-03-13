#!/bin/bash
# apis-hub · Full Deployment Orchestrator
# Designed for any environment (Linux, CI/CD, cloud).
# Only requires: docker, docker compose, bash.
set -e

echo "⚒  Starting Full Deployment"
echo ""

# ── Step 1: Install Composer dependencies ────────────────────────────────────
# If vendor/ is missing or outdated, install it via the official Composer image.
if [ ! -f "vendor/autoload.php" ]; then
    echo "📦 [1/4] Composer dependencies not found. Installing..."
    docker run --rm \
        -v "$(pwd):/app" \
        -w /app \
        composer:latest \
        install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader
    echo "✔ Dependencies installed."
else
    echo "📦 [1/4] Composer dependencies already present. Skipping install."
fi

# ── Step 2: Refresh Instances from rules ──────────────────────────────────────
echo ""
echo "🔄 [2/4] Refreshing instances configuration from rules..."
docker run --rm \
    -v "$(pwd):/app" \
    -w /app \
    php:8.3-cli \
    php bin/cli.php app:refresh-instances
echo "✔ Instances refreshed."

# ── Step 3: Generate docker-compose.yml ───────────────────────────────────────
echo ""
echo "📂 [3/4] Generating deployment manifests from deploy settings..."

docker run --rm \
    -v "$(pwd):/app" \
    -w /app \
    php:8.3-cli \
    php bin/build-deployment.php

echo "✔ docker-compose.yml generated successfully."

# ── Step 4: Build images and start containers ─────────────────────────────────
echo ""
echo "🚀 [4/4] Building images and starting containers..."
docker compose up -d --build

echo ""
echo "✅ Deployment complete!"
echo ""
echo "The following are now running automatically inside each container:"
echo "  - Schema migration (orm:schema-tool:update)"
echo "  - Entity & catalog seeding (app:initialize-entities)"
echo "  - Initial job scheduling (app:schedule-initial-jobs)"
echo "  - Cron setup & process worker"
echo ""
echo "Monitor with:  docker compose logs -f"
echo "Dashboard at:  http://<host>:<first-instance-port>/monitoring"
