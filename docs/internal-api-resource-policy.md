# Internal API resource policy

## Implemented safeguards

- [x] Atomic Redis token budgets shared by all PHP processes.
- [x] Tenant-wide and authenticated-actor budgets; admin keys and authorized IPs have no resource-guard exemption.
- [x] Aggregation concurrency leases, acquired with the budgets in a single Lua operation.
- [x] Separate management and KPI orchestration pools. KPI parents cannot occupy all aggregate slots needed by child queries.
- [x] `429` with `Retry-After` for exhausted capacity, before controller execution.
- [x] `503` with `Retry-After: 2` if capacity checks cannot reach Redis; no unlimited fail-open execution.
- [x] Owner-specific release in `finally`, plus expiring leases for crashed workers.
- [x] Aggregate admission/rejection counters without keys, user names, or request bodies.
- [ ] Deploy and tune against each tenant's observed query duration and resource usage.

This guard is independent of the older subscription/IP limiter in `RoutingCore`. Subscription access restrictions remain in force. The resource guard runs after authentication for non-HTML routes, including internal/admin requests. Exact health paths `/api/heartbeat`, `/api/v1/ping`, and `/up` bypass both guards. Management calls still require their existing authorization; their budget is independent, not unlimited.

## Defaults and configuration

Budgets measure **work tokens**, not literal HTTP counts. Aggregations and KPI computations cost five tokens; other operations cost one. Token buckets refill continuously and allow the specified burst. Denied admission consumes no tokens.

| Setting | Default |
|---|---:|
| `API_TENANT_BUDGET_PER_MINUTE` | 600 |
| `API_TENANT_BUDGET_BURST` | 150 |
| `API_ACTOR_BUDGET_PER_MINUTE` | 300 |
| `API_ACTOR_BUDGET_BURST` | 120 |
| `API_MAX_CONCURRENT_AGGREGATIONS` | 4 |
| `API_MAX_CONCURRENT_READS` | 8 |
| `API_MAX_CONCURRENT_KPIS` | 2 |
| `API_MAX_CONCURRENT_MANAGEMENT_REQUESTS` | 2 |
| `API_KPI_BUDGET_PER_MINUTE` | 60 |
| `API_KPI_BURST` | 10 |
| `API_MANAGEMENT_BUDGET_PER_MINUTE` | 60 |
| `API_MANAGEMENT_BURST` | 10 |
| `API_AGGREGATION_LEASE_SECONDS` | 300 |
| `API_RATE_LIMIT_NAMESPACE` | `api_resource_guard` |

The normal data burst accommodates a complete multi-section explorer refresh. Concurrency caps independently protect expensive work. Ordinary data requests share the data pool with aggregations, but can use up to eight slots; an aggregation is admitted only when fewer than four data slots are occupied. This bounds ordinary API fan-out too. Actor budgets apply within each pool. Positive configuration values are required; a burst smaller than an operation's cost is raised to that cost so operations cannot be permanently unserviceable.

Deployments normally have separate Redis instances. If several tenants share a Redis database, configure a distinct `API_RATE_LIMIT_NAMESPACE` for each tenant.

**Lease lifetime:** concurrency is bounded while leases are valid. Set the lease longer than the maximum complete handler lifetime, and configure database/web-server execution timeouts below that lifetime. Otherwise an exceptionally long handler can outlive its lease. Expiration is crash recovery, not cancellation of SQL that is already running. No database settings or schema are changed automatically.

## Actor identity

User-scoped keys use the authenticated user context. The facade sends a tenant/user HMAC as `X-Request-Actor`; the tenant accepts that header only when the request is authenticated with its admin key. Other callers cannot rotate this header to evade their actor budget. Master-key callers without a trusted actor header share a credential-scoped budget. Redis stores hashes, not raw credentials.

The facade separately limits and coalesces read requests through `RemoteRequestCoordinator`; see the facade's `config/remote_requests.php`. Mutating SDK operations are never automatically retried or coalesced.

## Diagnostics and validation

Redis hashes `<namespace>:{data}:stats`, `<namespace>:{management}:stats`, and `<namespace>:{orchestration}:stats` contain `admitted`, `budget_rejected`, and `concurrency_rejected` cumulative counters. They expire after 24 hours without traffic; use counter deltas to measure rates. Slot sets use the matching `:active` key; expired entries are removed on admission.

Tests use a separately started, persistence-disabled Redis. Set `TEST_RESOURCE_GUARD_REDIS_PORT` to its port and run `tests/Integration/RequestBudgetLimiterTest.php`; the test never flushes Redis and removes only its own random namespace. Pure routing-policy tests are in `tests/Unit/Services/Http/RequestBudgetPolicyTest.php`.
