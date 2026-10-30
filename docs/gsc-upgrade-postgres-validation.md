# GSC backfill PostgreSQL validation

`tests/Integration/GscSearchTypeUpgradePostgresTest.php` runs the existing backfill,
collision, rollback, batching and idempotence tests on real PostgreSQL, including
its table locks. Each test creates and removes a random schema; it never drops an
existing schema or reads the configured tenant database.

Provide `GSC_UPGRADE_TEST_DSN` explicitly with a PostgreSQL URL for a disposable
test database. Its role needs CREATE SCHEMA permission. Run:

```
php vendor/phpunit/phpunit/phpunit -c phpunit.xml tests/Integration/GscSearchTypeUpgradePostgresTest.php
```

The volume test normally creates 2,001 configurations. Set
`GSC_UPGRADE_TEST_CONFIGS=612702` to reproduce the observed configuration volume and
273,566 duplicate merges. It verifies preserved values without summation, retained
channel links, canonical dimension hashes and foreign key integrity. This is a
synthetic fixture, not a production snapshot or an exact runtime prediction.
The full test can take over an hour and should run on representative staging
hardware. Do not put it in a short timeout CI job.

Without an explicit DSN the PostgreSQL tests skip. SQLite coverage continues to
run independently and is not evidence that PostgreSQL locks or production volume
have been validated.
