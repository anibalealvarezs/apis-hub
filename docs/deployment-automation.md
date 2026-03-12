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
- **Entity Seeding & Discovery**:
  - Populates lookup tables (Countries, Devices).
  - **Automated Mapping**: Discovers and maps Configured Accounts/Pages via `app:initialize-entities`.
  - **Facebook Bridge**: In Facebook environments, the `fb-entities-sync` instance handles the initial mapping of all pages and accounts configured in `project.yaml`.

- **Job Dependency Management & Initial Scheduling**:  
  - Executes `app:schedule-initial-jobs`, which reads the `instances` list from `project.yaml`.
  - **Inter-Job Dependencies**: Supports the `requires` field (e.g., paid metrics jobs waiting for `fb-entities-sync` to complete).
  - Queues **Historical ranges** (e.g., full year 2025) immediately into the `jobs` table.
  - Queues **Recent windows** (e.g., last 3 days) once immediately so that data is available without waiting for the first cron cycle.
- **Cron Setup**: Configures the local crontab with the correct frequencies for recurring jobs.

## 🛠️ Key Improvements

| Component | Description | Benefits |
| :--- | :--- | :--- |
| **`app:schedule-initial-jobs`** | New command that translates `instances` config into DB Job records. | Ensures data starts flowing immediately upon deployment. |
| **Dependency Awareness** | Uses the `requires` flag to sequence jobs (e.g., Entities Sync -> Metrics Fetch). | Prevents race conditions and errors in initial data runs. |
| **Granular FB Filtering** | Now supports `additionalParams` (since/until) at the request level. | Strictly adheres to `project.yaml` limitations and protects Rate Limits. |
| **Combined Entrypoint** | Merges infrastructure setup (Cron) with application setup (DB/Jobs). | Guaranteed consistency; the container is not "Ready" until its local configuration is applied. |

## 📝 Usage

### Prerequisites

- **Docker** and **Docker Compose** — that's it. No host-side PHP, Laragon, or any other runtime required.

### Fresh Installation / Reset

If you want to perform a completely clean installation:

1. **Delete Existing Containers**:

   ```bash
   docker rm -f $(docker ps -aq)
   ```

2. **Remove Unused Volumes** (Optional):

   ```bash
   docker volume prune -f
   ```

### Deployment Steps

1. Edit `deploy/project.yaml` with your credentials, DB settings, and instance definitions.
2. Ensure your target database (e.g., `apis-hub-12`) is configured in the `database` section.
3. Run from the project root:

   ```bash
   ./bin/full-deploy.sh project
   ```

   This performs **3 steps automatically**:

   | Step | Action |
   | --- | --- |
   | **1** | Installs Composer dependencies via `composer:latest` Docker image |
   | **2** | Generates `docker-compose.yml` from `deploy/project.yaml` via `php:8.3-cli` Docker image |
   | **3** | Builds images and starts all containers (`docker compose up -d --build`) |

4. Once containers are running, each one self-initializes:
   - Migrates the DB schema
   - Seeds entities and catalogs
   - Schedules initial jobs (respecting dependencies like `fb-entities-sync`)
   - Sets up its local cron worker

5. Monitor progress at: `http://<host>:<port>/monitoring`
