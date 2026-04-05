# 📊 Data Aggregation

**APIs Hub** provides a powerful aggregation engine that allows you to perform statistical operations (SUM, AVG, COUNT, etc.) on your persisted entities. This can be done both via the **CLI** and the **REST API**.

---

## 🚀 CLI Usage

The `app:aggregate` command is the main entry point to calculate metrics directly from the terminal.

### Basic Syntax

```bash
php bin/cli.php app:aggregate --entity=<entity_name> --aggregations='{"alias": "FUNCTION(field)"}'
```

### Examples

#### 1. Total Revenue from Shopify Orders

```bash
php bin/cli.php app:aggregate \
  --entity=channeled_order \
  --channel=shopify \
  --aggregations='{"total_revenue": "SUM(total_price)"}' \
  --pretty
```

#### 2. Advanced Multi-Channel Metrics with Grouping

The engine is platform-agnostic. You can group data by dimensions and apply multiple aggregations for any provider (Facebook, Google Search Console, etc.).

```bash
# Example for Facebook Ads
php bin/cli.php app:aggregate \
  --entity=channeled_metric \
  --channel=facebook \
  --aggregations='{"spend": "spend", "ctr": "ctr"}' \
  --group-by='monthly' \
  --pretty

# Example for Google Search Console
php bin/cli.php app:aggregate \
  --entity=channeled_metric \
  --channel=google_search_console \
  --aggregations='{"clicks": "clicks", "avg_position": "position"}' \
  --group-by='query' \
  --pretty
```

### Available Options

| Option | Shortcut | Description |
| :--- | :--- | :--- |
| `--entity` | `-e` | **Required.** The entity to aggregate. |
| `--aggregations` | `-a` | **Required.** JSON map of aggregations. e.g. `{"clicks": "clicks"}` |
| `--channel` | `-c` | Optional. The channel for the entity (for channeled entities). |
| `--group-by` | `-g` | Optional. Comma separated list of fields to group by. |
| `--start-date` | `-s` | Optional. Filter by date (if the entity supports it). |
| `--end-date` | `-d` | Optional. Filter by date. |
| `--filters` | `-f` | Optional. JSON object for standard field filtering. |
| `--pretty` | | Pretty print the JSON output. |

---

## 🌐 API Usage

Aggregations are exposed via `POST` endpoints to allow complex payloads.

### Endpoints

- **Standard Entities:** `POST /entity/{entity}/aggregate`
- **Managed Metrics:** `POST /{channel}/{entity}/aggregate`

### Request Payload

The API accepts a JSON body with the following structure:

```json
{
  "aggregations": {
    "total_spend": "spend",
    "avg_ctr": "ctr"
  },
  "groupBy": ["monthly", "account"],
  "filters": {
    "dimensions.gender": "female"
  },
  "startDate": "2024-01-01",
  "endDate": "2024-01-31"
}
```

> [!TIP]
> You can also pass control parameters (like `aggregations` or `groupBy`) via query string if your body only contains `filters`.

---

## 🧠 Advanced Concepts

### 1. Unified Metric Formulas

For metrics aggregation, the system provides **Intelligent Formulas** that ensure mathematical correctness (e.g., using weighted averages for rates instead of simple sums). These are universal across all supported platforms.

| Formula | Description | Platform Support |
| :--- | :--- | :--- |
| `spend` | Total Spend | Facebook, etc. |
| `clicks` | Total Clicks | Facebook, Google, etc. |
| `impressions` | Total Impressions | Facebook, Google, etc. |
| `reach` | Unique Reach | Facebook, etc. |
| `frequency` | Ad Frequency (Weighted) | Facebook, etc. |
| `ctr` | Click-Through Rate (Weighted) | Facebook, Google, etc. |
| `cpc` | Cost Per Click (Weighted) | Facebook, etc. |
| `cpm` | Cost Per Mille (Weighted) | Facebook, etc. |
| `position` | Mean Position (Weighted) | Google, etc. |

### 2. Intelligent Filtering & Grouping

The engine automatically bridges different levels of the data architecture to allow filtering and grouping by both high-level configurations and low-level granular dimensions.

#### Global Entities

You can group or filter directly by these common high-level attributes:

- `account`, `campaign`, `adGroup`, `ad`, `query`, `page`, `country`, `device`.

#### Granular Platform Dimensions

Filter or group by platform-specific granular breakdowns using the `dimensions.` prefix:

- `dimensions.gender`, `dimensions.age`, `dimensions.searchAppearance`, etc.

**Example (Segmented Analysis):**

```bash
php bin/cli.php app:aggregate -e channeled_metric -c facebook \
  -a '{"cost":"spend"}' \
  -g 'account,dimensions.gender' \
  -f '{"dimensions.age":"45-54"}' --pretty
```

### 1.5. PostgreSQL Compatibility & Column Names (v1.4.0 Standard)

Since v1.4.0, APIs Hub has adopted **PostgreSQL** as the primary database for production. This introduces key naming requirements to ensure query stability:

- **Mandatory snake_case**: All column names in standard and channeled entities MUST be used in `snake_case` within your aggregation queries (e.g., use `campaign_id` instead of `campaignId`).
- **JSONB Traversal**: To filter or group by data stored inside JSONB columns (like the `dimensions` field), use the following syntax:
  - **Syntax**: `dimensions.your_key`
  - **Example**: `groupBy: ["dimensions.gender"]`
- **Case Sensitivity**: PostgreSQL is case-sensitive for unquoted identifiers. Our aggregation engine automatically handles standard quotes, but ensure your JSON keys in `aggregations` and `filters` strictly match the database schema.

---

## 📅 Time Series & Smoothing

### Temporal Aggregation

The engine supports built-in temporal aliases to easily format and group data by time periods.

| Alias | Description | Output Format |
| :--- | :--- | :--- |
| `daily` | Full date | `YYYY-MM-DD` |
| `weekly` | ISO Week | `YYYY-Www` |
| `monthly` | Year and Month | `YYYY-MM` |
| `quarterly` | Year and Quarter | `YYYY-QX` |
| `yearly` | Full Year | `YYYY` |

### Gap Filling (Smoothing)

When aggregating by a temporal alias (e.g., `daily`), the engine automatically performs **Gap Filling**. If a specific day or month has no data, the system will inject a "zeroed" record to ensure a continuous series, essential for charting.

> [!IMPORTANT]
> Smoothing is automatically triggered when using a temporal alias in `groupBy` and providing both `--start-date` and `--end-date`.

---

## 🧪 Error Handling

- **Missing Aggregations:** Returns `400 Bad Request`.
- **Invalid Entity/Channel:** Returns `404 Not Found`.
- **Security Restriction:** Direct access to raw data fields like `value` in metrics is restricted to prevent data corruption. Users must use named formulas.
- **Dimension Mismatch:** If a dimension filter is applied to an entity that doesn't support it, the filter is ignored or returns empty results depending on the join type.
