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
- [7. Database Maintenance & Reset (Nuclear Operations)](#7-database-maintenance--reset-nuclear-operations)
- [8. Multiple Deployments & Port Isolation](#8-multiple-deployments--port-isolation)
- [9. Local Packages: Symlink Refresh Command](#9-local-packages-symlink-refresh-command)
- [10. Server Strategy When `composer.lock` Is Not Updated](#10-server-strategy-when-composerlock-is-not-updated)
- [11. One-Shot Lock Refresh + Auto-Commit + Dev Restore](#11-one-shot-lock-refresh--auto-commit--dev-restore)
  - [Definitive command (GitKraken flow)](#definitive-command)

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
# Perform a comprehensive diagnostic (DB, Redis, Schema, Catalog, MCP)
docker compose exec facebook-marketing-entities-sync php bin/cli.php app:health-check
```

---

## 5. Quality and Testing

### Running Unit Tests

```bash
# Run all tests
docker compose exec gsc-jan vendor/bin/phpunit tests
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
scp -r config/channels/ root@178.104.61.99:/root/apis-hub/config/

# 4. Sync entire config folder (Optional but recommended)
scp -r config/ root@178.104.61.99:/root/apis-hub/
```

### Secure Tunneling (SSH Port Forwarding)

Use these commands from your local machine to connect securely to the infrastructure.

```powershell
# 1. Database Tunnel (PostgreSQL)
# Local Port 5433 -> Remote Port 5432
ssh -L 5433:localhost:5432 root@178.104.61.99 -N

# 2. Intel Bridge Tunnel (MCP Server)
# Local Port 3010 -> Remote Port 3000
ssh -L 3010:localhost:3000 root@178.104.61.99 -N
```

> [!TIP]
> Use the `-N` flag to forward ports without opening a remote shell.

---

## 7. Database Maintenance & Reset (Nuclear Operations)

Use these commands when you need to completely wipe data. **WARNING: IRREVERSIBLE.**

### Step-by-Step Reset Chain

```bash
# 1. Nuclear Drop (Force disconnects sessions)
docker compose exec facebook-marketing-entities-sync php bin/cli.php db:query "DROP DATABASE \"apis-hub-production\" --force"

# 2. Create Fresh Database
docker compose exec facebook-marketing-entities-sync php bin/cli.php db:query "CREATE DATABASE \"apis-hub-production\""

# 3. Master Setup Trigger (Schema + Seeding)
docker compose exec facebook-marketing-entities-sync php bin/cli.php app:setup-db

# 4. Verify Health
docker compose exec facebook-marketing-entities-sync php bin/cli.php app:health-check
```

> [!NOTE]
> Use the `facebook-marketing-entities-sync` container for these master commands.

---

## 8. Multiple Deployments & Port Isolation

If you need to run multiple `apis-hub` projects on the same server (e.g. for different clients), follow these rules to ensure true isolation.

### 1. Distinct `DEPLOYMENT_NAME`

Each project must have a unique identifier in its `.env` file. This prevents containers and volumes from different projects from clashing.

```env
DEPLOYMENT_NAME=client-xyz
```

### 2. Port Block Reservation

Assign a unique starting port for each deployment to avoid "Port already allocated" errors.

```env
STARTING_HOST_PORT=9000
```

This ensures Project A uses ports 9000-9099, and Project B (set to 10000) uses 10000-10099.

### 3. Service Port Overrides

Also override the host-exposed ports for shared services like Database and Redis:

```env
DB_HOST_PORT=9100
REDIS_HOST_PORT=9099
```

### 4. Verification Check

After a new deployment, you can verify which project a container belongs to by checking its labels or name:

```bash
docker ps --filter "label=com.docker.compose.project=client-xyz"
```

---

## 9. Local Packages: Symlink Refresh Command

Use this in **PowerShell** to refresh all local `anibalealvarezs/*` path packages across the workspace (including `apis-hub-facade`).

```powershell
$composer = "C:\ProgramData\ComposerSetup\bin\composer.bat"
$repos = @(
  "D:\laragon\www\amazon-api-anibal",
  "D:\laragon\www\amazon-hub-driver",
  "D:\laragon\www\api-client-skeleton",
  "D:\laragon\www\api-driver-core",
  "D:\laragon\www\apis-hub",
  "D:\laragon\www\apis-hub-api",
  "D:\laragon\www\apis-hub-facade",
  "D:\laragon\www\bigcommerce-hub-driver",
  "D:\laragon\www\facebook-graph-api",
  "D:\laragon\www\google-api-anibal",
  "D:\laragon\www\google-hub-driver",
  "D:\laragon\www\klaviyo-api-anibal",
  "D:\laragon\www\klaviyo-hub-driver",
  "D:\laragon\www\linkedin-hub-driver",
  "D:\laragon\www\mailchimp-api-anibal",
  "D:\laragon\www\meta-hub-driver",
  "D:\laragon\www\netsuite-api-anibal",
  "D:\laragon\www\netsuite-hub-driver",
  "D:\laragon\www\pinterest-hub-driver",
  "D:\laragon\www\shipstation-api-anibal",
  "D:\laragon\www\shopify-api-anibal",
  "D:\laragon\www\shopify-hub-driver",
  "D:\laragon\www\tiktok-hub-driver",
  "D:\laragon\www\triple-whale-api-anibal",
  "D:\laragon\www\triple-whale-hub-driver",
  "D:\laragon\www\x-hub-driver"
)

foreach ($repo in $repos) {
  if (-not (Test-Path "$repo\composer.json")) { continue }

  Write-Host "==> $repo"
  Set-Location $repo

  # Remove only local vendor namespace, keep the rest of vendor untouched.
  if (Test-Path ".\vendor\anibalealvarezs") {
    Remove-Item ".\vendor\anibalealvarezs" -Recurse -Force
  }

  & $composer update "anibalealvarezs/*" -W --ignore-platform-reqs --no-interaction
  if ($LASTEXITCODE -ne 0) {
    Write-Warning "Failed in $repo"
  }
}
```

Quick verification (example):

```powershell
Get-Item "D:\laragon\www\apis-hub-facade\vendor\anibalealvarezs\google-api" | Format-List FullName,Attributes,LinkType,Target
```

---

## 10. Server Strategy When `composer.lock` Is Not Updated

If `composer.lock` is intentionally kept local (for path symlink workflow), the safest way to ensure servers take the latest package versions is:

1. **Do not rely on `composer update` in production.**
2. In CI, run `composer update` (or targeted updates), commit the resulting `composer.lock`, and deploy with `composer install`.
3. Deploy servers using:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --classmap-authoritative
```

### Recommended release flow

1. Update package versions/tags in package repositories.
2. In the consuming app repository, run `composer update` in CI/release branch.
3. Commit `composer.lock` as part of the release commit.
4. Deploy using `composer install` only.

### Why this matters

- Without committing `composer.lock`, each server may resolve different dependency versions at deploy time.
- With committed `composer.lock`, every server installs the exact same dependency graph.

### For your local monorepo workflow

- Keep local path repositories and symlinks for daily development.
- Keep `composer.json`/`composer.lock` as `assume-unchanged` locally if needed.
- Before creating a production release, generate and commit a real `composer.lock` from a clean, non-path release context.

---

## 11. One-Shot Lock Refresh + Auto-Commit + Dev Restore

Use this command to automate the full cycle across all local packages:

- update `composer.lock` from non-path repositories,
- create commit(s) for changed locks,
- restore local development state (path symlinks),
- re-enable `assume-unchanged` on `composer.json` and `composer.lock`.

### Definitive command

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "D:\laragon\www\apis-hub\scripts\refresh-locks-and-restore-dev.ps1"
```

Optional variants:

```powershell
# Dry-run (no updates/commits)
powershell -NoProfile -ExecutionPolicy Bypass -File "D:\laragon\www\apis-hub\scripts\refresh-locks-and-restore-dev.ps1" -DryRun

# Custom commit message
powershell -NoProfile -ExecutionPolicy Bypass -File "D:\laragon\www\apis-hub\scripts\refresh-locks-and-restore-dev.ps1" -CommitMessage "chore(deps): refresh lock files"

# Run only selected repos by folder name
powershell -NoProfile -ExecutionPolicy Bypass -File "D:\laragon\www\apis-hub\scripts\refresh-locks-and-restore-dev.ps1" -OnlyRepos apis-hub,apis-hub-api,apis-hub-facade
```

> [!IMPORTANT]
> The script does **not** push. After it finishes, review commits and push manually.
