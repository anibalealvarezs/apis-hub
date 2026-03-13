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

The application uses a unified configuration system centered at the `config/` directory. It supports **Environment Variable Interpolation** and **Multi-Environment Blocks**.

1. **Create your config**: Copy the `.example` files from `config/` to their `.yaml` counterparts.
   - `database.yaml`: Your DB credentials.
   - `security.yaml`: API keys and IP whitelists.
   - `app.yaml`: Global project settings.
   - `instances_rules.yaml`: Rules for automatic worker generation.
2. **Set Environment Variables**: You can override any value in the YAML using environment variables. The YAML uses `${VAR:-default}` syntax.
3. **Switch Environments**: Set the `APP_ENV` variable to switch between `testing` (default) and `production` database blocks.

> [!IMPORTANT]
> A `ConfigurationException` will be thrown if mandatory YAML files are missing, ensuring you don't start the app with an incomplete setup.

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

The `config/` directory is the single source of truth. It allows you to define:

- **Database Contexts**: Separate credentials for local development vs. Docker containers.
- **Worker Instances**: Define which channels and entities each worker should process.
- **Channel Rules**: Define which channels are enabled and their history depth in `config/instances_rules.yaml`.
- **Project Overrides**: Specific settings can be placed in `config/projects/{project-name}/`.

All values in the YAML can be dynamic. For example, `host: ${DB_HOST:-127.0.0.1}` will use the `DB_HOST` environment variable if present, otherwise it defaults to `127.0.0.1`.

---

## 🔐 Security & Access

To protect your endpoints, the application supports three layers of validation:

### 1. IP Whitelisting

Restricts access to specific IP addresses or CIDR blocks. Configure it in `project.yaml`:

```yaml
security:
  authorized_ips:
    - "127.0.0.1"
    - "192.168.1.0/24"
```

### 2. Token-Based Authentication

Supports both standard and industry-standard headers. Define keys in `project.yaml` or via the `APP_API_KEY` environment variable:

```yaml
security:
  api_keys:
    - "your-secret-key-1"
```

The API accepts two types of headers for these keys:

- **X-API-Key:** `X-API-Key: your-secret-key-1`
- **Bearer Token:** `Authorization: Bearer your-secret-key-1`

### 🛡️ Validation Logic

- **IP Check:** If `authorized_ips` is not empty, the client IP must match at least one entry.
- **Token Check:** If `api_keys` (or `APP_API_KEY`) is defined, the request must include a valid key in either header.
- **Combined:** If both are configured, the request must pass both layers.
- **Public:** If no security is configured, the API is public (use with caution).

---

## 📖 Documentation

Detailed guides for setting up and maintaining the infrastructure:

- [External Database Preparation](docs/external-db-prep.md)
- [Infrastructure & Health Checks](docs/health-checks.md)
- [Job Manipulation & Lifecycle](docs/job-manipulation.md)
- [Data Aggregation Guide](docs/aggregations.md)
- [Cloud Architecture & Data Access Strategy](docs/cloud-architecture.md)
- [Deployment Checklist](docs/deployment-checklist.md)

## 🛠 Installation & Deployment

The easiest way to deploy the entire stack is using the built-in orchestrator:

```bash
# Fully automated deployment
bash bin/full-deploy.sh
```

This script automates environment validation, dependency installation, instance regeneration, and container orchestration.

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

# Aggregate data (e.g. Total Revenue from Shopify Orders)
php bin/cli.php app:aggregate -e channeled_order -c shopify -a '{"revenue": "SUM(e.total_price)"}' --pretty
```

## 🧠 Core Architecture Highlights

### The Caching Philosophy (Redis)

We heavily rely on Redis caching to prevent API explosions and respect strict third-party Rate Limits:

- **Instant Hash Matching:** Sequential API or Console requests using the same URL parameters natively hit Redis in under `5ms`.
- **Body Filter Bypass:** To avoid Memory exhaustion, any specific request containing `filters` in their HTTP Body completely bypasses Redis, polling data synchronously for specialized needs.
- **Auto-Invalidation:** Every Create, Update, or Delete operation executed against a repository implicitly runs a SCAN wildcard deletion on Redis arrays preventing stale views.

APIs Hub is designed to scale horizontally through **dedicated worker containers**. Each worker is a specialized instance focused on a specific Channel and Entity.

### ⚡ Rapid Deployment Workflow

Follow these steps to generate dozens of optimized workers in seconds:

#### 1. Configure Rules

In `config/instances_rules.yaml`, define which channels you want to process and how much historical data to pull:

```yaml
rules:
  facebook_marketing:
    enabled: true
    history_months: 24  # Pull 2 years of history
  gsc:
    enabled: true
    history_months: 16  # Pull 16 months of history
```

#### 2. Auto-Generate Instances

Run the refresher to calculate quarterly splits, dependency chains, and staggered cron schedules:

```bash
# Regenerates config/instances.yaml based on your rules
php bin/cli.php app:refresh-instances
```

#### 3. Build Infrastructure

The deployment builder will convert your instance list into a production-ready Docker Compose file:

```bash
# Usage: php bin/build-deployment.php
php bin/build-deployment.php
```

This script injects all necessary environment variables, database credentials, and port mappings automatically.

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

## 💖 Support & Sponsorship

APIs Hub is high-quality, free-to-use software maintained with ❤️. If you or your company benefit from the project, please consider supporting its ongoing development:

- **[Sponsor on GitHub](https://github.com/sponsors/anibalealvarezs)**
- **Star the repo** to help with visibility!
- **Contribute code** by opening a Pull Request.

Your support helps cover hosting costs and the time spent adding new adapters and features.

## 📄 License

This project is licensed under the **MIT License** - see the [LICENSE](LICENSE) file for details.

---

*Maintained with ❤️ for elite Data Analytics pipelines.*
