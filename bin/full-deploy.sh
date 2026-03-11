#!/bin/bash
# apis-hub · Full Deployment Orchestrator
# This script automates the entire deployment process from project.yaml to running containers.

PROJECT_NAME=${1:-project}

echo "⚒  Starting Full Deployment for project: $PROJECT_NAME"

# 1. Generate docker-compose.yml and environment configs
echo "📂 [1/3] Generating deployment manifests..."
php bin/build-deployment.php "$PROJECT_NAME"

# 2. Deploy containers
echo "🚀 [2/3] Building and starting containers..."
docker compose up -d --build

# 3. Finalize
echo "✅ [3/3] Deployment triggered!"
echo "The following processes are now running automatically inside the containers:"
echo " - Database Schema Synchronization"
echo " - Entity & Catalog Seeding"
echo " - Initial Job Scheduling (Historical & Recent)"
echo " - Cron Service Setup"
echo ""
echo "You can monitor the progress with: docker compose logs -f"
echo "Or visit the monitoring dashboard."
