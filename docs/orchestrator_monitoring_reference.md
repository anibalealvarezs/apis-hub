# APIs Hub: Orchestration & Container Handling Reference

This document details the container orchestration architecture, job lifecycle, self-healing mechanisms, and operational commands for the **APIs Hub** infrastructure. It serves as a reference guide for designing monitoring systems (e.g., APIs Hub Facade).

---

## 1. Container Topology

The infrastructure operates under an **Orchestrator (Master) and Nodes (Workers)** model.

### Master Node (`apis-hub-master`)
- **Role:** Main control node and HTTP fallback.
- **Identification:** Its `INSTANCE_NAME` environment variable contains the word `master`.
- **Privileges:** It is the only container that must have the Docker socket mounted (`/var/run/docker.sock`).
- **Responsibilities:** 
  - Database schema updates and seeding.
  - Generating network topology (`config/instances.yaml`).
  - Scheduling initial jobs.
  - **Global Garbage Collection:** Scans the Docker API to detect dead workers.

### Generic Workers (`apis-hub-test-worker-*`)
- **Role:** Brute-force processing for jobs without state or rate-limit dependencies.
- **Identification:** The `API_SOURCE` environment variable is empty or set to `none`. The `INSTANCE_NAME` is auto-generated using the container's short hash/ID (e.g., `9ab0177578cc`).
- **Responsibilities:** Consume any job from the queue that does not require channel exclusivity.

### Dedicated Workers (`google-search-console-2025-4`, etc.)
- **Role:** Exclusive processing for specific channels or entities that require dedicated routing (due to API limits or in-memory caching).
- **Identification:** Have `API_SOURCE` and `API_ENTITY` explicitly defined. Their `INSTANCE_NAME` is predefined in the `docker-compose.yml`.

---

## 2. Job Lifecycle (Job State Machine)

Jobs flow through the `jobs` table utilizing the `JobStatus` enumeration.

1. **Scheduled (`status = 1`)**: The job is in the queue, waiting to be picked up.
2. **Processing (`status = 2`)**: A worker has acquired a lock on the job. The `workerId` column stores the identity of the container holding it.
3. **Completed (`status = 3`)**: Processing finished successfully.
4. **Failed (`status = 4`)**: Fatal failure after exhausting all retry attempts.
5. **Cancelled (`status = 5`)**: Manual logical cancellation (the system will not retry it).

---

## 3. Self-Healing Mechanisms

The ecosystem is highly resilient against catastrophic crashes, abrupt shutdowns (OOM Kills), and dynamic scaling events.

### A. Dead Container Detection (Instantaneous)
- **Executor:** Master Node (via Cron, every minute).
- **Process:** 
  1. The Master makes a native cURL request to the local Docker socket HTTP API (`/containers/json`).
  2. Extracts the names and short IDs of all currently running containers.
  3. Executes an `UPDATE` in the database to find jobs in `status = 2` whose `workerId` **is not** in the list of alive containers.
  4. The matched jobs are instantly reverted to `status = 1` (Scheduled).

### B. Timeout Garbage Collection (Safety Net)
- **Executor:** Master Node.
- **Process:** If the Docker socket becomes unavailable for any reason, the Master will automatically revert to `status = 1` any job that has remained in `status = 2` for more than **120 minutes**.

### C. Boot-up Instance Recovery
- **Executor:** Workers (Dedicated and Generic).
- **Process:** At the beginning of their lifecycle, each worker checks if it has jobs stuck under its own name (`workerId = $envInstance`) from a previous lifecycle (before a restart) and releases them back into the queue.

---

## 4. Operational Playbooks & Scenarios

The following are standard operational flows for handling and monitoring from Facade.

### Scenario A: Clean Deployment (Cold Boot)
When the infrastructure boots from scratch or volumes are wiped.

```bash
# 1. Spin up infrastructure scaling generic workers
docker compose up -d --scale worker=3

# 2. Sync schemas and seed master entities
docker exec -it apis-hub-master php bin/cli.php app:setup-db

# 3. Map and generate worker configurations
docker exec -it apis-hub-master php bin/cli.php app:refresh-instances

# 4. Populate initial job queue
docker exec -it apis-hub-master php bin/cli.php app:schedule-initial-jobs
```

### Scenario B: Enabling a New Channel
When a user connects a new platform requiring synchronization.

```bash
# 1. Sync providers in the DB
docker exec -it apis-hub-master php bin/cli.php app:setup-db

# 2. (CRITICAL) Regenerate topology to assign new dedicated workers
docker exec -it apis-hub-master php bin/cli.php app:refresh-instances

# 3. Trigger channel job generation
docker exec -it apis-hub-master php bin/cli.php app:schedule-initial-jobs
```

### Scenario C: Abrupt Crash or Scale Down
If workers are shut down (e.g., `docker compose up -d --scale worker=1`) while actively processing data.

- **Command:** None required.
- **Automatic Flow:** The Master's cron will detect the disappearance of the crashed containers within the first minute and revert their jobs to `Scheduled`. The surviving workers will seamlessly assimilate them.

### Scenario D: Monitoring and Rescuing Failed Jobs
If jobs accumulate logical or API errors (not infrastructure crashes) and shift to `Failed`.

```bash
# Requeue all failed jobs to force a retry
docker exec -it apis-hub-master php bin/cli.php app:retry-failed-jobs
```

---

## 5. Facade Monitoring Cheatsheet

If Facade needs to query the health of the orchestrator, it should monitor these metrics (via API or direct Query):

- **Job Health Check:** Count `SELECT count(*) FROM jobs WHERE status = 2 AND updated_at < (NOW() - INTERVAL 120 MINUTE)`. If > 0, the Master has lost connection with the Docker daemon and the safety net is failing.
- **Queue Saturation:** Count `status = 1`. A continuously growing number across hours indicates that more generic workers are required.
- **Logical Failure Rate:** Count `status = 4`. A sudden spike indicates issues with API credentials, rate limits, or upstream schema changes.
