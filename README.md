<div align="center">
  <h1>🚀 APIs Hub</h1>
  <p><strong>A headless data extraction and caching engine tailored for Analytics and E-commerce integrations.</strong></p>
</div>

---

## 📖 Overview

**APIs Hub** is an elite standalone service built in PHP. It is specifically designed to work as an asynchronous background worker and high-speed cache layer for complex data pipelines. It pulls, standardizes, caches, and persists data from external APIs (such as Shopify, NetSuite, Klaviyo, Google Search Console, Facebook Graph, etc.) using unified architectures.

Built under a **Serverless-ready** architecture, the project utilizes **Doctrine ORM** for its robust DBAL, **Redis** for sub-millisecond response caches, and a flexible Command-Line Interface to trigger complex background tasks smoothly.

---

## 🛠 Tech Stack

- **PHP 8.3** (CLI and Micro-routing logic)
- **Doctrine ORM** (Agnostic Database Support: MySQL / PostgreSQL)
- **Redis** (High-speed caching & stampede-prevention)
- **Symfony Console Component** (CLI Commands)

---

## 🚀 Getting Started

### 1. Prerequisites

You can run this application locally or via Docker. The easiest way is using Docker Compose. Make sure you have installed:

- Docker & Docker Compose
- Composer (if running locally without Docker)
- PHP 8.1+ (if running bare-metal)

### 2. Environment Configuration

The application gracefully falls back to YAML files, but the absolute **best practice** is managing configurations using Environment Variables.

Copy the example configuration or create your own `.env` file structure matching the keys used in `docker-compose.yml`:

```env
DB_DRIVER=pdo_mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=root
DB_PASSWORD=secret
DB_NAME=apis-hub

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Example of JSON injected channel config
CHANNELS_CONFIG={"shopify":{"enabled":true,"api_key":"your-key", ...}}
```

### 3. Database Initialization

Before running queries, initialize your Cloud/Local Database schema with these idempotent commands:

```bash
# 1. Start the Docker services (Redis + DB + PHP)
docker-compose up -d

# 2. Update schema structure
docker-compose exec app php bin/cli.php orm:schema-tool:update --force

# 3. Seed initial mandatory entities
docker-compose exec app php bin/cli.php app:initialize-entities
```

---

## 🎮 CLI Usage (Commands)

APIs Hub exposes a powerful set of CLI commands intended to be used by cronjobs, scheduled tasks, or manual debugging.

### ✨ Asynchronous Caching

Schedule async extraction jobs into the queue natively from your console:

```bash
# Basic request: Schedule a cache sync for Shopify Products
php bin/cli.php app:cache shopify products

# With optional URL Query Parameters (JSON or Query-String parsing supported)
php bin/cli.php app:cache facebook metric --params="startDate=2025-07-19&endDate=2025-07-31"

# With optional Payload Body (For POST-like filtering structures)
php bin/cli.php app:cache klaviyo customers --data='{"some_flag": true}'
```

### 📚 Entity CRUD Tooling

Retrieve parsed data and list records bypassing the standard HTTP layout:

```bash
# Read a basic entity bypassing cache
php bin/cli.php app:read -e jobs -i 1

# Read a channeled record (Google Search Console metric) with parameters
php bin/cli.php app:read -c google_search_console -e metric -p "limit=100&pagination=3&rawData=1"
```

## 🧠 Core Architecture Highlights

### The Caching Philosophy (Redis)

We heavily rely on Redis caching to prevent API explosions and respect strict third-party Rate Limits:

- **Instant Hash Matching:** Sequential API or Console requests using the same URL parameters natively hit Redis in under `5ms`.
- **Body Filter Bypass:** To avoid Memory exhaustion, any specific request containing `filters` in their HTTP Body completely bypasses Redis, polling data synchronously for specialized needs.
- **Auto-Invalidation:** Every Create, Update, or Delete operation executed against a repository implicitly runs a SCAN wildcard deletion on Redis arrays preventing stale views.

### Distributed Workers Support

Because state depends solely on Environment Variables and persistence falls to decoupled MySQL/Redis shards, you can confidently run **10 concurrent instances/containers** of `APIs Hub` to drastically accelerate your job ingestion times.

---

## 🧪 Testing

The codebase relies exclusively on PHPUnit for testing business logic, Repositories, API conversions, and Commands.

```bash
# Run the entire test suite
vendor/bin/phpunit tests/

# Run Benchmarks
./vendor/bin/phpbench run tests/Benchmark --report='default'
```

---

<div align="center">
  <i>Maintained with ❤️ for elite Data Analytics pipelines.</i>
</div>
