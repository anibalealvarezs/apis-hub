# 📊 Data Aggregation

**APIs Hub** provides a powerful aggregation engine that allows you to perform statistical operations (SUM, AVG, COUNT, etc.) on your persisted entities. This can be done both via the **CLI** and the **REST API**.

---

## 🚀 CLI Usage

The `app:aggregate` command allows you to calculate metrics directly from the terminal.

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
  --aggregations='{"total_revenue": "SUM(e.total_price)"}' \
  --pretty
```

#### 2. Advanced Metrics with Grouping (Google Search Console)

You can group data by dimensions and apply multiple aggregations at once. Use `e.` prefix for standard fields and `m.` or `mc.` for metric/config fields in channeled metrics.

```bash
php bin/cli.php app:aggregate \
  --entity=channeled_metric \
  --channel=google_search_console \
  --aggregations='{"total_clicks": "SUM(m.value)", "avg_ctr": "AVG(mc.ctr)"}' \
  --group-by='metricDate' \
  --start-date='2024-03-01' \
  --end-date='2024-03-31' \
  --pretty
```

### Available Options

| Option | Shortcut | Description |
| :--- | :--- | :--- |
| `--entity` | `-e` | **Required.** The entity to aggregate. |
| `--aggregations` | `-a` | **Required.** JSON map of aggregations. e.g. `{"clicks": "SUM(m.value)"}` |
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
- **Channeled Entities:** `POST /{channel}/{entity}/aggregate`

### Request Payload

The API accepts a JSON body with the following structure:

```json
{
  "aggregations": {
    "total_amount": "SUM(e.amount)",
    "average_price": "AVG(e.price)"
  },
  "groupBy": ["category", "brand"],
  "filters": {
    "status": "published"
  },
  "startDate": "2024-01-01",
  "endDate": "2024-01-31"
}
```

> [!TIP]
> You can also pass control parameters (like `aggregations` or `groupBy`) via query string if your body only contains `filters`.

### Example Request (cURL)

```bash
curl -X POST https://api.yourdomain.com/shopify/channeled_order/aggregate \
     -H "Content-Type: application/json" \
     -H "X-API-Key: your_key" \
     -d '{
       "aggregations": {"gross_sales": "SUM(e.total_price)"},
       "groupBy": ["platformCreatedAt"],
       "startDate": "2024-02-01"
     }'
```

---

## 🧠 Advanced Concepts

### 1. Intelligent Metric Formulas

For `channeled_metric` entities, you no longer need to write raw SQL for common metrics. The system provides **pre-calculated formulas** that ensure mathematical correctness (e.g., using weighted averages for rates).

| Formula | Description | Calculation Logic |
| :--- | :--- | :--- |
| `spend` | Total Spend | `SUM(value)` where name is "spend" |
| `clicks` | Total Clicks | `SUM(value)` where name is "clicks" |
| `impressions` | Total Impressions | `SUM(value)` where name is "impressions" |
| `reach` | Total Reach | `SUM(value)` where name is "reach" |
| `frequency` | Ad Frequency | `SUM(impressions) / SUM(reach)` |
| `ctr` | Click-Through Rate | `SUM(clicks) / SUM(impressions)` |
| `cpc` | Cost Per Click | `SUM(spend) / SUM(clicks)` |
| `cpm` | Cost Per Mille | `SUM(spend) / (SUM(impressions) / 1000)` |
| `position` | Weighted Position | `SUM(position * impressions) / SUM(impressions)` |

**Example:**

```bash
php bin/cli.php app:aggregate -e channeled_metric -c facebook \
  -a '{"cost":"spend", "CTR":"ctr"}' --pretty
```

### 2. Multi-Level Filtering & Grouping

The engine intelligently traverses the 4-level architecture (`MetricConfig` -> `Metric` -> `ChanneledMetric` -> `Dimension`) automatically.

#### Level 1: Configuration Fields

You can group or filter directly by entity relationships defined in `MetricConfig`:

- `account`, `campaign`, `adGroup`, `ad`, `query`, `page`, `country`, `device`.

#### Level 4: Dynamic Dimensions

Filter or group by granular platform dimensions using the `dimensions.` prefix:

- `dimensions.gender`, `dimensions.age`, `dimensions.searchAppearance`, etc.

**Example (Segmented Analysis):**

```bash
php bin/cli.php app:aggregate -e channeled_metric -c facebook \
  -a '{"gasto":"spend"}' \
  -g 'account,dimensions.gender' \
  -f '{"dimensions.age":"45-54"}' --pretty
```

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

When aggregating by a temporal alias (e.g., `daily`), the engine automatically performs **Gap Filling**. If a specific day or month has no data, the system will inject a "zeroed" record to ensure a continuous series.

> [!IMPORTANT]
> Smoothing is automatically triggered when using a temporal alias in `groupBy` and providing both `--start-date` and `--end-date`.

**Example (Continuous Daily Chart Data):**

```bash
php bin/cli.php app:aggregate -e channeled_metric -c google_search_console \
  -a '{"clicks":"clicks"}' \
  -g 'daily' \
  -s '2026-03-01' -d '2026-03-31' --pretty
```

---

## 🧪 Error Handling

- **Missing Aggregations:** Returns `400 Bad Request`.
- **Invalid SQL Expression:** Returns `500 Internal Server Error` with Doctrine's message.
- **Invalid Entity/Channel:** Returns `404 Not Found`.
- **Dimension Mismatch:** If a dimension filter is applied to an entity that doesn't support it, the filter is ignored or returns empty results depending on the join type.
