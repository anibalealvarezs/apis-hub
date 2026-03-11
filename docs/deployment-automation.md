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

## 📝 Usage Example

1. Edit `deploy/project.yaml` with your credentials and instances.
2. Run:

   ```bash
   ./bin/full-deploy.sh project
   ```

3. Check the progress at: `http://<your-server-ip>:<port>/monitoring` (or whatever port your first instance uses).
