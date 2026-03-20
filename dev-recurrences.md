# APIs Hub - Development Recurrences

This document lists common command chains needed during development to keep local instances in sync.

## 📌 Table of Contents

- [1. Refreshing Code Bases After Commits](#1-refreshing-code-bases-after-commits)
- [2. Rebuilding After Breaking Changes](#2-rebuilding-after-breaking-changes)
- [3. Useful Shortcuts](#3-useful-shortcuts)
  - [Running CLI commands for specific entities](#running-cli-commands-for-specific-entities)
  - [Checking real-time logs](#checking-real-time-logs)
  - [Managing and Monitoring Instance Jobs](#managing-and-monitoring-instance-jobs)
  - [Managing Redis Cache](#managing-redis-cache)
- [4. Health Check and Diagnostics](#4-health-check-and-diagnostics)
- [5. Quality and Testing](#5-quality-and-testing)
  - [Running Unit Tests](#running-unit-tests)
  - [Running Static Analysis (PHPStan)](#running-static-analysis-phpstan)
- [6. Remote Deployment (Hetzner)](#6-remote-deployment-hetzner)

---

## 1. Refreshing Code Bases After Commits

Since we use Docker volumes, code changes on your host are reflected in containers immediately. However, to ensure all services are using the latest state (and to restart long-running processes):

```bash
# Restart all active services
docker compose restart

# Optional: Ensure any new dependencies are installed (run in one of the PHP services)
docker compose exec gsc-jan composer install
```

---

## 2. Rebuilding After Breaking Changes

Use this chain when you've updated `Dockerfile`, `docker-compose.yml`, or when you've introduced DB schema changes (migrations).

### Step-by-Step Chain

```bash
# 0. Generate docker-compose.yml from project config (required on fresh clone or after project.yaml changes)
#    This file is gitignored — it must be generated locally.
php bin/build-deployment.php project

# 1. Stop and remove containers (ensures fresh start)
docker compose down

# 2. Rebuild and start headlessly
docker compose up -d --build

# 3. Update dependencies (if not handled by entrypoint/build)
docker compose exec gsc-jan composer install

# 4. Update Database Schema (Doctrine)
# First: Review what will change
docker compose exec gsc-jan php bin/cli.php orm:schema-tool:update --dump-sql
# Second: Apply the changes
docker compose exec gsc-jan php bin/cli.php orm:schema-tool:update --force

# 5. Initialize/Update Seed Data (Countries, Devices, Pages)
docker compose exec gsc-jan php bin/cli.php app:initialize-entities

# 6. Verify System Health
docker compose exec gsc-jan php bin/cli.php app:health-check
```

---

## 3. Useful Shortcuts

### Running CLI commands for specific entities

```bash
# Example: Read jobs (Smart Context: shows only local instance jobs by default)
docker compose exec gsc-jan php bin/cli.php app:read --entity=job --params="status=scheduled&limit=5"

# Example: Read ALL jobs across ALL instances (Global View)
docker compose exec gsc-jan php bin/cli.php app:read --entity=job --params="global=1&limit=20"
```

### Checking real-time logs

```bash
# Follow logs for all services
docker compose logs -f

# Follow logs for a specific service (e.g., Facebook Ads)
docker compose logs -f fb-ads
```

### Managing and Monitoring Instance Jobs

To check the current state of your jobs (e.g., to see if any are rate-limited/delayed):

```bash
# 1. List scheduled jobs (Queued for next run)
docker compose exec <service-name> php bin/cli.php app:read --entity=job --params="status=1&limit=10"

# 2. List delayed jobs (Rate-limited, waiting for cooldown)
docker compose exec <service-name> php bin/cli.php app:read --entity=job --params="status=5&limit=10"

# 3. List failed jobs (Encountered a permanent error)
docker compose exec <service-name> php bin/cli.php app:read --entity=job --params="status=4&limit=10"
```

To re-cache data for a specific instance using its deployment parameters:

```bash
# Trigger the caching task using environment variables
docker compose exec <service-name> sh -c 'php bin/cli.php app:cache $API_SOURCE $API_ENTITY --params="startDate=$START_DATE&endDate=$END_DATE"'

# Force the job processor to run immediately
docker compose exec <service-name> php bin/cli.php jobs:process
```

### Managing Redis Cache

Use the `cache:clear` command to invalidate data in Redis.

```bash
# 1. Clear EVERYTHING (Nuclear option)
docker compose exec gsc-jan php bin/cli.php cache:clear --all

# 2. Clear for a specific channel (e.g., facebook)
docker compose exec gsc-jan php bin/cli.php cache:clear --channel=facebook

# 3. Clear for a specific entity (e.g., product)
docker compose exec gsc-jan php bin/cli.php cache:clear --entity=product

# 4. Clear for a specific channel AND entity
docker compose exec gsc-jan php bin/cli.php cache:clear --channel=shopify --entity=customer
```

---

## 4. Health Check and Diagnostics

Before running tests or after a rebuild, verify the health of the entire infrastructure:

```bash
# Perform a comprehensive diagnostic (DB, Redis, Schema, Catalog)
docker compose exec gsc-jan php bin/cli.php app:health-check
```

---

## 5. Quality and Testing

### Running Unit Tests

```bash
# Run all tests
docker compose exec gsc-jan vendor/bin/phpunit tests

# Run a specific test class
docker compose exec gsc-jan vendor/bin/phpunit tests/Controllers/CacheControllerTest.php
```

### Running Static Analysis (PHPStan)

```bash
docker compose exec gsc-jan vendor/bin/phpstan analyze src
```

---

## 6. Remote Deployment (Hetzner)

Since configuration files (`.yaml`) and secrets (`.env`) are excluded from repository commits, they must be synchronized manually to the Hetzner server using `scp` from **PowerShell** (Windows) or Terminal (Unix).

### Basic Deployment Flow

```powershell
# 1. Copy environment configuration (rename to .env)
scp .env.llenatucentro root@178.104.61.99:/root/apis-hub/.env

# 2. Copy base configuration files (*.yaml)
scp -r config/*.yaml root@178.104.61.99:/root/apis-hub/config/

# 3. Copy channel-specific configuration files (facebook, google, etc.)
#    This is a recursive copy of the entire channels folder.
scp -r config/channels/ root@178.104.61.99:/root/apis-hub/config/
```

### Quick "Sync All Configs" Shortcut

To synchronize the entire `config` directory in one go (including subfolders and `.example` files):

```powershell
scp -r config/ root@178.104.61.99:/root/apis-hub/
```

> [!NOTE]
> After copying the configuration files, remember to log into the server and run `php bin/build-deployment.php` to regenerate the `docker-compose.yml` if necessary, and then `docker compose up -d --build`.

---

## 7. Database Maintenance & Reset (Nuclear Operations)

Use these commands when you need to completely wipe the data and start from a clean state. **WARNING: This is irreversible.**

### Step-by-Step Reset Chain

```bash
# 1. Nuclear Drop (Force disconnects all active sessions)
# Replace 'apis-hub-production' with your actual DB Name (DB_NAME env)
docker compose exec facebook-marketing-entities-sync php bin/cli.php db:query "DROP DATABASE \"apis-hub-production\" --force"

# 2. Create Fresh Database
docker compose exec facebook-marketing-entities-sync php bin/cli.php db:query "CREATE DATABASE \"apis-hub-production\""

# 3. Master Setup Trigger (Schema creation + Entity seeding)
docker compose exec facebook-marketing-entities-sync php bin/cli.php app:setup-db

# 4. Verify Schema (Optional)
docker compose exec facebook-marketing-entities-sync php bin/cli.php app:health-check
```

> [!TIP]
> Use the `facebook-marketing-entities-sync` container for these commands as it is usually the "Master" instance configured with the most permissions in `entrypoint.sh`.
