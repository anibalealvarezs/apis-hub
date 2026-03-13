# APIs Hub · Automation & Deployment Plan

To achieve a fully automated deployment with minimal user intervention, we have established an orchestration layer that handles everything from the split configuration at `config/` to the execution of parallel data extraction jobs.

## 🚀 Orchestration Flow

The deployment process is now simplified into two main phases:

### 1. Host-Side Manifest Generation

The user runs a single command to generate the infrastructure requirements:

- **Command**: `./bin/full-deploy.sh`
- **Action**:
  - Executes `app:refresh-instances` to calculate your infrastructure from `config/instances_rules.yaml`.
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
  - Executes `app:schedule-initial-jobs`, which reads the final `instances.yaml`.
  - **Inter-Job Dependencies**: Supports the `requires` field (e.g., paid metrics jobs waiting for `fb-entities-sync` to complete).
  - Queues **Historical ranges** immediately into the `jobs` table.
  - Queues **Recent windows** once immediately so that data is available without waiting for the first cron cycle.
- **Cron Setup**: Configures the local crontab with the correct frequencies for recurring jobs.

## 🛠️ Key Improvements

| Component | Description | Benefits |
| :--- | :--- | :--- |
| **`app:refresh-instances`** | Command that translates rules into an optimized worker list. | Replaces manual editing of hundreds of job lines. |
| **`app:schedule-initial-jobs`** | Command that translates `instances` config into DB Job records. | Ensures data starts flowing immediately upon deployment. |
| **Dependency Awareness** | Uses the `requires` flag to sequence jobs (e.g., Entities Sync -> Metrics Fetch). | Prevents race conditions and errors in initial data runs. |
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

1. Configure your rules in `config/instances_rules.yaml`.
2. Ensure your secret data is in `config/security.yaml` and `config/database.yaml`.
3. Run from the project root:

   ```bash
   ./bin/full-deploy.sh
   ```

   This performs **4 steps automatically**:

   | Step | Action |
   | --- | --- |
   | **1** | Installs Composer dependencies via `composer:latest` Docker image |
   | **2** | **Refreshes Instances**: Calculates quarterly splits and dependencies |
   | **3** | Generates `docker-compose.yml` from `instances.yaml` via `php:8.3-cli` |
   | **4** | Builds images and starts all containers (`docker compose up -d --build`) |

4. Once containers are running, each one self-initializes:
   - Migrates the DB schema
   - Seeds entities and catalogs
   - Schedules initial jobs (respecting dependencies like `fb-entities-sync`)
   - Sets up its local cron worker

5. Monitor progress at: `http://<host>:<port>/monitoring`
