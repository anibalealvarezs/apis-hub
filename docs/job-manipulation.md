# ⚙️ Job Manipulation & Lifecycle

This guide explains how to create, monitor, and control caching jobs using the CLI. These actions are equivalent to the API endpoints and can be used as a backend reference for a future frontend.

## 1. Creating a Job

Jobs are created when you request data to be cached. You can trigger this manually for any channel/entity.

```bash
# Example: Schedule a Facebook Ads sync
php bin/cli.php apis-hub:cache facebook metric -p '{"start_date":"-3 days","end_date":"yesterday"}'
```

- **Response**: Returns a JSON object containing the `job_id` (a UUID).

---

## 2. Checking Job Status

You can check the status of a specific job or list recent ones using the CRUD commands on the `job` entity.

### Check a specific Job by UUID

```bash
# Replace <UUID> with your actual job ID
php bin/cli.php app:read --entity=job --filters='{"uuid":"<UUID>"}'
```

### List Jobs (Smart Context)

By default, when you list jobs inside a specific container, the output is **automatically filtered** to match that instance's configuration (Channel, Entity, and Date Range). This keeps your workspace clean and relevant.

```bash
# Shows only jobs relevant to the current instance
php bin/cli.php app:read --entity=job --params='limit=10'
```

### Force Global View

If you need to see all jobs across the entire system from any container, use the `global=1` parameter:

```bash
# Shows all jobs from all instances
php bin/cli.php app:read --entity=job --params='global=1&limit=20'
```

> [!TIP]
> You can also override the local context by explicitly filtering for a different channel, e.g., `--params='channel=gsc'`.

### Status Reference

The `status` field in the database follows these integer values:

- `1`: **Scheduled** (Waiting in queue)
- `2`: **Processing** (Currently fetching data)
- `3`: **Completed** (Finished successfully)
- `4`: **Failed** (Error occurred during execution)
- `6`: **Cancelled** (Manually discarded by an administrator)

---

## 3. Manually Suspending/Cancelling a Job

If a job is **Scheduled** but not yet running, or if it's currently **Processing** and you want to stop it, you can change its status to **Cancelled (6)**.

The `ProcessJobsCommand` and the `CacheController` check the status of the job at key intervals. If they see the status has changed to `cancelled` (6), they will throw a `JobCancelledException` and stop the sync.

### Cancel a Job

```bash
# Replace <ID> with the internal numerical ID of the job
php bin/cli.php app:update --entity=job --id=<ID> --data='{"status":6}'
```

---

## 4. Monitoring Dashboard

While CLI commands are powerful for automation, the **Monitoring Dashboard** provides a real-time visual interface to manage your infrastructure.

### Accessing the Dashboard

Navigate to `/monitoring` in your browser. You will see a list of all active containers (instances) defined in your `project.yaml`.

### Advanced Actions

- **Run Process Now**: Triggers an immediate execution of a **Scheduled** job without waiting for its next cron cycle.
- **Discard Job**: Stops a running process (moving it to **Cancelled**) or removes a scheduled one from the immediate queue.
- **Re-schedule / Recycle**: Allows you to clone the configuration of *any* historical job (completed, failed, or cancelled) to create a new execution in the future.

### Data Integrity (History)

The dashboard follows a **Forensic Preservation** policy:

- Manual actions like "Retry" or "Re-schedule" do **not** overwrite the original job record. Instead, they create a brand new entry, preserving the status and logs of the previous attempt for auditing.
- No jobs are physically deleted from the database via the dashboard; they are only deactivated and moved to a terminal state.

---

## 5. Automation & Safe Processing

Jobs are automatically processed in the background by the `ProcessJobsCommand`.

### Instance-Safe Workers

The workers are designed to be **localized** and **thread-safe**:

- **Localization**: Each worker only picks up jobs that match its assigned `Channel`, `Entity`, and `Date Range`.
- **Atomic Claiming**: The system uses a "Claim-first" mechanism. Multiple workers can query the same database without ever starting the same job twice.

- **Worker Command**: `php bin/cli.php jobs:process`
- **Automation Logic**: See [entrypoint.sh](../entrypoint.sh) to see how the worker is bootstrapped in a container.
