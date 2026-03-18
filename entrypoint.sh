#!/bin/bash
set -e

# Ensure persistent log directory exists (mapped to host via volume)
mkdir -p /app/logs

# Update database schema and seed entities (Single Master instance ONLY to avoid deadlocks)
if [[ "$INSTANCE_NAME" == *"entities-sync"* ]]; then
    LOCKFILE="/app/storage/db_setup.lock"
    # Use mkdir for atomic lock (works across containers if volume is shared)
    if mkdir "/app/storage/db_lock" 2>/dev/null; then
        echo "Master Instance ($INSTANCE_NAME): Acquired lock. Initializing database and entities..."
        php bin/cli.php app:setup-db || echo "Database setup failed"
        # Keep the lock dir to indicate setup is done, or remove it? 
        # Actually, let's just use it as a "done" flag too for this deployment.
    else
        echo "Instance ($INSTANCE_NAME): Database setup already in progress or completed by another instance. Waiting..."
        # Fallback to the waiting logic below
        INSTANCE_TYPE="waiting-master"
    fi
fi

if [[ "$INSTANCE_NAME" != *"entities-sync"* ]] || [[ "$INSTANCE_TYPE" == "waiting-master" ]]; then
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

    crontab /etc/cron.d/apis-hub-cron || echo "Crontab load failed"
fi

# Start cron service
echo "Starting cron service..."
service cron start

# Start MCP Server in SSE mode (background)
echo "Starting MCP Server on port 3000..."
export MCP_MODE=sse
export MCP_PORT=3000
node mcp-server/index.js &

# Run the web server
echo "Starting PHP server on port $PORT..."
exec php -S 0.0.0.0:${PORT} -t . bin/index.php

