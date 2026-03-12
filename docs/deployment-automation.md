# APIs Hub · Automation & Deployment Plan

To achieve a fully automated deployment with minimal user intervention, we have established an orchestration layer that handles everything from the initial `project.yaml` configuration to the execution of parallel data extraction jobs.

## 🚀 Orchestration Flow

The deployment process is now simplified into two main phases:

### 1. Host-Side Manifest Generation

The user runs a single command to generate the infrastructure requirements:

- **Command**: `bin/full-deploy.sh [project-name]`
- **Action**:  
  - Executes `bin/build-deployment.php` to generate a dynamic `docker-compose.yml`.
  - Automatically maps environment variables (DB credentials, Redis config) for each instance.
  - Triggers `docker compose up -d --build` to launch the entire stack.

### 2. Container-Side Self-Initialization

Once the containers are up, each one executes `entrypoint.sh` which performs the following:

- **Database Migration**: Automatically syncs the schema with `orm:schema-tool:update`.
- **Entity Seeding**: Populates lookup tables (Countries, Devices) and maps Configured Accounts/Pages via `app:initialize-entities`.

- **Job Recovery & Initial Scheduling**:  
  - Executes `app:schedule-initial-jobs`, which reads the `instances` list from `project.yaml`.
  - Queues **Historical ranges** (e.g., full year 2025) immediately into the `jobs` table.
  - Queues **Recent windows** (e.g., last 3 days) once immediately so that data is available without waiting for the first cron cycle.
- **Cron Setup**: Configures the local crontab with the correct frequencies for recurring jobs.

## 🛠️ Key Improvements

| Component | Description | Benefits |
| :--- | :--- | :--- |
| **`app:schedule-initial-jobs`** | New command that translates `instances` config into DB Job records. | Ensures data starts flowing immediately upon deployment. |
| **Idempotency** | The scheduling command checks for existing `scheduled` or `processing` jobs for the same range. | Safe to run in parallel across multiple containers. |
| **Combined Entrypoint** | Merges infrastructure setup (Cron) with application setup (DB/Jobs). | Guaranteed consistency; the container is not "Ready" until its local configuration is applied. |

## 📝 Usage

### Prerequisites

- **Docker** and **Docker Compose** — that's it. No host-side PHP, Laragon, or any other runtime required.

### Steps

1. Edit `deploy/project.yaml` with your credentials, DB settings, and instance definitions.
2. Run from the project root:

   ```bash
   ./bin/full-deploy.sh project
   ```

   This performs **3 steps automatically**:

   | Step | Action |
   |---|---|
   | **1** | Installs Composer dependencies via `composer:latest` Docker image (skipped if `vendor/` already exists) |
   | **2** | Generates `docker-compose.yml` from `deploy/project.yaml` via `php:8.3-cli` Docker image |
   | **3** | Builds images and starts all containers (`docker compose up -d --build`) |

3. Once containers are running, each one self-initializes:
   - Migrates the DB schema
   - Seeds entities and catalogs
   - Schedules initial jobs (historical + recent ranges)
   - Sets up its local cron worker

4. Monitor progress at: `http://<host>:<port>/monitoring`
