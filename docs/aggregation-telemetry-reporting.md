# Aggregation Telemetry Reporting

This note documents the local helper used to summarize aggregation fallback telemetry by pilot vs non-pilot channels.

## Service

- Class: `Services\Aggregation\AggregationFallbackTelemetryReporter`
- Input: executor metadata events (arrays that include `executor_path_decision`, `executor_fallback_reason`, and optional `planner_diagnostics` profile fields).
- Output: summary map with totals plus per-channel counters.

## Event capture

- Class: `Services\Aggregation\AggregationTelemetryEventRecorder`
- Trigger point: `AggregationExecutor::executeAggregate()` (invoked by `BaseRepository::aggregate()`) after planner and execution metadata have been consolidated for the request.
- Activation: opt-in via `AGGREGATION_TELEMETRY_PATH`.
- Format: append-only JSON Lines (`.jsonl` / NDJSON), one event per aggregate execution.

Example environment variable:

```powershell
$env:AGGREGATION_TELEMETRY_PATH = "D:\laragon\www\apis-hub\storage\aggregation-telemetry.jsonl"
```

## Key Counters

- `missing_profile_capability_events`
- `pilot_missing_profile_capability_events`
- `non_pilot_missing_profile_capability_events`
- `by_channel.<channel>.missing_profile_capability_events`

## Focused validation

Run the unit tests:

```powershell
Set-Location "D:\laragon\www\apis-hub"
vendor\bin\phpunit tests\Unit\Services\Aggregation\AggregationFallbackTelemetryReporterTest.php
```

## CLI command

Use the CLI command to produce a JSON snapshot from a telemetry payload file:

```powershell
Set-Location "D:\laragon\www\apis-hub"
php bin\cli.php app:aggregation-telemetry-report --input storage\aggregation-telemetry.json --pilot-channel google_search_console --pilot-channel facebook_organic --pretty
```

Accepted input shapes:

- a raw JSON array of executor metadata events, or
- an object with `events` plus optional `pilot_channels`, or
- newline-delimited JSON (`NDJSON` / `JSONL`) emitted by `AggregationTelemetryEventRecorder`.

Optional output file:

```powershell
Set-Location "D:\laragon\www\apis-hub"
php bin\cli.php app:aggregation-telemetry-report --input storage\aggregation-telemetry.json --output storage\aggregation-telemetry-summary.json --pretty
```

If using the recorder path directly:

```powershell
Set-Location "D:\laragon\www\apis-hub"
$env:AGGREGATION_TELEMETRY_PATH = "D:\laragon\www\apis-hub\storage\aggregation-telemetry.jsonl"
php bin\cli.php app:aggregation-telemetry-report --input storage\aggregation-telemetry.jsonl --pilot-channel google_search_console --pilot-channel facebook_organic --output storage\aggregation-telemetry-summary.json --pretty
```

