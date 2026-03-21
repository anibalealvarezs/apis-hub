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

# Configuration
export ENV_FILE=.env.demo

# Run the standard deployment with the demo env
# This will trigger InstanceGeneratorService to create only 1 master instance
sh bin/full-deploy.sh

# Seeding
echo ""
echo -e "${BLUE}🌱 [6/5] Seeding massive demo data into apis-hub-demo...${NC}"
echo "  (This might take a minute due to the volume of metrics)"

# We execute inside the master instance container
docker compose --env-file .env.demo exec demo-entities-sync php bin/cli.php app:seed-demo-data

echo ""
echo -e "${GREEN}✅ DEMO Deployment and Seeding complete!${NC}"
echo "------------------------------------------------"
echo -e "Master Instance: ${BLUE}http://localhost:8081${NC}"
echo -e "MCP Server:      ${BLUE}http://localhost:3000${NC}"
echo "------------------------------------------------"
