#!/bin/bash
# apis-hub · Demo Deployment Script
# This script orchestrates a single-instance deployment with massive simulated data.
set -e

# --- Colors ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}⚒  Starting apis-hub DEMO Deployment${NC}"
echo "------------------------------------------------"

# Ensure .env.demo exists
if [ ! -f ".env.demo" ]; then
    echo -e "${RED}❌ Error: .env.demo not found. Please create it first.${NC}"
    exit 1
fi

# Check for --no-seed option
if [ "$1" == "--no-seed" ]; then
    echo -e "${YELLOW}⏩ Skipping seeding as requested...${NC}"
    export SKIP_SEED=1
fi

# Configuration
export ENV_FILE=${ENV_FILE:-.env.demo}
export SKIP_SEED=${SKIP_SEED:-0}

# --- ENSURE CLEAN SLATE FOR DEMO (Crucial to synchronize DB credentials) ---
echo -e "${YELLOW}🧹 Cleaning up previous demo data & volumes...${NC}"
if [ -f "docker-compose.yml" ]; then
    docker compose --env-file "$ENV_FILE" down -v --remove-orphans || echo "  ⚠️ Cleanup had issues, continuing..."
fi
# ----------------------------------------------------------------------------

# Run the standard deployment with the demo env
# This will trigger InstanceGeneratorService to create only 1 master instance
sh bin/full-deploy.sh "$@"

# Seeding is now handled automatically by the master instance during bootstrap (app:setup-db)
# We just wait a few seconds and show the status.
echo ""
echo -e "${GREEN}✅ Deployment successful!${NC}"
echo -e "Automatic seeding is now running in the background inside the container."
echo "You can monitor progress with: docker compose logs -f demo-entities-sync"
echo ""

echo ""
echo -e "${GREEN}✅ DEMO Deployment and Seeding complete!${NC}"
echo "------------------------------------------------"
echo -e "Master Instance: ${BLUE}http://localhost:8081${NC}"
echo -e "MCP Server:      ${BLUE}http://localhost:3000${NC}"
echo "------------------------------------------------"