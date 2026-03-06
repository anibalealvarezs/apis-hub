# 🚀 APIs Hub

**A headless data extraction and caching engine tailored for Analytics and E-commerce integrations.**

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

The application uses a unified configuration system centered at `deploy/project.yaml`. It supports **Environment Variable Interpolation** and **Multi-Environment Blocks**.

1. **Create your config**: Copy `deploy/project.yaml.example` to `deploy/project.yaml`.
2. **Set Environment Variables**: You can override any value in the YAML using environment variables. The YAML uses `${VAR:-default}` syntax.
3. **Switch Environments**: Set the `APP_ENV` variable to switch between `testing` (default) and `production` database blocks.

#### Common Environment Variables

```env
# Environment Switching (defaults to 'testing')
APP_ENV=testing

# Database Overrides (Testing Block)
DB_HOST=127.0.0.1
DB_NAME=apis-hub-testing

# Database Overrides (Production Block)
PROD_DB_HOST=host.docker.internal
PROD_DB_NAME=apis-hub-production

# Redis Configuration
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Security Key for your API
APP_API_KEY=your-super-secure-random-key
```

---

## 🏗 Configuration Architecture

The `deploy/project.yaml` is the single source of truth. It allows you to define:

- **Database Contexts**: Separate credentials for local development vs. Docker containers.
- **Worker Instances**: Define which channels and entities each worker should process.
- **Channel Credentials**: Centralized storage for API keys (Google, FB, Shopify, etc.).

All values in the YAML can be dynamic. For example, `host: ${DB_HOST:-127.0.0.1}` will use the `DB_HOST` environment variable if present, otherwise it defaults to `127.0.0.1`.

---

## 🔐 Security & Access

To protect your public endpoints on Google Cloud, the application requires an **API Key** if the environment variable `APP_API_KEY` is set.

All requests must include the following header:

- **Header Name:** `X-API-Key`
- **Header Value:** The value you defined in `APP_API_KEY`.

If the header is missing or incorrect, the API will return a `401 Unauthorized` response.

---

## 📖 Documentation

Detailed guides for setting up and maintaining the infrastructure:

- [External Database Preparation](docs/external-db-prep.md)
- [Infrastructure & Health Checks](docs/health-checks.md)
- [Job Manipulation & Lifecycle](docs/job-manipulation.md)
- [Cloud Architecture & Data Access Strategy](docs/cloud-architecture.md)

## 🛠 Installation

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

## 🏗 Worker Containerization & Deployment

APIs Hub is designed to scale horizontally through **dedicated worker containers**. Each worker is a specialized instance focused on a specific Channel and Entity.

### 1. Defining Instances

In your `deploy/project.yaml`, you define a list of `instances`. Each instance becomes a standalone Docker service:

```yaml
instances:
  - name: gsc-jan    # Docker service name
    port: 8081       # External port (optional)
    channel: gsc     # Integration channel
    entity: metric   # Data entity
    start_date: "..."
    end_date: "..."
```

### 2. Generating Infrastructure

Instead of writing complex `docker-compose.yml` files manually, use the built-in deployment builder:

```bash
# Usage: php bin/build-deployment.php <project-filename-without-extension>
php bin/build-deployment.php project
```

This script reads your YAML and automatically generates a `docker-compose.yml` tailored to your defined instances, injecting all necessary environment variables and database credentials.

### 3. Execution

Once generated, deploy your cluster with standard Docker commands:

```bash
docker compose up -d --build
```

Each container will boot up, initialize its specific context, and begin processing the data pipeline according to its configuration.

## 🧪 Testing

The codebase relies exclusively on PHPUnit for testing business logic, Repositories, API conversions, and Commands.

```bash
# Run the entire test suite
vendor/bin/phpunit tests/

# Run Benchmarks
./vendor/bin/phpbench run tests/Benchmark --report='default'
```

---

*Maintained with ❤️ for elite Data Analytics pipelines.*
