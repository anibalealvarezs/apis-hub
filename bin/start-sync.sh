#!/bin/bash
# apis-hub · Lightweight Sync Starter (No Downtime)
# Applies configuration changes, scales workers, and schedules jobs.
set -e

echo -e "\033[1;34m⚡ Starting Lightweight Sync Orchestrator\033[0m"

ENV_FILE=${ENV_FILE:-.env}
if [ -z "$PROJECT_PATH_HOST" ]; then
    export PROJECT_PATH_HOST=$(pwd)
fi

# ── Step 1: Refresh Instances ──────────────────────────────────────────
echo -e "\033[1;33m🔄 [1/4] Refreshing instances from config...\033[0m"
MSYS_NO_PATHCONV=1 docker run --rm \
    -v "$(pwd):/app" \
    -e "ENV_FILE=$ENV_FILE" \
    -e "CONFIG_DIR=/app/config" \
    --env-file "$ENV_FILE" \
    -w /app \
    php:8.3-cli \
    php bin/cli.php app:refresh-instances || php bin/cli.php app:refresh-instances

# ── Step 2: Rebuild Manifest ───────────────────────────────────────────
echo -e "\033[1;33m📂 [2/4] Building Docker Compose manifest...\033[0m"
MSYS_NO_PATHCONV=1 docker run --rm \
    -v "$(pwd):/app" \
    -e "ENV_FILE=$ENV_FILE" \
    -e "PROJECT_PATH_HOST=$PROJECT_PATH_HOST" \
    -e "CONFIG_DIR=/app/config" \
    --env-file "$ENV_FILE" \
    -w /app \
    php:8.3-cli \
    php bin/build-deployment.php || php bin/build-deployment.php

# ── Step 3: Schedule Jobs ──────────────────────────────────────────────
echo -e "\033[1;33m📅 [3/4] Scheduling initial sync jobs...\033[0m"
# If running inside master container, execute directly, otherwise use docker exec
if [[ "$INSTANCE_NAME" == *"master"* ]]; then
    php bin/cli.php app:schedule-initial-jobs || true
else
    DEPLOYMENT_NAME=$(grep -E '^DEPLOYMENT_NAME=' "$ENV_FILE" | cut -d '=' -f 2 | tr -d '"' | tr -d "'" || echo "apis-hub")
    [ -z "$DEPLOYMENT_NAME" ] && DEPLOYMENT_NAME="apis-hub"
    docker exec "${DEPLOYMENT_NAME}-master" php bin/cli.php app:schedule-initial-jobs || php bin/cli.php app:schedule-initial-jobs || true
fi

# ── Step 4: Apply Containers ───────────────────────────────────────────
echo -e "\033[1;33m🚀 [4/4] Scaling and starting containers (No Downtime)...\033[0m"
docker compose --env-file "$ENV_FILE" up -d --remove-orphans

echo -e "\033[0;32m✅ Sync deployment successfully applied!\033[0m"
