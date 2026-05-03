# APIs Hub Memory
## Scope
- Package role: Orchestration (Worker)
- Purpose: This package operates within the Orchestration (Worker) layer of the APIs Hub SaaS hierarchy, coordinating caching, normalization, persistence, and data aggregation across source integrations.
- Dependency stance: Consumes `anibalealvarezs/api-client-skeleton`, `anibalealvarezs/api-driver-core`, `anibalealvarezs/facebook-graph-api`, `anibalealvarezs/google-api`, `anibalealvarezs/google-hub-driver`, `anibalealvarezs/meta-hub-driver`, `anibalealvarezs/klaviyo-hub-driver`, `anibalealvarezs/shopify-hub-driver`, `anibalealvarezs/netsuite-hub-driver`, `anibalealvarezs/amazon-hub-driver`, `anibalealvarezs/bigcommerce-hub-driver`, `anibalealvarezs/pinterest-hub-driver`, `anibalealvarezs/linkedin-hub-driver`, `anibalealvarezs/tiktok-hub-driver`, `anibalealvarezs/x-hub-driver`, and `anibalealvarezs/triple-whale-hub-driver`; serves the APIs Hub Facade and cached-data consumers.
## Local working rules
- Consult `AGENTS.md` first for package-specific instructions.
- Use this `MEMORY.md` for repository-specific decisions, learnings, and follow-up notes.
- Use `D:\laragon\www\_shared\AGENTS.md` and `D:\laragon\www\_shared\MEMORY.md` for cross-repository protocols and workspace-wide learnings.
- Keep secrets, credentials, tokens, and private endpoints out of this file.
## Current notes
- Orchestrator owns caching, normalization, persistence, and aggregation.
- Prefer Doctrine-managed schema changes for index work; avoid direct manual `CREATE INDEX` unless there is explicit emergency authorization.
- Use the metric aggregation strategy abstraction and the metric profile index planner to derive candidate indexes from driver-defined profiles before materializing them in Doctrine metadata.
- Keep weighted aggregate logic behavior-driven and reusable; avoid hardcoding channel/metric names when a strategy or template can express the same rule.
- Cache strategy must tolerate empty-but-successful responses and normalize cache keys recursively so repeated heavy misses do not re-run the same aggregate workload.
- In aggregate filtering, relation keys flagged as `isAttribute` (e.g. `page_platform_id`, JSON-backed linked ids) must be filtered by their mapped attribute SQL expression, not by FK identity (`mc.<fk>`).
