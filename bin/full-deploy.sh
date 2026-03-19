#!/bin/bash
# apis-hub · Full Deployment Orchestrator
# Designed for any environment (Linux, CI/CD, cloud).
# Only requires: docker, docker compose, bash.
set -e

echo "⚒  Starting Full Deployment"
echo ""

# ── Step 0: Ensure configuration files exist ──────────────────────────────────
echo "📂 [0/4] Checking configuration files..."
# --- Configuration & Colors ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}⚒  Starting apis-hub Deployment Orchestrator${NC}"
echo "------------------------------------------------"

# ── Step 0: Environment Validation ──────────────────────────────────────────
echo -e "${YELLOW}📂 [0/5] Validating environment & configuration...${NC}"

# Check for .env file
if [ ! -f ".env" ]; then
    if [ -f ".env.example" ]; then
        echo "  ⚠️  .env not found. Creating from .env.example..."
        cp .env.example .env
    else
        echo "  ⚠️  .env.example not found. Using default environment variables."
    fi
fi

# Check mandatory YAML files
CONFIG_FILES="database security app instances_rules logging redis"
for FILE in $CONFIG_FILES; do
    if [ ! -f "config/$FILE.yaml" ]; then
        if [ -f "config/$FILE.yaml.example" ]; then
            echo "  ⚠️  config/$FILE.yaml missing. Creating from example..."
            cp "config/$FILE.yaml.example" "config/$FILE.yaml"
        else
            printf "  ${RED}❌ Error: config/$FILE.yaml missing and no example found.${NC}\n"
            exit 1
        fi
    fi
done
echo -e "${GREEN}✔ Environment ready.${NC}"

# ── Step 1: Install Composer dependencies ────────────────────────────────────
echo ""
if [ ! -d "vendor" ] || [ "$1" == "--update" ]; then
    echo -e "${YELLOW}📦 [1/5] Installing/Updating dependencies...${NC}"
    MSYS_NO_PATHCONV=1 docker run --rm \
        -v "$(pwd):/app" \
        -w /app \
        composer:latest \
        install --no-scripts --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs
    echo -e "${GREEN}✔ Dependencies installed.${NC}"
else
    echo -e "${YELLOW}📦 [1/5] Dependencies already present. Skipping install.${NC}"
fi

# ── Step 1b: Fetch Remote Configuration (Optional) ──────────────────────────
echo ""
echo -e "${YELLOW}📡 [1.5/5] Checking for remote configuration...${NC}"
# Use local PHP if available, otherwise skip (this runs before containers are up)
if command -v php &> /dev/null; then
    php bin/fetch-remote-config.php || echo "  ⚠️ Remote config fetch failed, continuing with local config..."
else
    echo "  ⚠️ php-cli not found locally, skipping remote config fetch."
fi

# ── Step 2: Refresh Instances from rules ──────────────────────────────────────
echo ""
echo -e "${YELLOW}🔄 [2/5] Calculating instance nodes and splits...${NC}"
MSYS_NO_PATHCONV=1 docker run --rm \
    -v "$(pwd):/app" \
    --env-file .env \
    -w /app \
    php:8.3-cli \
    php bin/cli.php app:refresh-instances
echo -e "${GREEN}✔ Instances refreshed (config/instances.yaml).${NC}"

# ── Step 3: Generate docker-compose.yml ───────────────────────────────────────
echo ""
echo -e "${YELLOW}📂 [3/5] Building Docker Compose manifest...${NC}"
MSYS_NO_PATHCONV=1 docker run --rm \
    -v "$(pwd):/app" \
    --env-file .env \
    -w /app \
    php:8.3-cli \
    php bin/build-deployment.php
echo -e "${GREEN}✔ docker-compose.yml generated.${NC}"

# ── Step 4: Build images and start containers ─────────────────────────────────
echo ""
echo -e "${YELLOW}🚀 [4/5] Orchestrating containers...${NC}"

# Ensure a clean slate: stop existing and remove orphans before building
if [ -f "docker-compose.yml" ]; then
    echo "  🧹 Cleaning up existing deployment..."
    rm -rf storage/db_lock
    docker compose --env-file .env down --remove-orphans || echo "  ⚠️ Cleanup had issues, continuing..."
fi

echo "  🏗️  Building and starting new containers..."
docker compose --env-file .env up -d --remove-orphans --build

# ── Step 5: Post-deployment Health Check ──────────────────────────────────────
echo ""
echo -e "${YELLOW}🩺 [5/5] Waiting for master instance to bootstrap...${NC}"
# We wait a bit for the entities-sync instance to finish schema update
sleep 10
echo -e "${GREEN}✔ Bootstrap initiated.${NC}"

echo ""
echo -e "${GREEN}✅ Deployment successful and running in background!${NC}"
echo "------------------------------------------------"
echo "Automatic operations now running inside containers:"
echo "  - 🗄️  Database creation & Schema migration (app:setup-db)"
echo "  - 🌱 Entity & catalog seeding (app:setup-db)"
echo "  - 📅 Staggered Job scheduling (app:schedule-initial-jobs)"
echo "  - ⏱️  Dynamic Cron configuration per instance (bin/setup-cron.php)"
echo ""
echo -e "Monitor: ${BLUE}docker compose logs -f --tail=20${NC}"
echo -e "Status:  ${BLUE}php bin/cli.php app:health-check${NC}"
echo "------------------------------------------------"
