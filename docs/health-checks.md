# 🏥 Infrastructure & Database Health Checks

Use these steps to verify that the external database and caching are healthy.

## Automated Check

Run the diagnostic dashboard:

```bash
php bin/cli.php app:health-check
```

## Manual Verification

1. **Connectivity**: `php bin/cli.php orm:info`
2. **Schema**: `php bin/cli.php orm:validate-schema`
3. **Redis**: `php test_redis.php`
