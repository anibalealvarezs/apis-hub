#!/bin/bash
set -e

# Ensure persistent log directory exists (mapped to host via volume)
mkdir -p /app/logs /app/storage

# Update database schema and seed entities (Single Master instance ONLY to avoid deadlocks)
if [[ "$INSTANCE_NAME" == *"master"* ]]; then
    # Ensure modular dependencies are registered (especially during refactoring with local paths)
    echo "Master Instance ($INSTANCE_NAME): Updating modular dependencies..."
    composer update --no-scripts --no-interaction --ignore-platform-reqs || echo "Modular update failed, continuing..."

    # Wait for DB host to be resolvable via DNS
    DB_HOST_TO_CHECK=${DB_HOST:-db}
    echo "Master Instance ($INSTANCE_NAME): Waiting for database host '$DB_HOST_TO_CHECK' to be resolvable..."
    RETRY_DNS=0
    while ! getent hosts "$DB_HOST_TO_CHECK" > /dev/null && [ $RETRY_DNS -lt 30 ]; do
        sleep 1
        RETRY_DNS=$((RETRY_DNS+1))
    done

    # Use mkdir for atomic lock
    if mkdir "/app/storage/db_lock" 2>/dev/null; then
        echo "Master Instance ($INSTANCE_NAME): Acquired lock. Initializing database and entities..."
        if php bin/cli.php app:setup-db; then
            echo "Database setup successful. Installing dynamic drivers..."
            php bin/cli.php app:install-drivers
        else
            echo "Database setup failed. Removing lock to allow retries."
            rmdir "/app/storage/db_lock"
            exit 1 # Exit with error to signal failure
        fi
    else
        echo "Instance ($INSTANCE_NAME): Database setup already in progress or completed. Waiting..."
        INSTANCE_TYPE="waiting-master"
    fi
fi

if [[ "$INSTANCE_NAME" != *"master"* ]] || [[ "$INSTANCE_TYPE" == "waiting-master" ]]; then

    # Wait for the database and jobs table to exist (up to 2 minutes)
    MAX_RETRIES=60
    RETRY_COUNT=0
    
    # We use a robust PHP check that handles "Unknown database" error 
    # without crashing, treating it as "not ready".
    CHECK_CMD="require 'app/bootstrap.php'; try { \Helpers\Helpers::getManager()->getConnection()->executeQuery('SELECT 1 FROM jobs LIMIT 1'); echo 'READY'; } catch (Exception \$e) { echo 'NOT_READY'; }"
    
    while [ "$(php -r "$CHECK_CMD" 2>/dev/null)" != "READY" ] && [ $RETRY_COUNT -lt $MAX_RETRIES ]; do
        echo "Waiting for database '$DB_NAME' and 'jobs' table... (Attempt $((RETRY_COUNT+1))/$MAX_RETRIES)"
        sleep 2
        RETRY_COUNT=$((RETRY_COUNT+1))
    done
    
    if [ $RETRY_COUNT -eq $MAX_RETRIES ]; then
        echo "Warning: Database schema not ready after 2 minutes. Proceeding anyway..."
    else
        echo "Database schema detected and ready."
    fi
fi

# Each instance only schedules its OWN initial job to prevent massive duplication
echo "Scheduling initial job for $INSTANCE_NAME..."
php bin/cli.php app:schedule-initial-jobs --instance="$INSTANCE_NAME" || echo "Initial scheduling failed"

# Configure Cron based on project config
if [ -n "$PROJECT_CONFIG_FILE" ]; then
    # Extract project name from path (e.g. deploy/alimentos-bahia.yaml -> alimentos-bahia)
    PROJECT_NAME=$(basename "$PROJECT_CONFIG_FILE" .yaml)
    
    # Ensure instances.yaml is fresh. Only one instance needs to do this to avoid race conditions.
    # But all instances need the result for the cron setup.
    if [[ "$INSTANCE_NAME" == *"master"* ]]; then
        if mkdir "/app/storage/refresh_lock" 2>/dev/null; then

             echo "Regenerating instance configuration..."
             php bin/cli.php app:refresh-instances || echo "Instance refresh failed"
             # We keep the lock for 10 seconds to allow others to see the file is ready
             (sleep 10 && rm -rf "/app/storage/refresh_lock") &
        fi
    fi

    # Give the master sync instance a head start if it's currently refreshing
    if [ ! -f "config/instances.yaml" ] && [ -d "/app/storage/refresh_lock" ]; then
        echo "Waiting for instance configuration to be ready..."
        sleep 5
    fi

    echo "Configuring dynamic cron for project: $PROJECT_NAME..."
    php bin/setup-cron.php "$PROJECT_NAME" || echo "Cron setup failed, continuing..."

    # Ensure log files exist
    touch /app/logs/cron.log /app/logs/jobs.log /app/logs/gsc.log

    # Configure logrotate to cap logs at 2MB
    cat > /etc/logrotate.d/apis-hub << 'EOF'
/app/logs/*.log {
    size 2M
    rotate 2
    compress
    missingok
    notifempty
    copytruncate
}
EOF

    crontab /tmp/apis-hub-cron || echo "Crontab load failed"
fi

# Start cron service
echo "Starting cron service..."
cron || service cron start || echo "Cron service startup failed"

# Start MCP Server in SSE mode (background)
echo "Starting MCP Server on port 3000..."
export MCP_MODE=sse
export MCP_PORT=3000
node mcp-server/index.js &

# Automatic server detection based on INSTANCE_NAME
if [[ "$INSTANCE_NAME" == *"master"* ]]; then
    DISABLE_HTTP_SERVER=${DISABLE_HTTP_SERVER:-false}
    USE_SWOOLE=${USE_SWOOLE:-true}
else
    DISABLE_HTTP_SERVER=${DISABLE_HTTP_SERVER:-true}
    USE_SWOOLE=${USE_SWOOLE:-false}
fi

# Start the web server
if [ "$DISABLE_HTTP_SERVER" = "true" ]; then
    echo "HTTP server disabled ($INSTANCE_NAME). Running as pure worker."
    # We keep the container alive by waiting on the background processes or just tailing a log
    # Since cron is running and potentially other background tasks, we tail the cron log
    touch /app/logs/cron.log
    exec tail -f /app/logs/cron.log
elif [ "$USE_SWOOLE" = "true" ]; then
    echo "Starting Swoole HTTP server on port $PORT..."
    exec php bin/swoole-server.php
elif [ $# -gt 0 ]; then
    # If arguments are provided to the container (like 'vendor/bin/phpunit'), execute them instead of the web server
    echo "Executing custom command: $@"
    exec "$@"
else
    echo "Starting PHP server on port $PORT with compression..."
    exec php -d zlib.output_compression=On -S 0.0.0.0:${PORT} -t . bin/index.php
fi
