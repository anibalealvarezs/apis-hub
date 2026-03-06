#!/bin/bash
set -e

# ─────────────────────────────────────────────────────────────────────────────
# apis-hub · Database Initialization Utility
# ─────────────────────────────────────────────────────────────────────────────
# This script prepares the database for a new deployment.
# Logic:
# 1. Attempts to create/update tables via Doctrine.
# 2. Seeds catalogue data and entity mappings.
# ─────────────────────────────────────────────────────────────────────────────

echo "🚀 Starting Project Initialization..."

# 1. Update Schema
echo "📂 [1/2] Syncing database schema..."
php bin/cli.php orm:schema-tool:update --force

# 2. Seed Entities
echo "🌱 [2/2] Seeding catalogs and entities..."
php bin/cli.php app:initialize-entities

echo "✅ Initialization Complete! Your database is now ready for deployment."
