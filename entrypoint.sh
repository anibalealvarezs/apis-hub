#!/bin/bash
set -e

# Update database schema
echo "Updating database schema..."
php bin/cli.php orm:schema-tool:update --force || echo "Schema update failed, continuing..."

# Configure Cron based on project config
if [ -n "$PROJECT_CONFIG_FILE" ]; then
    # Extract project name from path (e.g. deploy/alimentos-bahia.yaml -> alimentos-bahia)
    PROJECT_NAME=$(basename "$PROJECT_CONFIG_FILE" .yaml)
    echo "Configuring dynamic cron for project: $PROJECT_NAME..."
    php bin/setup-cron.php "$PROJECT_NAME" || echo "Cron setup failed, continuing..."
    
    # Ensure cron service is ready
    touch /var/log/cron.log /var/log/jobs.log
    crontab /etc/cron.d/apis-hub-cron || echo "Crontab load failed"
fi

# Start cron service
echo "Starting cron service..."
service cron start

# Run the web server
echo "Starting PHP server on port $PORT..."
exec php -S 0.0.0.0:${PORT} -t bin/
