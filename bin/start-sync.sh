#!/bin/bash
# apis-hub · Lightweight Sync Starter (No Downtime)
# Applies configuration changes, scales workers, and schedules jobs.
set -e

echo -e "\033[1;34m⚡ Starting Lightweight Sync Orchestrator\033[0m"

ENV_FILE=${ENV_FILE:-.env}
if [ -z "$PROJECT_PATH_HOST" ]; then
    export PROJECT_PATH_HOST=$(pwd)
fi

DEPLOYMENT_NAME=$(grep -E '^DEPLOYMENT_NAME=' "$ENV_FILE" | cut -d '=' -f 2 | tr -d '"' | tr -d "'" || echo "apis-hub")
[ -z "$DEPLOYMENT_NAME" ] && DEPLOYMENT_NAME="apis-hub"

# ── Step 0: Ensure Master and Infra are Running ──────────────────────────────
echo -e "\033[1;33m🔄 [0/4] Ensuring infrastructure is running...\033[0m"
# Start db, redis, and master detached. Redirect output to prevent SSH from hanging on background FDs.
MSYS_NO_PATHCONV=1 docker compose --env-file "$ENV_FILE" up -d redis db master > /dev/null 2>&1 || true

# ── Step 1: Refresh Instances ──────────────────────────────────────────
echo -e "\033[1;33m🔄 [1/4] Refreshing instances from config...\033[0m"
docker exec "${DEPLOYMENT_NAME}-master" php bin/cli.php app:refresh-instances || \
{ command -v php >/dev/null 2>&1 && php bin/cli.php app:refresh-instances || { echo -e "\033[1;31m⚠️ Refresh instances failed via docker and php-cli is not available locally. Aborting.\033[0m" >&2; exit 1; }; }

# ── Step 2: Rebuild Manifest ───────────────────────────────────────────
echo -e "\033[1;33m📂 [2/4] Building Docker Compose manifest...\033[0m"
docker exec "${DEPLOYMENT_NAME}-master" php bin/cli.php app:build-deployment || \
{ command -v php >/dev/null 2>&1 && php bin/cli.php app:build-deployment || { echo -e "\033[1;31m⚠️ Build manifest failed via docker and php-cli is not available locally. Aborting.\033[0m" >&2; exit 1; }; }

# ── Step 3: Schedule Jobs ──────────────────────────────────────────────
echo -e "\033[1;33m📅 [3/4] Scheduling initial sync jobs...\033[0m"
# If running inside master container, execute directly, otherwise use docker exec
if [[ "$INSTANCE_NAME" == *"master"* ]]; then
    php bin/cli.php app:schedule-initial-jobs || true
else
    docker exec "${DEPLOYMENT_NAME}-master" php bin/cli.php app:schedule-initial-jobs || (command -v php >/dev/null 2>&1 && php bin/cli.php app:schedule-initial-jobs || echo -e "\033[1;31m⚠️ Schedule jobs failed and php-cli is not available locally. Skipping.\033[0m")
fi

# ── Step 4: Apply Containers ───────────────────────────────────────────
echo -e "\033[1;33m🚀 [4/4] Scaling and starting containers (No Downtime)...\033[0m"
WORKER_SERVICES=$(docker compose --env-file "$ENV_FILE" config --services | grep '^worker-tier-' | tr '\r\n' ' ' || true)
if [ -n "$WORKER_SERVICES" ]; then
    # pass the list of worker services without quotes so it expands to multiple arguments
    docker compose --env-file "$ENV_FILE" up -d --force-recreate --remove-orphans --no-deps $WORKER_SERVICES > /dev/null 2>&1
else
    echo -e "\033[1;33m⚠️ No worker-tier services found in manifest. Skipping up.\033[0m"
fi

echo -e "\033[0;32m✅ Sync deployment successfully applied!\033[0m"
