#!/bin/bash

# nuclear-sync.sh
# Safely orchestrates a nuclear resync from the Host OS using native Docker Compose.

set -e

echo "Starting Nuclear Resync Process..."

# Parse channel argument if provided
CHANNEL_ARG=""
if [ "$1" ]; then
    CHANNEL_ARG=$1
fi

# Get dynamically defined worker services from docker-compose.yml
WORKERS=$(docker compose config --services | grep "worker-tier-" | tr '\r\n' ' ' || true)

if [ -z "$WORKERS" ]; then
    echo "No worker tiers found in configuration. Cannot restart workers."
else
    # 1. Gracefully stop workers (Instant shutdown due to SIGTERM handling)
    echo "Stopping workers: $WORKERS"
    docker compose stop $WORKERS
fi

# 2. Run the database truncation and re-scheduling via the master container
echo "Running database truncation and scheduling..."
docker compose exec -T master php bin/cli.php app:nuclear-resync $CHANNEL_ARG

# 3. Start the workers back up with fresh state
if [ -n "$WORKERS" ]; then
    echo "Starting workers: $WORKERS"
    docker compose up -d $WORKERS
fi

echo "Nuclear Resync Process completed."
