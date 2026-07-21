#!/bin/bash

# nuclear-sync.sh
# Safely orchestrates a nuclear resync from the Host OS using native Docker Compose.

set -e

echo "Starting Nuclear Resync Process..."

# Parse channel and asset arguments if provided
CHANNEL_ARG=""
ASSET_ARG=""

for arg in "$@"; do
    case $arg in
        --channel=*)
            CHANNEL_ARG="$arg"
            ;;
        --asset=*)
            ASSET_ARG="$arg"
            ;;
        *)
            if [ -z "$CHANNEL_ARG" ]; then
                CHANNEL_ARG="$arg"
            elif [ -z "$ASSET_ARG" ]; then
                ASSET_ARG="--asset=$arg"
            fi
            ;;
    esac
done

# Get dynamically defined worker services from docker-compose.yml before the refresh
OLD_WORKERS=$(docker compose config --services | grep "worker-tier-" | tr '\r\n' ' ' || true)

if [ -z "$OLD_WORKERS" ]; then
    echo "No worker tiers found in configuration. Cannot stop workers."
else
    # 1. Gracefully stop workers (Instant shutdown due to SIGTERM handling)
    echo "Stopping current workers: $OLD_WORKERS"
    docker compose stop $OLD_WORKERS
fi

# 2. Run the database truncation and re-scheduling via the master container (this regenerates docker-compose.yml)
echo "Running database truncation and scheduling..."
docker compose exec -T master php bin/cli.php app:nuclear-resync $CHANNEL_ARG $ASSET_ARG

# 3. Re-evaluate workers from the freshly built docker-compose.yml
NEW_WORKERS=$(docker compose config --services | grep "worker-tier-" | tr '\r\n' ' ' || true)

# 4. Start the workers back up with fresh state
if [ -n "$NEW_WORKERS" ]; then
    echo "Starting workers (including any newly generated ones): $NEW_WORKERS"
    docker compose up -d $NEW_WORKERS
fi

echo "Nuclear Resync Process completed."
