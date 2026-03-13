#!/bin/bash
set -e

# Ensure persistent log directory exists (mapped to host via volume)
mkdir -p /app/logs

# Update database schema and seed entities (Single Master instance ONLY to avoid deadlocks)
if [[ "$INSTANCE_NAME" == *"entities-sync"* ]]; then
    echo "Master Instance ($INSTANCE_NAME): Initializing database and entities..."
    php bin/cli.php app:setup-db || echo "Database setup failed"
else
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

# Run the web server
echo "Starting PHP server on port $PORT..."
exec php -S 0.0.0.0:${PORT} -t . bin/index.php
