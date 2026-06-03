# Analytics Engine Integration Guide

This document outlines the standard operating procedure and API contract for utilizing the external Python Analytics Engine via the APIs Hub and Facade.

## Architecture & Responsibilities

The architecture is strictly decoupled into three layers:
1. **APIs Hub Facade**: Manages the user interface, custom KPI formulas, and API keys. It constructs the AST payload and targets the engine via the `analytics_engine_host` variable.
2. **APIs Hub Worker**: Acts as the data hydrator and translation proxy. It translates the AST into SQL aggregations, performs temporal gap-filling to ensure continuous series, and formats the output specifically for Pydantic schema validation.
3. **Python Analytics Engine**: A purely mathematical REST API (usually FastAPI) that receives strictly formatted JSON payloads, computes advanced statistics, and returns the result.

## Required Payload Structure (Facade -> APIs Hub)

When sending a request from the Facade to the APIs Hub `compute-kpi` endpoint to utilize the analytics engine (e.g., for regression), the payload must be formatted as follows:

```json
{
  "ast": {
    "type": "operator",
    "operator": "/", // Serves as the Bridge to split Dependent vs Independent
    "left": {
      "type": "metric",
      "metric": "facebook_marketing.spend"
    },
    "right": {
      "type": "operator",
      "operator": "+",
      "left": {
        "type": "metric",
        "metric": "facebook_marketing.clicks"
      },
      "right": {
        "type": "metric",
        "metric": "google_search_console.clicks"
      }
    }
  },
  "filters": {
    "startDate": "YYYY-MM-DD",
    "endDate": "YYYY-MM-DD",
    "groupBy": ["daily"]
  },
  "calculate_regression": true,
  "admin_api_key": "YOUR_API_KEY",
  "analytics_engine_host": "https://analytics.apis-hub.cloud/"
}
```

### Critical Rules
- **Temporal Grouping**: You MUST use `"groupBy": ["daily"]` (or weekly/monthly) to trigger the `AggregationPlanner`'s temporal gap-filling logic. Do not use `"metricDate"`.
- **AST Bridging**: When `calculate_regression` is true, the **root AST operator** does not perform math. Instead, it acts as a structural bridge:
  - `left` node becomes the `dependent_var` (Y-axis).
  - `right` node becomes the `independent_vars` (X-axis).
- **Sparse Data Handling**: The APIs Hub worker will automatically drop any date index where either the dependent or independent variable contains a `0` or `null`. A minimum of 2 overlapping, non-zero days are required to successfully forward the payload to the Python engine.

## Payload Structure (APIs Hub -> Python Engine)

The APIs Hub worker automatically translates the above request into the strict dictionary/series schema required by the Python engine's Pydantic validators:

```json
{
  "dependent_var": {
    "dates": ["2026-05-26", "2026-05-27"],
    "values": [1.5, 1.2]
  },
  "independent_vars": {
    "x1": {
      "dates": ["2026-05-26", "2026-05-27"],
      "values": [51, 60]
    }
  }
}
```

## Adding New Statistical Endpoints

To add a new statistical calculation (e.g., `autocorrelation`):
1. **Python Engine**: Create a new FastAPI route (e.g., `POST /api/v1/stats/autocorrelation`) utilizing the exact same Pydantic schema payload structure shown above.
2. **APIs Hub Worker**: Update `AnalyticsController.php` to accept a new trigger flag (e.g., `"calculate_autocorrelation": true`) and forward the standardized payload to the new route.
3. **Facade**: Update the UI/API client to toggle the new flag when constructing the `computeKpi` payload.
