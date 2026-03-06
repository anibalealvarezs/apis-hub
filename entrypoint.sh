#!/bin/bash
set -e

# Update database schema
echo "Updating database schema..."
php bin/cli.php orm:schema-tool:update --force || echo "Schema update failed, continuing..."

# Configure Cron based on API_SOURCE
if [ -n "$API_SOURCE" ] && [ -n "$API_ENTITY" ]; then
    echo "Configuring cron for $API_SOURCE - $API_ENTITY..."
    PARAMS=""
    if [ -n "$START_DATE" ] && [ -n "$END_DATE" ]; then
        PARAMS="--params=\"startDate=${START_DATE}&endDate=${END_DATE}\""
    fi
    
    # Schedule every hour (adjust frequency if necessary)
    CRON_LINE="0 * * * * cd /app && php bin/cli.php apis-hub:cache \"${API_SOURCE}\" \"${API_ENTITY}\" ${PARAMS} >> /var/log/cron.log 2>&1"
    echo "$CRON_LINE" > /etc/cron.d/apis-hub-cron
    chmod 0644 /etc/cron.d/apis-hub-cron
    crontab /etc/cron.d/apis-hub-cron
    touch /var/log/cron.log
    
    echo "Cron configured: $CRON_LINE"
fi

# Start cron service
echo "Starting cron service..."
service cron start

# Run the web server
echo "Starting PHP server on port $PORT..."
exec php -S 0.0.0.0:${PORT} -t bin/
