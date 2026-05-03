# Aggregate benchmark

Benchmark runner for `BaseRepository::aggregate()` focused on comparing total, temporal, and breakdown payloads.

## Included sample payloads

- `fixtures/gsc-total.json`
- `fixtures/gsc-daily.json`
- `fixtures/gsc-query.json`

These are modeled after the current GSC regression scenario.

## Quick validation

```powershell
php D:\laragon\www\apis-hub\tests\Performance\aggregate-benchmark.php --dry-run --entity=channeled_metric --channel=google_search_console --payload-dir=D:\laragon\www\apis-hub\tests\Performance\fixtures
```

## Run benchmark

```powershell
php D:\laragon\www\apis-hub\tests\Performance\aggregate-benchmark.php --entity=channeled_metric --channel=google_search_console --payload-dir=D:\laragon\www\apis-hub\tests\Performance\fixtures --runs=5 --warmup=1 --debug-sql
```

## Notes

- `--debug-sql` injects `filters.debug_sql=1`, which activates optimized-path timing traces currently logged by `BaseRepository`.
- For `channeled_metric`, pass `--channel=<name|id>` to reproduce the same `filters.channel` injection performed by `ChanneledCrudController` in the real API.
- Use `--output=json` if you want machine-readable results.
- You can pass one or more custom payload files via repeated `--payload=...`.

