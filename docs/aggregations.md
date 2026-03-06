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

### Channeled Metrics Logic

When aggregating `channeled_metric` entities, the hub automatically performs joins with the `metrics` and `metric_configs` tables. This allows you to use specific aliases in your aggregation expressions:

- `e.`: The main `channeled_metrics` table.
- `m.`: The `metrics` table (contains `value`).
- `mc.`: The `metric_configs` table (contains metadata and settings).

### Dimensional Grouping

If your entity uses `channeled_metric_dimensions`, you can group by dimensions using the `dimensions.NAME` syntax in the CLI or API:

```json
"groupBy": ["dimensions.page", "dimensions.country"]
```

---

## 🧪 Error Handling

- **Missing Aggregations:** Returns `400 Bad Request`.
- **Invalid SQL Expression:** Returns `500 Internal Server Error` with Doctrine's message.
- **Invalid Entity/Channel:** Returns `404 Not Found`.
