import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { SSEServerTransport } from "@modelcontextprotocol/sdk/server/sse.js";
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
  ListResourcesRequestSchema,
  ReadResourceRequestSchema,
} from "@modelcontextprotocol/sdk/types.js";
import { exec } from "child_process";
import { promisify } from "util";
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";
import dotenv from "dotenv";
import express from "express";
import cors from "cors";
import waitPort from "wait-port";
import crypto from "crypto";

const execAsync = promisify(exec);

// Path logic for portability
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const APIS_HUB_ROOT = path.resolve(__dirname, "..");

/**
 * Execute a PHP CLI command through Docker Compose if available,
 * otherwise execute directly (useful for different environments).
 */
async function runCliCommand(command) {
  try {
    const { stdout, stderr } = await execAsync(command, {
      cwd: APIS_HUB_ROOT,
    });
    return stdout || stderr;
  } catch (directError) {
    if (directError.stdout || directError.stderr) {
      return (directError.stdout || "") + "\n" + (directError.stderr || "");
    }
    const dockerPrefix = "docker compose exec -T master";
    const fullCommand = `${dockerPrefix} ${command}`;

    try {
      const { stdout, stderr } = await execAsync(fullCommand, {
        cwd: APIS_HUB_ROOT,
      });
      return stdout || stderr;
    } catch (innerError) {
      if (innerError.stdout || innerError.stderr) {
        return (innerError.stdout || "") + "\n" + (innerError.stderr || "");
      }
      throw new Error(`Command failed: ${directError.message || innerError.message}`);
    }
  }
}

// Load .env relative to project root
dotenv.config({ path: path.join(APIS_HUB_ROOT, ".env") });

/**
 * Helper to call Facade internal API endpoints with bearer authentication and HMAC signature.
 */
async function callFacadeInternal(endpoint, method = "GET", body = null) {
  let facadeBase = process.env.APIS_HUB_FACADE_URL || process.env.MONITOR_FACADE_URL;
  if (!facadeBase) {
    facadeBase = "http://localhost:8001";
  }

  // If facadeBase points to /api/heartbeat or other sub-paths, strip to root
  try {
    const parsed = new URL(facadeBase);
    facadeBase = `${parsed.protocol}//${parsed.host}`;
  } catch (e) {
    // Leave as is if URL parse fails
  }

  const url = `${facadeBase}${endpoint.startsWith("/") ? endpoint : "/" + endpoint}`;
  const secret = (process.env.ADMIN_API_KEY || process.env.APP_API_KEY || "").trim();
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const serializedBody = body ? JSON.stringify(body) : "";

  const payloadToSign = `${timestamp}.${serializedBody}`;
  const signature = crypto.createHmac("sha256", secret).update(payloadToSign).digest("hex");

  const headers = {
    Authorization: `Bearer ${secret}`,
    "X-Tenant-Signature": signature,
    "X-Tenant-Timestamp": timestamp,
    "Content-Type": "application/json",
    Accept: "application/json",
  };

  const response = await fetch(url, {
    method,
    headers,
    body: method !== "GET" && method !== "HEAD" ? serializedBody : undefined,
  });

  const responseText = await response.text();
  let responseData;
  try {
    responseData = JSON.parse(responseText);
  } catch (e) {
    responseData = { raw: responseText };
  }

  if (!response.ok) {
    const errorMsg =
      responseData.message ||
      (responseData.errors ? JSON.stringify(responseData.errors) : `HTTP ${response.status}: ${response.statusText}`);
    const err = new Error(errorMsg);
    err.status = response.status;
    err.data = responseData;
    throw err;
  }

  return responseData;
}

/**
 * Authentication and Security Middleware Helper
 * Extracts API key from Bearer token, custom headers, or query parameters.
 */
function extractAuth(req) {
  let token = null;

  // 1. Authorization: Bearer <key>
  const authHeader = req.headers["authorization"] || req.headers["Authorization"];
  if (authHeader && typeof authHeader === "string") {
    const parts = authHeader.split(" ");
    if (parts.length === 2 && /^Bearer$/i.test(parts[0])) {
      token = parts[1];
    } else {
      token = authHeader;
    }
  }

  // 2. Custom headers: X-API-Key or X-Admin-API-Key
  if (!token) {
    token = req.headers["x-api-key"] || req.headers["x-admin-api-key"] || req.headers["api-key"];
  }

  // 3. Query string fallback: key, api_key, token
  if (!token && req.query) {
    token = req.query.key || req.query.api_key || req.query.token;
  }

  if (Array.isArray(token)) token = token[0];
  if (token) token = token.trim();

  const adminKey = (process.env.ADMIN_API_KEY || "").trim();
  const appKey = (process.env.APP_API_KEY || "").trim();

  // If no keys configured in .env, default to admin for development safety
  if (!adminKey && !appKey) {
    return { authenticated: true, role: "admin", key: token || "dev-unrestricted" };
  }

  if (token && adminKey && token === adminKey) {
    return { authenticated: true, role: "admin", key: token };
  }

  if (token && appKey && token === appKey) {
    return { authenticated: true, role: "user", key: token, userContext: null };
  }

  // 4. Validate against user-scoped API keys (config/user_keys.json)
  try {
    const userKeysPath = path.join(APIS_HUB_ROOT, "config", "user_keys.json");
    if (fs.existsSync(userKeysPath)) {
      const rawUserKeys = fs.readFileSync(userKeysPath, "utf-8");
      const userKeys = JSON.parse(rawUserKeys);
      if (Array.isArray(userKeys)) {
        const matchingUser = userKeys.find(
          (u) => u.api_key && u.api_key.trim() === token
        );
        if (matchingUser) {
          return {
            authenticated: true,
            role: "user",
            key: token,
            userContext: {
              userId: matchingUser.user_id,
              userName: matchingUser.name,
              allowedAssetGroups: matchingUser.allowed_asset_groups || [],
              allowedAssets: matchingUser.allowed_assets || {},
            },
          };
        }
      }
    }
  } catch (err) {
    console.error(`[AUTH] Error reading config/user_keys.json: ${err.message}`);
  }

  return { authenticated: false, role: null, key: token, userContext: null };
}

/**
 * Sliding Window In-Memory Rate Limiter
 * Default: 60 requests per minute per key/IP to prevent agent swarm abuse.
 */
const rateLimitMap = new Map();
const RATE_LIMIT_WINDOW_MS = 60 * 1000;
const MAX_REQUESTS_PER_WINDOW = 60;

function checkRateLimit(identifier) {
  const now = Date.now();
  const record = rateLimitMap.get(identifier) || { count: 0, resetTime: now + RATE_LIMIT_WINDOW_MS };

  if (now > record.resetTime) {
    record.count = 1;
    record.resetTime = now + RATE_LIMIT_WINDOW_MS;
  } else {
    record.count += 1;
  }

  rateLimitMap.set(identifier, record);

  const remaining = Math.max(0, MAX_REQUESTS_PER_WINDOW - record.count);
  const resetInSec = Math.ceil((record.resetTime - now) / 1000);
  const exceeded = record.count > MAX_REQUESTS_PER_WINDOW;

  return { exceeded, remaining, resetInSec };
}

// Memory Cache for heavy aggregation responses (TTL: 10 mins)
const aggregationCache = new Map();
const CACHE_TTL_MS = 10 * 60 * 1000;

function getCachedAggregation(cacheKey) {
  const item = aggregationCache.get(cacheKey);
  if (!item) return null;
  if (Date.now() > item.expiresAt) {
    aggregationCache.delete(cacheKey);
    return null;
  }
  return item.data;
}

function setCachedAggregation(cacheKey, data) {
  aggregationCache.set(cacheKey, {
    data,
    expiresAt: Date.now() + CACHE_TTL_MS,
  });
}

/**
 * Tool Definitions Segregated by Role
 */
const USER_TOOLS = [
  {
    name: "get_mcp_guide",
    description:
      "Comprehensive interaction guide and usage specifications for AI agents connecting to this APIs Hub MCP node. Explains data discovery, filtering by connected assets, temporal/dimensional breakdowns, data scopes, entity measures aggregation, custom dashboard widget authoring, error prevention, and copy-paste ready query patterns.",
    inputSchema: {
      type: "object",
      properties: {
        topic: {
          type: "string",
          description:
            "Optional: Specific guide topic ('overview', 'workflow', 'assets', 'datascopes', 'scopes', 'filters', 'breakdowns', 'formulas', 'entity_measures', 'kpi_calculation', 'derived_metrics', 'dashboards', 'benchmarks', 'temporal_comparison', 'funnel_decomposition', 'examples')",
          enum: [
            "overview",
            "workflow",
            "assets",
            "datascopes",
            "scopes",
            "filters",
            "breakdowns",
            "formulas",
            "entity_measures",
            "kpi_calculation",
            "derived_metrics",
            "dashboards",
            "benchmarks",
            "temporal_comparison",
            "funnel_decomposition",
            "examples"
          ]
        }
      }
    }
  },
  {
    name: "list_connected_assets",
    description:
      "List all connected accounts, web properties, ad profiles, and stores linked to this project node. Returns asset IDs, display names, channels, and platform identifiers needed to formulate asset-scoped queries in summarize_performance.",
    inputSchema: {
      type: "object",
      properties: {
        channel: {
          type: "string",
          description:
            "Optional: Filter connected assets by channel (e.g. 'google_search_console', 'google_analytics', 'facebook_marketing', 'shopify', 'klaviyo', 'mailchimp', 'amazon', 'tiktok')"
        }
      }
    }
  },
  {
    name: "get_analytics_catalog",
    description:
      "Introspect and discover the full analytics capabilities of APIs Hub, including the exact current audited version of the metrics catalog for each channel ('catalog_versions' and 'channel_metrics_catalog' with contract_version, query_contract_version, minimum_release, audited_at, per-metric reducers, units, additivity, scopes, UI dependencies, dimensions, and caveats), data scopes (scope_global, scope_channel, scope_asset), supported channels and tags, canonical metrics, formula-based derived metrics, entity_measures (10 cached entity types with Two-Stage measures, dimensions, canonical deduplication, and multi-currency rules), allowed temporal/non-temporal breakdowns, and the platform-wide reference library of predefined KPIs and Derived Metrics.",
    inputSchema: {
      type: "object",
      properties: {
        section: {
          type: "string",
          description: "Optional: Specific catalog section to inspect ('catalog_versions', 'channel_metrics_catalog', 'reference_library', 'predefined_kpis', 'predefined_derived_metrics', 'entity_measures', 'benchmarks', 'data_scopes', 'canonical_metrics', 'derived_formulas')",
          enum: ["catalog_versions", "channel_metrics_catalog", "reference_library", "predefined_kpis", "predefined_derived_metrics", "entity_measures", "benchmarks", "data_scopes", "canonical_metrics", "derived_formulas"]
        },
        scope: {
          type: "string",
          description: "Optional: Filter catalog by data scope ('global', 'channel', 'asset')",
          enum: ["global", "channel", "asset"]
        },
        channel: {
          type: "string",
          description: "Optional: Channel identifier to inspect its exact versioned metrics catalog, contract_version, scopes, dimensions, and caveats (e.g. 'google_search_console', 'google_analytics', 'google_ads', 'facebook_marketing', 'facebook_organic', 'mailchimp', 'shopify', 'klaviyo')"
        }
      }
    }
  },
  {
    name: "list_custom_kpis",
    description:
      "List the custom KPIs and calculated derived metrics specifically defined for this project. Returns KPI names, calculation formulas (AST / math expression), descriptions, and filtering criteria.",
    inputSchema: {
      type: "object",
      properties: {}
    }
  },
  {
    name: "list_project_dashboards",
    description:
      "List all configured project dashboards and their constituent widgets. Returns dashboard names, layout, widget titles, selected metrics/KPIs, source channels, and visualization controls so agents can answer questions regarding active dashboards.",
    inputSchema: {
      type: "object",
      properties: {
        dashboard_id: {
          type: "number",
          description: "Optional: Specific dashboard ID to inspect its detailed widget configurations"
        }
      }
    }
  },
  {
    name: "list_configured_alerts",
    description:
      "List active threshold alerts, schedule triggers, and monitoring rules configured in the project. Returns alert names, source metrics, upper/lower limit thresholds, and schedule evaluation status.",
    inputSchema: {
      type: "object",
      properties: {
        alert_id: {
          type: "number",
          description: "Optional: Specific alert ID to inspect"
        }
      }
    }
  },
  {
    name: "summarize_performance",
    description:
      "Get aggregated performance data using Channeled Metrics and intelligent formulas (spend, clicks, ctr, cpc, cpm, roas, position, etc). Matches widget capabilities, supporting multiple data scopes, breakdowns (daily, weekly, monthly, dimensional), advanced filters, and asset group isolation.",
    inputSchema: {
      type: "object",
      properties: {
        entity: {
          type: "string",
          description:
            "The entity name (use 'channeled_metric' for performance data)",
          default: "channeled_metric"
        },
        channel: {
          type: "string",
          description:
            "The channel identifier (e.g. 'google_search_console', 'google_analytics', 'facebook_marketing', 'facebook_organic', 'shopify', 'klaviyo', 'mailchimp', 'amazon', 'tiktok')",
        },
        scope: {
          type: "string",
          description:
            "Data scope for calculation: 'global' (cross-channel / blended), 'channel' (single provider/channel), or 'asset' (specific ad account, store, or property)",
          enum: ["global", "channel", "asset"]
        },
        aggregations: {
          type: "object",
          description:
            "Object mapping alias to formula or canonical metric. Available formulas: 'spend', 'clicks', 'impressions', 'reach', 'results', 'ctr', 'cpc', 'cpm', 'roas', 'cost_per_result', 'result_rate', 'position', 'sessions', 'conversions', 'conversion_rate', 'bounce_rate'. e.g. {\"total_spend\":\"spend\",\"blended_roas\":\"roas\",\"avg_cpc\":\"cpc\"}",
        },
        filters: {
          type: "object",
          description:
            'Optional: Object containing filters or dimensions. e.g. {"channeledAccount":"2", "device":"desktop", "country":"US"}',
        },
        groupBy: {
          type: "string",
          description:
            "Comma separated fields to group by. Supports temporal granularities ('daily', 'weekly', 'monthly', 'quarterly', 'yearly') and dimensional breakdowns ('device', 'country', 'query', 'page', 'dimensions.*').",
        },
        startDate: { type: "string", description: "Start date in format Y-m-d" },
        endDate: { type: "string", description: "End date in format Y-m-d" },
      },
      required: ["entity", "aggregations"],
    },
  },
  {
    name: "aggregate_entities",
    description:
      "Execute Two-Stage dynamic Entity Measures aggregation over cached entity tables ('orders', 'customers', 'stores', 'products', 'discounts', 'campaigns', 'ad-groups', 'ads', 'posts', 'events'). Computes order counts, canonical deduplicated orders, revenue, AOV, customer LTV, repeat buyer rate, pre-purchase touches, discount usage, and campaign budgets with dimensional breakdowns, time buckets, and multi-currency safety guards. Matches custom dashboard 'entity_measure' widgets.",
    inputSchema: {
      type: "object",
      properties: {
        entity_type: {
          type: "string",
          description: "Target entity family to aggregate ('orders', 'customers', 'stores', 'products', 'discounts' / 'promotions' / 'promo-codes', 'campaigns', 'ad-groups' / 'ad-sets', 'ads', 'posts', 'events')",
          enum: ["orders", "customers", "stores", "products", "discounts", "promotions", "promo-codes", "campaigns", "ad-groups", "ad-sets", "ads", "posts", "events"]
        },
        measures: {
          type: "array",
          items: { type: "string" },
          description: "Measures to compute (e.g. for 'orders': ['order_count', 'canonical_order_count', 'unique_customers', 'revenue', 'subtotal', 'total_tax', 'total_discount', 'aov']; for 'customers': ['customer_count', 'canonical_customer_count', 'customer_ltv', 'avg_revenue_per_customer', 'repeat_customer_count', 'high_frequency_customer_count', 'repeat_buyer_rate', 'repeat_frequency', 'pre_purchase_touches'])"
        },
        dimensions: {
          type: "array",
          items: { type: "string" },
          description: "Optional dimensions to group by (e.g. ['channel'], ['store'], ['currency'], ['financial_status'], ['status'], ['vendor'], ['code'])"
        },
        time_bucket: {
          type: "string",
          description: "Optional temporal bucket for time-series trends ('day', 'week', 'month', 'year'). Omit for scalar summary or dimensional table.",
          enum: ["day", "week", "month", "year"]
        },
        channel: {
          type: "string",
          description: "Optional channel filter (e.g. 'shopify', 'mailchimp', 'klaviyo', 'facebook_marketing', 'google_ads')"
        },
        account_ids: {
          type: "array",
          items: { type: "number" },
          description: "Optional array of connected asset/account integer IDs from list_connected_assets"
        },
        deduplicate_canonical: {
          type: "boolean",
          description: "When true (default for cross-channel queries without 'channel' dimension), collapses multi-channel references to the same canonical order into a single deduplicated record before summing revenue or computing AOV.",
          default: true
        },
        filters: {
          type: "object",
          description: "Optional filter criteria (e.g. { date_from: '2026-10-01', date_to: '2026-10-31', currency: 'USD', store_id: 1, customer_id: 15, search: 'VIP' })"
        },
        limit: {
          type: "number",
          description: "Maximum rows to return (default 100, max 1000)",
          default: 100
        }
      },
      required: ["entity_type", "measures"]
    }
  },
  {
    name: "check_coverage",
    description:
      "Analyze data gap coverage for a specific channel (e.g. facebook_marketing, google_search_console)",
    inputSchema: {
      type: "object",
      properties: {
        channel: {
          type: "string",
          description: "The channel identifier",
        },
        days: {
          type: "number",
          description:
            "Optional: Number of days to look back (default 30)",
          default: 30,
        },
      },
      required: ["channel"],
    },
  },
  {
    name: "get_available_instances",
    description:
      "List configured sync instances and their active status",
    inputSchema: {
      type: "object",
      properties: {},
    },
  },
  {
    name: "get_dashboard_authoring_context",
    description:
      "Get project authoring context, active connected channels, available asset groups, supported widget/source types (including 'entity_measure' capabilities for orders, customers, stores, products, discounts, campaigns, ad-groups, ads, posts, events), the exact current per-channel metrics catalog and contract versions ('catalog_versions' and 'channel_metrics_catalog'), and current dashboard/widget billing quotas before creating or modifying a dashboard.",
    inputSchema: {
      type: "object",
      properties: {}
    }
  },
  {
    name: "read_dashboard_spec",
    description:
      "Read the full JSON specification (widgets, layout, controls, source configurations) of an existing dashboard to prepare modifications or a full rewrite.",
    inputSchema: {
      type: "object",
      properties: {
        dashboard_id: {
          type: "number",
          description: "The ID of the existing dashboard to read"
        }
      },
      required: ["dashboard_id"]
    }
  },
  {
    name: "create_dashboard_draft",
    description:
      "Create a staged dashboard draft session with full validation of widget limits, quotas, and criteria (audience, account mode). Supports 'metric', 'kpi', 'derived_metric', 'entity', and 'entity_measure' widgets (plus multi-series charts blending 'entity_measure' and 'metric' series via source_config.raw_series). Returns draft_token and preview_url for user review.",
    inputSchema: {
      type: "object",
      properties: {
        dashboard_id: {
          type: "number",
          description: "Optional: ID of existing dashboard if proposing modifications/rewrite. Omit for new dashboard."
        },
        name: {
          type: "string",
          description: "Title of the dashboard"
        },
        description: {
          type: "string",
          description: "Optional description explaining dashboard purpose"
        },
        target_audience: {
          type: "string",
          description: "Target audience for styling & metric focus",
          enum: ["client", "agency", "internal", "executive"]
        },
        account_mode: {
          type: "string",
          description: "Account grouping strategy",
          enum: ["standalone", "asset_groups", "blended"]
        },
        grid_layout: {
          type: "object",
          description: "Optional grid configuration"
        },
        widgets: {
          type: "array",
          description: "Array of widget specifications",
          items: {
            type: "object",
            properties: {
              name: { type: "string" },
              title: { type: "string" },
              description: { type: "string" },
              widget_type: {
                type: "string",
                enum: [
                  "tile",
                  "advanced_tile",
                  "metric_breakdown",
                  "stacked_area",
                  "line_chart",
                  "bar_chart",
                  "table",
                  "gauge",
                  "sparkline",
                  "anomaly_list",
                  "pie_chart",
                  "combo_chart",
                  "anomaly_chart",
                  "scatter_plot"
                ]
              },
              source_type: {
                type: "string",
                description: "Data source type: 'metric' (standard OLAP metrics or multi-series raw_series), 'entity_measure' (Two-Stage entity aggregations over orders, customers, stores, products, discounts, campaigns, ad-groups, ads, posts, events), 'kpi' (statistical KPI), 'derived_metric' (AST formula), or 'entity'",
                enum: ["kpi", "metric", "entity", "entity_measure", "derived_metric"]
              },
              source_config: {
                type: "object",
                description: "Source configuration. For source_type='entity_measure': { entity_type: 'orders'|'customers'|'stores'|'products'|'discounts'|'campaigns'|'ad-groups'|'ads'|'posts'|'events', measures: ['aov', 'revenue', ...], dimensions?: ['channel', 'store', ...], channel?: 'shopify'|'mailchimp'|..., deduplicate_canonical?: true, granularity?: 'daily'|'weekly'|'monthly'|'lifetime', filters?: { currency?: 'USD', ... } }. For multi-series blending (source_type='metric'): { raw_series: [{ type: 'entity_measure', entity_type: 'orders', measures: ['revenue'], deduplicate_canonical: true, label: 'Orders Revenue' }, { type: 'metric', channel: 'facebook_marketing', metrics: ['spend'], label: 'Ad Spend' }] }."
              },
              controls: {
                type: "object",
                description: "Optional runtime/display controls (e.g. granularity, date_start, date_end, filters, pie_style, color)"
              },
              grid_x: { type: "number" },
              grid_y: { type: "number" },
              grid_w: { type: "number" },
              grid_h: { type: "number" },
              custom_kpi_id: { type: "number" },
              derived_metric_id: { type: "number" }
            },
            required: ["name", "widget_type", "source_type"]
          }
        }
      },
      required: ["name", "widgets"]
    }
  },
  {
    name: "update_dashboard_draft",
    description:
      "Iteratively update staged widgets, layout, or metadata in an active draft session based on user feedback before committing. Supports 'metric', 'kpi', 'derived_metric', 'entity', and 'entity_measure' widgets.",
    inputSchema: {
      type: "object",
      properties: {
        draft_token: {
          type: "string",
          description: "The draft token returned by create_dashboard_draft"
        },
        name: { type: "string" },
        description: { type: "string" },
        target_audience: {
          type: "string",
          enum: ["client", "agency", "internal", "executive"]
        },
        account_mode: {
          type: "string",
          enum: ["standalone", "asset_groups", "blended"]
        },
        grid_layout: { type: "object" },
        widgets: {
          type: "array",
          items: {
            type: "object",
            properties: {
              name: { type: "string" },
              title: { type: "string" },
              description: { type: "string" },
              widget_type: {
                type: "string",
                enum: [
                  "tile",
                  "advanced_tile",
                  "metric_breakdown",
                  "stacked_area",
                  "line_chart",
                  "bar_chart",
                  "table",
                  "gauge",
                  "sparkline",
                  "anomaly_list",
                  "pie_chart",
                  "combo_chart",
                  "anomaly_chart",
                  "scatter_plot"
                ]
              },
              source_type: {
                type: "string",
                description: "Data source type: 'metric', 'entity_measure', 'kpi', 'derived_metric', or 'entity'",
                enum: ["kpi", "metric", "entity", "entity_measure", "derived_metric"]
              },
              source_config: {
                type: "object",
                description: "Source configuration (for 'entity_measure': { entity_type, measures, dimensions, channel, deduplicate_canonical, granularity, filters }; for multi-series: { raw_series: [...] })"
              },
              controls: { type: "object" },
              grid_x: { type: "number" },
              grid_y: { type: "number" },
              grid_w: { type: "number" },
              grid_h: { type: "number" },
              custom_kpi_id: { type: "number" },
              derived_metric_id: { type: "number" }
            },
            required: ["name", "widget_type", "source_type"]
          }
        }
      },
      required: ["draft_token", "widgets"]
    }
  },
  {
    name: "commit_dashboard_draft",
    description:
      "Atomically commit an approved dashboard draft to production. This creates/updates the dashboard and widgets and syncs project metadata.",
    inputSchema: {
      type: "object",
      properties: {
        draft_token: {
          type: "string",
          description: "The draft token to commit"
        }
      },
      required: ["draft_token"]
    }
  },
  {
    name: "discard_dashboard_draft",
    description:
      "Discard an active draft session and clean up staged resources if user cancels authoring.",
    inputSchema: {
      type: "object",
      properties: {
        draft_token: {
          type: "string",
          description: "The draft token to discard"
        }
      },
      required: ["draft_token"]
    }
  },
];

const ADMIN_TOOLS = [
  ...USER_TOOLS,
  {
    name: "get_system_health",
    description:
      "Comprehensive diagnostic health check of the APIs Hub worker infrastructure, queue workers, Redis and databases",
    inputSchema: {
      type: "object",
      properties: {},
    },
  },
  {
    name: "trigger_instance_sync",
    description: "Manually schedule and dispatch initial jobs for an instance",
    inputSchema: {
      type: "object",
      properties: {
        instance_name: {
          type: "string",
          description:
            "The name of the instance to trigger (e.g. facebook-marketing-recent)",
        },
      },
      required: ["instance_name"],
    },
  },
  {
    name: "process_jobs",
    description: "Manually execute pending job queue batch via worker CLI",
    inputSchema: {
      type: "object",
      properties: {},
    },
  },
  {
    name: "inspect_job_queue",
    description:
      "Inspect current job queue metrics (scheduled, pending, failed, completed)",
    inputSchema: {
      type: "object",
      properties: {},
    },
  },
  {
    name: "log_analyzer",
    description:
      "Scan server logs for recent exceptions, tracebacks or critical anomalies",
    inputSchema: {
      type: "object",
      properties: {
        limit: {
          type: "number",
          description: "Max errors to show per log file",
          default: 5,
        },
        hours: {
          type: "number",
          description: "Look back timeframe in hours",
          default: 24,
        },
      },
    },
  },
];

function createMcpServer(role = "admin", userContext = null) {
  const server = new Server(
    {
      name: "apis-hub-mcp",
      version: "1.0.0",
    },
    {
      capabilities: {
        resources: {},
        tools: {},
      },
    },
  );

  const activeTools = role === "admin" ? ADMIN_TOOLS : USER_TOOLS;

  /**
   * Resources
   */
  server.setRequestHandler(ListResourcesRequestSchema, async () => {
    return {
      resources: [
        {
          uri: "apis-hub://config/instances",
          name: "Current Instances Configuration",
          mimeType: "text/yaml",
          description: "The current instances.yaml generated from rules",
        },
        {
          uri: "apis-hub://logs/recent",
          name: "Recent Job Logs",
          mimeType: "text/plain",
          description: "Last 50 lines of the jobs log",
        },
      ],
    };
  });

  async function executeToolCall(name, args = {}) {
    if (name === "get_system_health") {
      try {
        const stdout = await runCliCommand("php bin/cli.php app:health-check");
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        return {
          content: [
            { type: "text", text: `Health check failed: ${error.message}` },
          ],
          isError: true,
        };
      }
    }

    if (name === "trigger_instance_sync") {
      const instance = args.instance_name;
      try {
        const stdout = await runCliCommand(
          `php bin/cli.php app:schedule-initial-jobs --instance="${instance}"`,
        );
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        return {
          content: [
            { type: "text", text: `Sync trigger failed: ${error.message}` },
          ],
          isError: true,
        };
      }
    }

    if (name === "process_jobs") {
      try {
        const stdout = await runCliCommand("php bin/cli.php app:process-jobs");
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        return {
          content: [
            { type: "text", text: `Job processing failed: ${error.message}` },
          ],
          isError: true,
        };
      }
    }

    if (name === "check_coverage") {
      const { channel, days = 30 } = args;
      if (!channel) {
        return {
          content: [
            { type: "text", text: "Error: The 'channel' parameter is required for check_coverage (e.g. 'google_search_console', 'facebook_marketing', 'shopify', 'klaviyo')." }
          ],
          isError: true
        };
      }
      try {
        const stdout = await runCliCommand(
          `php bin/cli.php app:check-coverage --channel="${channel}" --days=${days}`,
        );
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        return {
          content: [
            { type: "text", text: `Coverage check failed: ${error.message}` },
          ],
          isError: true,
        };
      }
    }

    if (name === "inspect_job_queue") {
      try {
        const stdout = await runCliCommand("php bin/cli.php app:jobs-stats");
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        return {
          content: [
            { type: "text", text: `Job inspection failed: ${error.message}` },
          ],
          isError: true,
        };
      }
    }

    if (name === "log_analyzer") {
      const { limit = 5, hours = 24 } = args;
      try {
        const stdout = await runCliCommand(
          `php bin/cli.php app:analyze-errors --limit=${limit} --hours=${hours}`,
        );
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        return {
          content: [
            { type: "text", text: `Log analysis failed: ${error.message}` },
          ],
          isError: true,
        };
      }
    }

    if (name === "get_mcp_guide") {
      const { topic = "overview" } = args;

      const guideSections = {
        overview: {
          title: "APIs Hub MCP Server — System Architecture & Interaction Overview",
          summary: "This MCP server bridges LLMs and Autonomous Agents directly to the high-performance OLAP and data aggregation engine of APIs Hub.",
          key_principles: [
            "1. Three-Tier Analytics Hierarchy: Always choose the appropriate data scope ('global', 'channel', or 'asset').",
            "2. Read-Only Safety: All analytics tools are non-destructive and optimized with in-memory deterministic caching.",
            "3. Asset Discovery First: Never guess asset IDs. Call 'list_connected_assets' to discover the exact IDs and channels available.",
            "4. Formula Normalization & Audited Channel Contracts: Metrics are governed by versioned per-channel contracts ('contract_version: 1', 'audited_at: 2026-10-30', GSC 'minimum_release: 1.18.3', GA4 'query_contract_version: 1'). Call 'get_analytics_catalog' with section='catalog_versions', section='channel_metrics_catalog', or channel='<channel>' to inspect exact metric definitions, reducers, units, additivity ('is_additive'), scopes, dimensions, and caveats.",
            "5. Dual-Engine Analytics (Channeled Metrics + Entity Measures): In addition to pre-aggregated channel metrics via 'summarize_performance', APIs Hub provides Two-Stage dynamic Entity Measures via 'aggregate_entities' over 10 cached entity tables ('orders', 'customers', 'stores', 'products', 'discounts', 'campaigns', 'ad-groups', 'ads', 'posts', 'events'), which can also be added directly to Custom Dashboard widgets."
          ],
          catalog_versions_summary: {
            google_search_console: { contract_version: 1, minimum_release: "1.18.3", audited_at: "2026-10-30", search_types: ["web", "image", "video", "news", "discover", "googleNews"], scopes: ["non-searchAppearance", "searchAppearance"] },
            google_analytics: { contract_version: 1, query_contract_version: 1, audited_at: "2026-10-30", scopes: ["account", "traffic_matrix", "event_matrix", "acquisition_matrix", "ad_touchpoint_matrix", "engagement_matrix", "ecommerce_matrix", "product_matrix"] },
            google_ads: { contract_version: 1, audited_at: "2026-10-30", scopes: ["account", "campaign", "ad_group", "ad", "query"], ui_dependencies: ["campaign_level", "adgroup_level", "ad_level", "device_level", "search_term_level"] },
            facebook_marketing: { contract_version: 1, audited_at: "2026-10-30", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"] },
            facebook_organic: { contract_version: 1, audited_at: "2026-10-30", scopes: ["facebook_page", "instagram_account", "fb_post", "ig_post"] },
            mailchimp: { contract_version: 1, audited_at: "2026-10-30", scopes: ["campaign_engagement", "store_conversions"] }
          },
          available_topics: ["overview", "workflow", "assets", "filters", "scopes", "breakdowns", "formulas", "entity_measures", "kpi_calculation", "derived_metrics", "dashboards", "benchmarks", "temporal_comparison", "funnel_decomposition", "examples"]
        },
        workflow: {
          title: "Recommended Agent Workflow",
          steps: [
            {
              step: 1,
              tool: "list_connected_assets",
              purpose: "Discover active accounts, websites, properties, and ad accounts linked to the project, noting their integer 'id' and 'channel'."
            },
            {
              step: 2,
              tool: "get_analytics_catalog",
              purpose: "Inspect available canonical metrics, derived formulas, entity_measures (orders, customers, stores, products, discounts, campaigns, ad-groups, ads, posts, events), channel datascopes, valid breakdowns, and the platform-wide reference library of predefined KPIs and Derived Metrics."
            },
            {
              step: 3,
              tool: "list_custom_kpis",
              purpose: "Check if the project has user-defined custom KPIs or formula AST expressions configured."
            },
            {
              step: 4,
              tool: "list_project_dashboards",
              purpose: "Inspect active dashboards, widget definitions, chart types, source bindings, and visual layouts configured for this project."
            },
            {
              step: 5,
              tool: "list_configured_alerts",
              purpose: "Review active monitoring threshold alerts, scheduled evaluations, and anomaly limits."
            },
            {
              step: 6,
              tool: "summarize_performance / aggregate_entities",
              purpose: "Run targeted OLAP metric queries via 'summarize_performance' or Two-Stage entity aggregations (Order AOV, Canonical Revenue, Customer LTV, Repeat Rate, Promo Usage) via 'aggregate_entities'."
            },
            {
              step: 7,
              tool: "kpi_and_derived_metric_evaluation",
              purpose: "To calculate or evaluate Predefined KPIs, Custom KPIs, or Derived Metrics (DMs): (1) inspect the AST / source_series via 'get_analytics_catalog' or 'list_custom_kpis', (2) fetch constituent metrics via 'summarize_performance' or 'aggregate_entities', and (3) perform the mathematical operator or regression calculation over the returned series."
            },
            {
              step: 8,
              tool: "create_dashboard_draft / update_dashboard_draft / commit_dashboard_draft",
              purpose: "When authoring or updating custom dashboards, call 'get_dashboard_authoring_context' first, then stage widgets using 'source_type' = 'metric', 'entity_measure', 'derived_metric', or 'kpi'."
            }
          ]
        },
        kpi_calculation: {
          title: "How Autonomous Agents Execute KPI & AST Formula Calculations",
          architecture_principle: "APIs Hub decouples raw OLAP data aggregation from virtual metric computation. While standard metrics (spend, clicks, impressions, ctr, cpc, cpm, roas, position, sessions, conversions) are aggregated server-side via 'summarize_performance', complex statistical KPIs (regressions, elasticities, MACD momentum, Granger causality, and multi-channel AST combinations) are designed to be calculated by AI agents using constituent data streams.",
          three_step_execution_recipe: [
            "Step 1 (Inspect Formula): Call 'get_analytics_catalog' (with section='predefined_kpis' or 'predefined_derived_metrics') or 'list_custom_kpis' to obtain the KPI's AST (Abstract Syntax Tree), calculation_type, and constituent metrics.",
            "Step 2 (Fetch Constituents): For each metric in the AST, call 'summarize_performance' specifying the appropriate channel, scope ('global', 'channel', 'asset'), asset filter, and groupBy (e.g., 'daily', 'query', 'page').",
            "Step 3 (Execute Math / Statistical Evaluation): Apply the AST operator ('+', '-', '*', '/', 'ratio') or statistical model (linear regression slope, moving average, percent change) across the aligned data points."
          ],
          concrete_example_search_position_efficiency: {
            kpi: "search_position_efficiency_query",
            ast_logic: "clicks / position grouped by query",
            agent_execution: "Call 'summarize_performance' with channel='google_search_console', aggregations={ clicks: 'clicks', avg_position: 'position' }, groupBy='query'. In the returned dataset, for each query calculate efficiency = clicks / avg_position. Queries with the highest ratio yield maximum click capture per ranking rank."
          },
          concrete_example_cross_channel_marginal_cost: {
            kpi: "true_blended_marginal_cost",
            ast_logic: "(spend_ch1 + spend_ch2) / (clicks_ch1 + clicks_ch2)",
            agent_execution: "Call 'summarize_performance' with scope='global', aggregations={ total_spend: 'spend', total_clicks: 'clicks' }, groupBy='daily'. For each day or total, calculate blended_marginal_cost = total_spend / total_clicks."
          }
        },
        assets: {
          title: "Asset Identification & Filtering Protocol",
          explanation: "In APIs Hub, connected entities (GSC web properties, GA4 analytics streams, Meta ad accounts, Shopify stores, Klaviyo accounts) are called 'Assets' or 'Channeled Accounts'.",
          rules: [
            "Asset IDs are integers (e.g. 2, 46, 12).",
            "When querying a specific asset in 'summarize_performance', pass the ID in the filters object using either 'channeledAccount' or 'account_id'. Example: filters: { \"channeledAccount\": \"2\" }.",
            "If the user asks about a client or domain (e.g. 'marcelacrodriguez.com'), run 'list_connected_assets' first to find matching IDs."
          ]
        },
        datascopes: {
          title: "Channel-Specific Datascopes, Audited Catalog Versions & Dimension Orthogonality",
          critical_rule: "Each marketing and analytics channel in APIs Hub is governed by an audited, versioned metrics catalog ('contract_version: 1', 'audited_at: 2026-10-30'). Mixing metrics or dimensions across different scopes within the same channel produces ambiguous cross-joins or duplicate row counting. Call 'get_analytics_catalog' with section='channel_metrics_catalog' or channel='<channel>' for the full per-metric contract.",
          channels: {
            google_search_console: {
              contract_version: 1,
              minimum_release: "1.18.3",
              audited_at: "2026-10-30",
              search_types: ["web", "image", "video", "news", "discover", "googleNews"],
              default_search_type: "web",
              feed_types_note: "Feed types ('discover', 'googleNews') support only metrics ['clicks', 'impressions', 'ctr'] (no 'position'), tabs ['pages', 'countries'], and scope 'non-searchAppearance'.",
              scopes: {
                "non-searchAppearance (default / standard)": {
                  description: "Standard search performance metrics. Safe to cross with query, dimensions.page, country, device.",
                  enforced_filter: '{ "dimensions.searchAppearance": "standard" }',
                  explanation: "Google Search Console stores impressions separated by searchAppearance. If you do not filter to 'standard' (or group by dimensions.searchAppearance), metrics will double count because multiple feature rows exist for identical queries."
                },
                "searchAppearance": {
                  description: "Performance by Google Search feature (AMP, Good Page Experience, Merchant Listings, Review Snippets, Video). Overlaps standard scope; never merge with standard.",
                  required_group_or_filter: 'groupBy: "dimensions.searchAppearance" or filters: { "dimensions.searchAppearance": { "operator": "neq", "value": "standard" } }'
                }
              }
            },
            google_analytics_ga4: {
              contract_version: 1,
              query_contract_version: 1,
              audited_at: "2026-10-30",
              caveats_summary: "GA-01: 'spend' is unsupported in GA4 (revenue is not advertising cost). GA-02: 'newUsers', 'activeUsers', 'totalUsers' are non-additive reported sums, not window uniques. GA-03: 'dimensions.sessionSourceMedium' is a virtual dimension combining source + medium.",
              scopes: {
                "account": {
                  description: "Property-level daily totals (dimensions.scope IS NULL).",
                  metrics: ["screenPageViews", "sessions", "bounceRate", "totalRevenue"],
                  dimensions: []
                },
                "traffic_matrix (default session scope)": {
                  description: "Traffic, visits, revenue, conversions, and engagement acquired in each session.",
                  metrics: ["screenPageViews", "sessions", "bounceRate", "averageSessionDuration", "totalRevenue", "conversions"],
                  dimensions: ["dimensions.sessionDefaultChannelGroup", "dimensions.sessionSourceMedium", "dimensions.source", "dimensions.medium", "dimensions.landing_page", "channeledCampaign", "adGroup", "country", "device"]
                },
                "acquisition_matrix (first user scope)": {
                  description: "First touchpoint / user acquisition attribution (non-additive reported user sums).",
                  metrics: ["newUsers", "activeUsers", "totalUsers"],
                  dimensions: ["dimensions.firstUserDefaultChannelGroup", "dimensions.firstUserSourceMedium", "channeledCampaign", "adGroup", "ad"]
                },
                "event_matrix": {
                  description: "Granular interaction event counting and key events.",
                  metrics: ["eventCount", "conversions"],
                  dimensions: ["event", "dimensions.page", "dimensions.sessionDefaultChannelGroup", "dimensions.sessionSourceMedium", "channeledCampaign", "adGroup", "ad"]
                },
                "ad_touchpoint_matrix": {
                  description: "Paid advertising attribution linking campaign, ad group, and ad.",
                  metrics: ["sessions", "conversions", "totalRevenue"],
                  dimensions: ["channeledCampaign", "adGroup", "ad"]
                },
                "engagement_matrix (opt-in, recipe_revision='1')": {
                  description: "Engaged sessions, engagement duration, and engagement rate.",
                  metrics: ["sessions", "engagedSessions", "userEngagementDuration", "engagementRate"],
                  dimensions: ["dimensions.sessionDefaultChannelGroup", "dimensions.sessionSourceMedium", "dimensions.landingPagePlusQueryString", "device", "country"]
                },
                "ecommerce_matrix (opt-in, recipe_revision='1')": {
                  description: "Purchase transactions, gross/net purchase revenue, and refunds.",
                  metrics: ["ecommercePurchases", "purchaseRevenue", "grossPurchaseRevenue", "refundAmount", "transactions"],
                  dimensions: ["dimensions.sessionDefaultChannelGroup", "dimensions.sessionSourceMedium", "dimensions.sessionCampaignName", "device", "country"]
                },
                "product_matrix (opt-in, recipe_revision='1')": {
                  description: "Item-level views, add-to-carts, purchases, item revenue, and refunds.",
                  metrics: ["itemsViewed", "itemsAddedToCart", "itemsPurchased", "itemRevenue", "grossItemRevenue", "itemRefundAmount"],
                  dimensions: ["dimensions.itemId", "dimensions.itemName", "dimensions.itemBrand", "dimensions.itemCategory", "dimensions.itemVariant"]
                }
              }
            },
            google_ads: {
              contract_version: 1,
              audited_at: "2026-10-30",
              scopes: ["account", "campaign", "ad_group", "ad", "query"],
              ui_dependencies: ["campaign_level", "adgroup_level", "ad_level", "device_level", "search_term_level"],
              metrics: {
                cached_additive: ["spend", "impressions", "clicks", "conversions"],
                known_issue_conditional: ["conversion_value", "roas_purchase"],
                derived_non_additive: ["ctr", "cpc", "cpm", "cost_per_conversion", "conversion_rate"],
                source_only: ["video_views"]
              },
              dimensions: ["device", "campaignType", "query", "channeledCampaign", "adGroup", "ad"]
            },
            facebook_marketing: {
              contract_version: 1,
              audited_at: "2026-10-30",
              scopes: ["account_level", "campaign_level", "adset_level", "ad_level"],
              hierarchy_rule: "Facebook Ads metrics are stored at 'account_level', 'campaign_level', 'adset_level', and 'ad_level' (default). Optional breakdown passes exist for demographics (age, gender), geography (country), and placement (publisher_platform, platform_position).",
              metrics: {
                additive: ["spend", "clicks", "impressions", "results", "purchases", "purchase_value", "leads"],
                non_additive: ["reach", "frequency", "cpm", "ctr", "cpc", "purchase_roas", "cost_per_result", "result_rate", "cost_per_purchase", "cost_per_lead"]
              },
              dimensions: ["ad", "adGroup", "channeledCampaign", "channeledAccount", "dimensions.age", "dimensions.gender", "dimensions.country", "dimensions.publisher_platform", "dimensions.platform_position"],
              safeguard: "Never mix age/gender/placement breakdown passes with ad level attribution unless specifically analyzing demographic or placement slices."
            },
            facebook_organic: {
              contract_version: 1,
              audited_at: "2026-10-30",
              scopes: {
                "instagram_account": {
                  description: "Metrics for the IG profile entity.",
                  metrics: ["likes", "comments", "reach", "views", "content_views", "profile_views", "website_clicks", "profile_links_taps", "follows_and_unfollows", "saves", "shares", "total_interactions", "replies", "accounts_engaged", "follower_total"],
                  dimensions: ["dimensions.media_product_type", "dimensions.follow_type", "dimensions.contact_button_type"]
                },
                "ig_post": {
                  description: "Metrics scoped strictly to individual IG media / posts.",
                  metrics: ["likes", "comments", "reach", "views", "saves", "shares", "total_interactions", "replies", "ig_reels_avg_watch_time", "ig_reels_video_view_total_time", "profile_activity", "profile_visits", "reposts", "follows"],
                  breakdown: "ig_post"
                },
                "facebook_page": {
                  description: "Page-level organic performance.",
                  metrics: ["likes", "comments", "reach", "views", "page_views_total", "video_views", "follows_and_unfollows", "shares", "total_interactions", "follower_total"],
                  dimensions: ["dimensions.reaction_type"]
                },
                "fb_post": {
                  description: "Post-level organic performance.",
                  metrics: ["likes", "comments", "reach", "views", "video_views", "shares", "total_interactions", "post_clicks", "post_video_avg_time_watched"],
                  dimensions: ["fb_post", "dimensions.reaction_type"]
                }
              }
            },
            mailchimp: {
              contract_version: 1,
              audited_at: "2026-10-30",
              scopes: {
                "campaign_engagement": {
                  description: "Campaign-level email performance, opens, clicks, bounces, unsubscribes, and URL slices.",
                  metrics: ["sends", "opens", "opens_standard", "opens_proxy", "opens_unique_daily", "clicks", "clicks_unique", "bounces", "bounces_hard", "bounces_soft", "unsubscribes", "open_rate", "click_rate"],
                  dimensions: ["channeledCampaign", "campaignType", "dimensions.page (clicks & clicks_unique only)"]
                },
                "store_conversions": {
                  description: "Connected e-commerce store orders and revenue attributed within 30-day window.",
                  metrics: ["orders", "revenue"],
                  dimensions: ["dimensions.store_id", "dimensions.currency"]
                }
              }
            }
          }
        },
        scopes: {
          title: "Execution Scopes ('global', 'channel', 'asset')",
          scopes: {
            global: "Blended cross-network performance across all integrated providers. Do not pass a 'channel' parameter.",
            channel: "Ecosystem performance restricted to a single provider (e.g. channel: 'google_search_console', 'google_analytics', 'facebook_marketing').",
            asset: "Granular performance isolated to a specific account, property, or store. Must include filters: { \"channeledAccount\": \"<id>\" }."
          }
        },
        filters: {
          title: "Filter Syntax & Capabilities",
          accepted_formats: {
            exact_match: { "device": "desktop", "country": "US", "channeledAccount": "2" },
            nested_dimensions: { "dimensions.gender": "male", "dimensions.country": "USA", "dimensions.searchAppearance": "standard" }
          },
          warning: "Do not pass complex mathematical operators in filter keys unless checking standard equality. For metric thresholds, aggregate first then evaluate in prompt reasoning."
        },
        breakdowns: {
          title: "Temporal and Dimensional Breakdowns ('groupBy')",
          temporal: ["daily", "weekly", "monthly", "quarterly", "yearly", "date"],
          dimensional: ["device", "country", "query", "page", "campaign", "ad_set", "placement", "dimensions.*"],
          multi_grouping: "You can group by multiple dimensions comma-separated, e.g. 'channel,device' or 'date,device'."
        },
        formulas: {
          title: "Server-Side OLAP Aggregations (Computed Natively in summarize_performance)",
          architecture_note: "These 10 standard formulas are computed directly by the OLAP SQL aggregation engine when requested in 'aggregations'. You do NOT need to manually compute them unless performing custom statistical modeling.",
          formulas: [
            { name: "spend", formula: "SUM(spend)", description: "Total advertising cost" },
            { name: "clicks", formula: "SUM(clicks)", description: "Total link or ad clicks" },
            { name: "impressions", formula: "SUM(impressions)", description: "Total impressions" },
            { name: "ctr", formula: "clicks / impressions * 100", description: "Click-Through Rate (%)" },
            { name: "cpc", formula: "spend / clicks", description: "Cost Per Click" },
            { name: "cpm", formula: "spend / impressions * 1000", description: "Cost Per Mille" },
            { name: "roas", formula: "revenue / spend", description: "Return on Ad Spend" },
            { name: "position", formula: "weighted_avg(position by impressions)", description: "Average Search Engine Rank (weighted by impressions)" },
            { name: "sessions", formula: "SUM(sessions)", description: "Web/Store Traffic Sessions" },
            { name: "conversions", formula: "SUM(conversions)", description: "Total Goal / Purchase Conversions" }
          ]
        },
        entity_measures: {
          title: "Entity Measures Aggregation ('aggregate_entities' & Dashboard Widget Authoring)",
          concept: "While 'summarize_performance' queries pre-aggregated daily channel metrics ('channeled_metrics'), 'aggregate_entities' (and dashboard widgets with source_type='entity_measure') executes Two-Stage dynamic aggregations directly over APIs Hub's 10 cached entity tables: 'orders', 'customers', 'stores', 'products', 'discounts' (aliases: 'promotions', 'promo-codes'), 'campaigns', 'ad-groups' (alias: 'ad-sets'), 'ads', 'posts', and 'events'.",
          core_capabilities_and_safeguards: {
            two_stage_canonical_deduplication: "A single physical order or customer can be referenced across multiple channels (e.g. Shopify, Mailchimp, Klaviyo, Google Analytics). When 'deduplicate_canonical' is true (default for cross-channel rollups without a 'channel' dimension), the engine collapses multi-channel references sharing the same canonical 'order_id' or 'customer_id' into a single record before summing revenue or computing AOV (MAX(amount) per canonical order). When 'dimensions: [\"channel\"]' is requested, each channel's attributed orders and revenue are compared side-by-side without collapsing across channels.",
            multi_currency_safety_guard: "Monetary measures ('revenue', 'subtotal', 'total_tax', 'total_discount', 'aov', 'customer_ltv', 'avg_revenue_per_customer', 'average_price', 'min_price', 'max_price', 'total_budget', 'average_budget') are protected against cross-currency addition. If the queried scope spans multiple currencies (e.g. USD and EUR) and neither 'dimensions: [\"currency\"]' nor 'filters: { \"currency\": \"USD\" }' is provided, monetary measures return null and 'meta.currency_mixed: true' lists the detected currencies.",
            customer_cross_channel_intelligence: "For 'customers', custom commerce measures ('customer_ltv', 'avg_revenue_per_customer', 'repeat_customer_count', 'high_frequency_customer_count', 'repeat_buyer_rate', 'repeat_frequency') join canonical customer orders across all connected stores, and 'pre_purchase_touches' attributes email/campaign touchpoints occurring prior to purchase."
          },
          supported_entity_families: {
            orders: {
              measures: ["order_count", "canonical_order_count", "unique_customers", "revenue", "subtotal", "total_tax", "total_discount", "aov"],
              dimensions: ["store", "channel", "account", "product", "discount_code", "financial_status", "fulfillment_status", "currency", "shipping_country"],
              time_buckets: ["day", "week", "month", "year"]
            },
            customers: {
              measures: ["customer_count", "canonical_customer_count", "customer_ltv", "avg_revenue_per_customer", "repeat_customer_count", "high_frequency_customer_count", "repeat_buyer_rate", "repeat_frequency", "pre_purchase_touches"],
              dimensions: ["store", "channel", "status", "account"],
              time_buckets: ["day", "week", "month", "year"]
            },
            stores: {
              measures: ["store_count"],
              dimensions: ["channel", "currency", "account"],
              time_buckets: ["day", "week", "month", "year"]
            },
            products: {
              measures: ["product_count", "canonical_product_count", "average_price", "min_price", "max_price"],
              dimensions: ["store", "channel", "vendor", "product_type", "sku", "currency"],
              time_buckets: ["day", "week", "month", "year"]
            },
            discounts: {
              aliases: ["promotions", "promo-codes"],
              measures: ["discount_count", "total_usage"],
              dimensions: ["store", "channel", "code", "status"],
              time_buckets: ["day", "week", "month", "year"]
            },
            campaigns: {
              measures: ["campaign_count", "total_budget", "average_budget"],
              dimensions: ["status", "objective", "buying_type", "channel", "account"],
              time_buckets: ["day", "week", "month", "year"]
            },
            "ad-groups": {
              aliases: ["ad-sets"],
              measures: ["ad_group_count"],
              dimensions: ["status", "campaign", "optimization_goal", "billing_event", "channel", "account"],
              time_buckets: ["day", "week", "month", "year"]
            },
            ads: {
              measures: ["ad_count"],
              dimensions: ["status", "campaign", "ad_group", "channel", "account"],
              time_buckets: ["day", "week", "month", "year"]
            },
            posts: {
              measures: ["post_count"],
              dimensions: ["page", "media_type", "account"],
              time_buckets: ["day", "week", "month", "year"]
            },
            events: {
              measures: ["event_occurrence_count", "distinct_event_names"],
              dimensions: ["channel", "action", "account"],
              time_buckets: ["day", "week", "month", "year"]
            }
          },
          dashboard_widget_authoring_blueprints: {
            description: "Agents can add Entity Measures to custom dashboard widgets via 'create_dashboard_draft' and 'update_dashboard_draft' in two ways: (1) Direct 'source_type: \"entity_measure\"' widgets, or (2) Multi-series 'source_type: \"metric\"' widgets blending 'entity_measure' series with standard channel metrics.",
            direct_scalar_tile_example: {
              name: "Cross-Channel Canonical AOV",
              title: "Average Order Value (AOV)",
              widget_type: "tile",
              source_type: "entity_measure",
              source_config: {
                entity_type: "orders",
                measures: ["aov"],
                deduplicate_canonical: true,
                filters: { currency: "USD" }
              },
              grid_x: 0,
              grid_y: 0,
              grid_w: 3,
              grid_h: 2
            },
            direct_time_series_chart_example: {
              name: "Daily Canonical Orders Revenue",
              title: "Orders Revenue Trend",
              widget_type: "line_chart",
              source_type: "entity_measure",
              source_config: {
                entity_type: "orders",
                measures: ["revenue", "order_count"],
                deduplicate_canonical: true,
                granularity: "daily",
                filters: { currency: "USD" }
              },
              grid_x: 3,
              grid_y: 0,
              grid_w: 9,
              grid_h: 4
            },
            direct_dimensional_table_or_bar_chart_example: {
              name: "Orders & Revenue by Channel",
              title: "Channel Attribution Comparison",
              widget_type: "table",
              source_type: "entity_measure",
              source_config: {
                entity_type: "orders",
                measures: ["order_count", "revenue", "aov"],
                dimensions: ["channel"],
                deduplicate_canonical: false,
                filters: { currency: "USD" }
              },
              grid_x: 0,
              grid_y: 4,
              grid_w: 6,
              grid_h: 4
            },
            multi_series_blended_chart_example: {
              name: "Canonical Orders Revenue vs Meta Ad Spend",
              title: "Store Revenue vs Ad Spend",
              widget_type: "line_chart",
              source_type: "metric",
              source_config: {
                granularity: "daily",
                raw_series: [
                  {
                    type: "entity_measure",
                    entity_type: "orders",
                    measures: ["revenue"],
                    deduplicate_canonical: true,
                    filters: { currency: "USD" },
                    label: "Canonical Orders Revenue"
                  },
                  {
                    type: "metric",
                    channel: "facebook_marketing",
                    metrics: ["spend"],
                    label: "Meta Ad Spend"
                  }
                ]
              },
              grid_x: 6,
              grid_y: 4,
              grid_w: 6,
              grid_h: 4
            }
          }
        },
        derived_metrics: {
          title: "Predefined Derived Metrics (DMs) — Platform Reference Library & Evaluation",
          concept: "Derived Metrics (DMs) represent mathematical combinations of two or more time-series or channel metrics (e.g. CPA = Spend / Results, SEO Efficiency = Clicks / Position, Blended CPC = (Spend_Ch1 + Spend_Ch2) / (Clicks_Ch1 + Clicks_Ch2)).",
          reference_library_access: "Call 'get_analytics_catalog' with section='predefined_derived_metrics' to retrieve all 26 platform-wide reference blueprints with their complete ASTs, source series definitions, required capability tags, and formats.",
          structure_of_a_dm: {
            required_tags: "Channel capability tags that must be active (e.g., ['spendable', 'clickable'], ['seo', 'impressionable'], ['organic_social', 'reach_driven']).",
            source_series: "Constituent series labeled with keys ('a', 'b', etc.). Channel placeholders (e.g., '__SPENDABLE_CHANNEL_1__', '__SEO_CHANNEL_1__', '__CLICKABLE_CHANNEL_1__') represent the active channel to bind.",
            ast: "Abstract Syntax Tree with operator ('/', '*', '+', '-') and left/right metric pointers.",
            format: "'currency', 'percentage', 'decimal', or 'integer'."
          },
          evaluation_recipe: [
            "1. Inspect Blueprint: Fetch DM definition from 'get_analytics_catalog(section: \"predefined_derived_metrics\")' or project's custom list via 'list_custom_kpis'.",
            "2. Map Channel Placeholders: Replace placeholders like '__SEO_CHANNEL_1__' with the real channel from 'list_connected_assets' (e.g. 'google_search_console').",
            "3. Query Constituents: Call 'summarize_performance' for each source series with the corresponding metric (e.g. series 'a' = spend, series 'b' = clicks) and desired breakdown/date range.",
            "4. Compute AST: Combine the series point-by-point or on totals according to the AST operator (e.g. series_a / series_b)."
          ],
          available_predefined_dms: [
            { key: "cpc", name: "Cost per Click", formula: "spend / clicks", categories: ["cost", "paid_media"] },
            { key: "ctr", name: "Click-Through Rate", formula: "clicks / impressions", categories: ["performance", "paid_media"] },
            { key: "cpa", name: "Cost per Acquisition / Result", formula: "spend / results", categories: ["cost", "paid_media", "results"] },
            { key: "cvr", name: "Conversion Rate", formula: "results / clicks", categories: ["results", "paid_media"] },
            { key: "roas", name: "Return on Ad Spend", formula: "revenue / spend", categories: ["revenue", "paid_media"] },
            { key: "cost_per_conversion", name: "Cost per Conversion", formula: "spend / conversions", categories: ["cost", "paid_media"] },
            { key: "result_rate", name: "Result Rate", formula: "results / impressions", categories: ["results", "paid_media"] },
            { key: "cost_per_engagement", name: "Cost per Engagement", formula: "spend / engagements", categories: ["cost", "engagement"] },
            { key: "engagement_click_rate", name: "Engagement Click Rate", formula: "clicks / engagements", categories: ["engagement", "paid_media"] },
            { key: "organic_engagement_rate", name: "Organic Engagement Rate", formula: "engaged_users / reach", categories: ["organic", "social"] },
            { key: "organic_reach_efficiency", name: "Organic Reach per Impression", formula: "reach / impressions", categories: ["organic", "social"] },
            { key: "organic_impression_engagement", name: "Engagement per Impression", formula: "engaged_users / impressions", categories: ["organic", "social"] },
            { key: "seo_ctr", name: "SEO Click-Through Rate", formula: "clicks / impressions", categories: ["seo", "clicks"] },
            { key: "click_position_efficiency", name: "Click Position Efficiency", formula: "clicks / position", categories: ["seo", "performance"] },
            { key: "impression_position_efficiency", name: "Impression Position Efficiency", formula: "impressions / position", categories: ["seo", "impressions"] },
            { key: "blended_cpc", name: "Blended Cross-Channel CPC", formula: "(spend_1 + spend_2) / (clicks_1 + clicks_2)", categories: ["cross-channel", "cost"] },
            { key: "blended_roas", name: "Blended Cross-Channel ROAS", formula: "(rev_1 + rev_2) / (spend_1 + spend_2)", categories: ["cross-channel", "revenue"] },
            { key: "blended_ctr", name: "Blended Cross-Channel CTR", formula: "(clicks_1 + clicks_2) / (imp_1 + imp_2)", categories: ["cross-channel", "performance"] },
            { key: "aov", name: "Average Order Value", formula: "revenue / conversions", categories: ["ecommerce", "revenue"] },
            { key: "cart_conversion_rate", name: "Cart to Order Conversion Rate", formula: "conversions / add_to_cart", categories: ["ecommerce", "funnel"] },
            { key: "revenue_per_visitor", name: "Revenue per Visitor", formula: "revenue / sessions", categories: ["ecommerce", "traffic"] },
            { key: "blended_mer", name: "Marketing Efficiency Ratio (MER)", formula: "store_revenue / total_ad_spend", categories: ["cross-channel", "executive"] },
            { key: "seo_vs_paid_clicks", name: "SEO vs Paid Clicks Ratio", formula: "seo_clicks / paid_clicks", categories: ["cross-channel", "seo", "paid_media"] },
            { key: "paid_organic_impression_ratio", name: "Paid-to-Organic Impression Ratio", formula: "organic_impressions / paid_impressions", categories: ["cross-channel", "social"] },
            { key: "organic_reach_vs_seo_ctr", name: "Organic Reach vs SEO CTR", formula: "organic_reach / seo_clicks", categories: ["cross-channel", "social", "seo"] },
            { key: "revenue_per_click", name: "Revenue per Click (Combined)", formula: "revenue / total_clicks", categories: ["cross-channel", "revenue"] }
          ]
        },
        dashboards: {
          title: "Dashboards & Visual Widgets Architecture",
          purpose: "Dashboards in APIs Hub provide real-time, customizable monitoring views that aggregate performance across channels, KPIs, and derived metrics. An autonomous agent should inspect dashboards to understand what KPIs and visual representations the business prioritizes.",
          discovery_tool: "Call 'list_project_dashboards' to inspect configured dashboards, widget collections, grid layouts, and source bindings.",
          dashboard_structure: {
            id: "Unique dashboard integer ID.",
            name: "Display title (e.g. 'Executive Summary', 'SEO & Organic Growth', 'Paid Media Performance').",
            is_default: "Boolean indicating if this is the primary overview dashboard.",
            widgets: "Array of configured visualization widgets."
          },
          widget_sources: {
            metric: "Direct raw OLAP metric aggregation (e.g., total spend, impressions, clicks from a specific channel) OR multi-series blending via 'source_config.raw_series' (which can combine 'type: \"metric\"', 'type: \"entity_measure\"', and 'type: \"derived_metric\"' series on the same chart).",
            entity_measure: "Two-Stage dynamic Entity Measures aggregation over cached entity tables ('orders', 'customers', 'stores', 'products', 'discounts', 'campaigns', 'ad-groups', 'ads', 'posts', 'events'). Configured via 'source_config: { entity_type, measures, dimensions, channel, deduplicate_canonical, granularity, filters }'.",
            kpi: "Statistical Analytics Engine KPI (e.g. Anomaly detection, MACD momentum, Linear trendline, Granger causality). Can evaluate either standard channel metrics or entity measures.",
            derived_metric: "Calculated series from an AST blueprint (e.g. CPA, Click Position Efficiency, Blended ROAS combining Canonical Orders Revenue from 'entity_measure' with Paid Ad Spend from 'metric')."
          },
          widget_types_and_roles: {
            tile: "Number Tile — Displays a single high-impact total or average with trend arrow. Ideal for executive summaries (e.g. total revenue, overall MER).",
            line_chart: "Continuous Time-Series — Displays trends over daily/weekly intervals. Best for tracking trajectory, velocity, or seasonality.",
            bar_chart: "Categorical Comparison — Compares discrete volumes side-by-side across channels, campaigns, or devices.",
            pie_chart: "Part-to-Whole Share — Shows proportion distribution (e.g. channel budget allocation, device share).",
            gauge: "Target Progress — Measures progress or current percentage against an SLA, threshold, or goal.",
            sparkline: "Minimalist Trendline — Compact inline visual indicating directional momentum without heavy axis clutter.",
            scatter_plot: "Correlation & Elasticity — Identifies correlation between two continuous variables (e.g., ad spend vs organic search volume).",
            combo_chart: "Dual-Axis Overlay — Combines bars (volume like clicks) and lines (rates like CTR or CPA) across time.",
            table: "Granular Tabular View — Multi-dimensional row-by-row matrix (e.g., top 20 search queries with clicks, impressions, CTR, position).",
            anomaly_chart: "Statistical Outlier Watch — Line chart with standard deviation bands that highlights anomalies in red."
          },
          agent_interpretation_guideline: "When an agent is asked 'What does our dashboard show?' or 'How is our main dashboard performing?', the agent should: (1) call 'list_project_dashboards' to identify the active widgets and their source KPIs/metrics, (2) query 'summarize_performance' for the corresponding metrics and date range, and (3) synthesize an executive interpretation highlighting trend direction, goal achievement, and outliers."
        },
        benchmarks: {
          title: "Marketing Benchmarks & Target Evaluation Guidelines",
          purpose: "Enables agents to assess whether observed metrics represent strong, average, or concerning performance when explicit client targets are not provided in project alerts or metadata.",
          standard_channel_benchmarks: {
            google_search_console: {
              top_3_ctr: "25% - 35% for Position 1; 12% - 16% for Position 2; 8% - 11% for Position 3.",
              top_10_ctr: "2% - 4% for Positions 4-10.",
              diagnostic_rule: "If an organic query ranks in Position <= 3 but has CTR < 5%, evaluate title and snippet copy for intent mismatch or rich snippet competition."
            },
            google_analytics_ga4: {
              average_bounce_rate: "40% - 60% for content/blogs; 25% - 45% for e-commerce.",
              engagement_rate: "55% - 70% is healthy across direct and organic search.",
              session_duration: "1.5 - 3.5 minutes for content and shopping sites."
            },
            facebook_marketing: {
              average_ctr: "0.90% - 1.60% (e-commerce / retail); 0.70% - 1.10% (B2B).",
              average_cpc: "$0.80 - $2.50 depending on audience geography and vertical.",
              healthy_frequency: "1.5 - 3.0 per 7-day window. Frequency > 4.5 accompanied by rising CPC signals audience saturation / creative fatigue."
            },
            ecommerce_funnel: {
              healthy_conversion_rate: "1.8% - 3.5% (Sessions to Completed Orders).",
              cart_abandonment_rate: "65% - 75% baseline.",
              blended_mer: "3.0x - 5.0x for mature brands; 1.8x - 2.5x for rapid acquisition phases."
            }
          }
        },
        temporal_comparison: {
          title: "Period-over-Period (PoP) & Year-over-Year (YoY) Velocity Analysis",
          methodology: "Accurate marketing intelligence requires comparing current period performance against prior periods to measure momentum, seasonality, and campaign impact.",
          delta_formulas: {
            absolute_delta: "Current_Value - Prior_Value",
            percentage_delta: "((Current_Value - Prior_Value) / Prior_Value) * 100",
            velocity_indicator: "Positive delta indicates growth; for cost metrics (CPA, CPC), negative delta indicates efficiency gain."
          },
          recipe_pop_comparison: [
            "1. Define Current Window: e.g., startDate='2026-09-01', endDate='2026-09-25' (25 days).",
            "2. Define Prior Window: Calculate equivalent length prior offset, e.g., startDate='2026-08-07', endDate='2026-08-31' (25 days).",
            "3. Query Both Windows: Call 'summarize_performance' once for each period with identical aggregations, scope, and filters.",
            "4. Calculate Deltas: For each key metric (spend, clicks, conversions, ctr, cpc), compute the % change: ((current - prior) / prior) * 100."
          ],
          recipe_yoy_comparison: [
            "1. Shift Current Window by exactly 1 year (365 days) or 52 weeks (matching day-of-week).",
            "2. Query both periods via 'summarize_performance'.",
            "3. Isolate macro/seasonal growth from tactical campaign performance."
          ]
        },
        funnel_decomposition: {
          title: "Funnel Decomposition & Root-Cause Attribution Tree",
          principle: "When high-level performance shifts (e.g. ROAS or Revenue changes), autonomous agents should follow a systematic MECE decomposition tree instead of guessing.",
          roas_decomposition_tree: {
            formula: "ROAS = (Revenue / Spend) = (Conversions * AOV) / (Clicks * CPC) = (CVR * AOV) / CPC",
            diagnostic_steps: [
              {
                step: 1,
                check: "Did ROAS decline due to Cost (CPC) or Yield (CVR * AOV)?",
                action: "Calculate Delta(CPC) and Delta(Conversion_Rate). If CPC rose > 20%, inspect auction competition, audience fatigue (frequency), and creative CTR."
              },
              {
                step: 2,
                check: "If Yield declined, was it Conversion Rate (CVR) or Order Value (AOV)?",
                action: "If CVR dropped, inspect landing page load time, out-of-stock items, or mobile vs desktop checkout breakdown. If AOV dropped, check promotional discounting or product mix changes."
              }
            ]
          },
          seo_traffic_decomposition_tree: {
            formula: "Organic Search Clicks = Total Search Impressions * CTR",
            diagnostic_steps: [
              {
                step: 1,
                check: "Did Clicks drop due to lower Market Demand (Impressions) or lower Capture (CTR)?",
                action: "Query 'summarize_performance' with groupBy='query' and aggregations={ clicks: 'clicks', impressions: 'impressions', ctr: 'ctr', position: 'position' }."
              },
              {
                step: 2,
                check: "If Impressions dropped: Did average ranking position slip, or did overall seasonal search volume decline?",
                action: "Inspect avg_position delta. If position is stable but impressions dropped, the keyword demand is seasonal. If position slipped from 2.1 to 8.4, algorithm updates or competitor outranking occurred."
              }
            ]
          }
        },
        examples: {
          title: "Copy-Paste Ready Query Recipes",
          recipes: [
            {
              scenario: "SEO Audit: Top ranking queries and CTR for a website",
              tool: "summarize_performance",
              arguments: {
                entity: "channeled_metric",
                channel: "google_search_console",
                scope: "asset",
                filters: { channeledAccount: "2" },
                groupBy: "device",
                aggregations: {
                  total_clicks: "clicks",
                  total_impressions: "impressions",
                  click_through_rate: "ctr",
                  avg_position: "position"
                },
                startDate: "2026-09-01",
                endDate: "2026-09-24"
              }
            },
            {
              scenario: "Cross-Channel Executive Blended Performance",
              tool: "summarize_performance",
              arguments: {
                entity: "channeled_metric",
                scope: "global",
                groupBy: "channel,device",
                aggregations: {
                  blended_clicks: "clicks",
                  blended_impressions: "impressions",
                  blended_ctr: "ctr"
                }
              }
            },
            {
              scenario: "GA4 Traffic and Session Analysis",
              tool: "summarize_performance",
              arguments: {
                entity: "channeled_metric",
                channel: "google_analytics",
                scope: "asset",
                filters: { channeledAccount: "46" },
                groupBy: "device",
                aggregations: {
                  total_sessions: "sessions",
                  total_conversions: "conversions"
                }
              }
            },
            {
              scenario: "Mailchimp Email Campaign & Audience Engagement Analysis",
              tool: "summarize_performance",
              arguments: {
                entity: "channeled_metric",
                channel: "mailchimp",
                scope: "asset",
                filters: { channeledAccount: "50" },
                aggregations: {
                  total_sends: "sends",
                  total_opens: "opens",
                  total_clicks: "clicks",
                  unique_clicks: "clicks_unique",
                  total_bounces: "bounces",
                  unsubscribes: "unsubscribes"
                }
              }
            },
            {
              scenario: "Cross-Channel Canonical Orders Revenue, AOV, and Buying Customers (Entity Measures)",
              tool: "aggregate_entities",
              arguments: {
                entity_type: "orders",
                measures: ["canonical_order_count", "unique_customers", "revenue", "aov", "total_discount"],
                deduplicate_canonical: true,
                time_bucket: "day",
                filters: {
                  date_from: "2026-10-01",
                  date_to: "2026-10-31",
                  currency: "USD"
                }
              }
            },
            {
              scenario: "Customer Lifetime Value (LTV), Repeat Buyer Rate & Pre-Purchase Multi-Channel Touches",
              tool: "aggregate_entities",
              arguments: {
                entity_type: "customers",
                measures: ["canonical_customer_count", "customer_ltv", "avg_revenue_per_customer", "repeat_buyer_rate", "pre_purchase_touches"],
                filters: {
                  currency: "USD"
                }
              }
            },
            {
              scenario: "Stage a Custom Dashboard Draft with Entity Measures & Blended Multi-Series Widgets",
              tool: "create_dashboard_draft",
              arguments: {
                name: "Cross-Channel Commerce & Attribution Overview",
                target_audience: "executive",
                account_mode: "blended",
                widgets: [
                  {
                    name: "Canonical AOV Tile",
                    title: "Average Order Value (AOV)",
                    widget_type: "tile",
                    source_type: "entity_measure",
                    source_config: {
                      entity_type: "orders",
                      measures: ["aov"],
                      deduplicate_canonical: true,
                      filters: { currency: "USD" }
                    },
                    grid_x: 0,
                    grid_y: 0,
                    grid_w: 4,
                    grid_h: 2
                  },
                  {
                    name: "Orders Revenue vs Meta Ad Spend",
                    title: "Canonical Store Revenue vs Ad Spend",
                    widget_type: "line_chart",
                    source_type: "metric",
                    source_config: {
                      granularity: "daily",
                      raw_series: [
                        {
                          type: "entity_measure",
                          entity_type: "orders",
                          measures: ["revenue"],
                          deduplicate_canonical: true,
                          filters: { currency: "USD" },
                          label: "Canonical Orders Revenue"
                        },
                        {
                          type: "metric",
                          channel: "facebook_marketing",
                          metrics: ["spend"],
                          label: "Meta Ad Spend"
                        }
                      ]
                    },
                    grid_x: 4,
                    grid_y: 0,
                    grid_w: 8,
                    grid_h: 4
                  }
                ]
              }
            }
          ]
        }
      };

      const selected = guideSections[topic] || guideSections.overview;
      return {
        content: [
          {
            type: "text",
            text: JSON.stringify(selected, null, 2)
          }
        ]
      };
    }

    if (name === "list_connected_assets") {
      const { channel } = args;
      let cmd = "php bin/cli.php app:list-assets --pretty";
      if (channel) {
        cmd += ` --channel="${channel}"`;
      }

      try {
        const stdout = await runCliCommand(cmd);
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        // Fallback: Query via instances.yaml or direct PDO if CLI encounters bootstrap exception
        return {
          content: [
            { type: "text", text: `Asset listing failed: ${error.message}` }
          ],
          isError: true
        };
      }
    }

    if (name === "get_analytics_catalog") {
      const { scope, channel, section } = args;
      const catalog = {
        catalog_versions: {
          google_search_console: {
            channel: "google_search_console",
            contract_version: 1,
            query_contract_version: null,
            minimum_release: "1.18.3",
            audited_at: "2026-10-30",
            metrics_count: 4,
            search_types: ["web", "image", "video", "news", "discover", "googleNews"],
            scopes: ["non-searchAppearance", "searchAppearance"],
            ui_dependencies: ["non-searchAppearance", "searchAppearance"]
          },
          google_analytics: {
            channel: "google_analytics",
            contract_version: 1,
            query_contract_version: 1,
            minimum_release: null,
            audited_at: "2026-10-30",
            metrics_count: 26,
            scopes: [
              "account",
              "traffic_matrix",
              "event_matrix",
              "acquisition_matrix",
              "ad_touchpoint_matrix",
              "engagement_matrix",
              "ecommerce_matrix",
              "product_matrix"
            ],
            ui_dependencies: [
              "traffic_matrix",
              "acquisition_matrix",
              "event_matrix",
              "ad_touchpoint_matrix",
              "engagement_matrix",
              "ecommerce_matrix",
              "product_matrix"
            ]
          },
          google_ads: {
            channel: "google_ads",
            contract_version: 1,
            query_contract_version: null,
            minimum_release: null,
            audited_at: "2026-10-30",
            metrics_count: 12,
            scopes: ["account", "campaign", "ad_group", "ad", "query"],
            ui_dependencies: ["campaign_level", "adgroup_level", "ad_level", "device_level", "search_term_level"]
          },
          facebook_marketing: {
            channel: "facebook_marketing",
            contract_version: 1,
            query_contract_version: null,
            minimum_release: null,
            audited_at: "2026-10-30",
            metrics_count: 17,
            scopes: ["account_level", "campaign_level", "adset_level", "ad_level"],
            ui_dependencies: ["account_level", "campaign_level", "adset_level", "ad_level"]
          },
          facebook_organic: {
            channel: "facebook_organic",
            contract_version: 1,
            query_contract_version: null,
            minimum_release: null,
            audited_at: "2026-10-30",
            metrics_count: 25,
            scopes: ["facebook_page", "instagram_account", "fb_post", "ig_post"],
            ui_dependencies: ["instagram_account", "ig_post", "facebook_page", "fb_post"]
          },
          mailchimp: {
            channel: "mailchimp",
            contract_version: 1,
            query_contract_version: null,
            minimum_release: null,
            audited_at: "2026-10-30",
            metrics_count: 15,
            scopes: ["campaign_engagement", "store_conversions"],
            ui_dependencies: ["campaign_engagement", "store_conversions"]
          }
        },
        channel_metrics_catalog: {
          google_search_console: {
            channel: "google_search_console",
            contract_version: 1,
            audited_at: "2026-10-30",
            minimum_release: "1.18.3",
            default_search_type: "web",
            search_types: {
              web: { label: "Web", metrics: ["clicks", "impressions", "ctr", "position"], tabs: ["queries", "pages", "countries", "devices", "appearances"], scopes: ["non-searchAppearance", "searchAppearance"] },
              image: { label: "Images", metrics: ["clicks", "impressions", "ctr", "position"], tabs: ["queries", "pages", "countries", "devices", "appearances"], scopes: ["non-searchAppearance", "searchAppearance"] },
              video: { label: "Videos", metrics: ["clicks", "impressions", "ctr", "position"], tabs: ["queries", "pages", "countries", "devices", "appearances"], scopes: ["non-searchAppearance", "searchAppearance"] },
              news: { label: "News (Google Search tab)", metrics: ["clicks", "impressions", "ctr", "position"], tabs: ["queries", "pages", "countries", "devices", "appearances"], scopes: ["non-searchAppearance", "searchAppearance"] },
              discover: { label: "Discover", metrics: ["clicks", "impressions", "ctr"], tabs: ["pages", "countries"], scopes: ["non-searchAppearance"] },
              googleNews: { label: "Google News (site and app)", metrics: ["clicks", "impressions", "ctr"], tabs: ["pages", "countries"], scopes: ["non-searchAppearance"] }
            },
            metrics: {
              clicks: { label: "Clicks", unit: "count", reducer: "sum", is_additive: true },
              impressions: { label: "Impressions", unit: "count", reducer: "sum", is_additive: true },
              ctr: { label: "CTR", unit: "ratio", reducer: "clicks / impressions", is_additive: false },
              position: { label: "Average Position", unit: "rank", reducer: "impression_weighted_average", is_additive: false }
            },
            breakdowns: { queries: "query", pages: "dimensions.page", countries: "country", devices: "device", appearances: "dimensions.searchAppearance" },
            scopes: {
              "non-searchAppearance": { label: "Standard Search (Non-Appearance)", filter: { "dimensions.searchAppearance": "standard" }, aggregation_profiles: ["gsc_search_cube", "gsc_page_flow"] },
              searchAppearance: { label: "Search Appearance", filter: { "dimensions.searchAppearance": { operator: "neq", value: "standard" } }, grain: ["daily", "dimensions.searchAppearance"], aggregation_profiles: ["gsc_search_cube_by_appearance"], overlaps_standard: true, merge_with_standard: false }
            },
            filters: { search_type: "dimensions.search_type", semantic: ["dimensions.intent", "dimensions.brand_relation", "dimensions.business_relevance"] }
          },
          google_analytics: {
            channel: "google_analytics",
            contract_version: 1,
            query_contract_version: 1,
            audited_at: "2026-10-30",
            metrics: {
              screenPageViews: { label: "Page and screen views", unit: "count", reducer: "sum", scopes: ["account", "traffic_matrix"], status: "cached", is_additive: true },
              sessions: { label: "Sessions", unit: "count", reducer: "sum_within_one_scope", scopes: ["account", "traffic_matrix", "ad_touchpoint_matrix", "engagement_matrix"], status: "cached", is_additive: true },
              bounceRate: { label: "Bounce rate", unit: "ratio", reducer: "session_weighted_average", scopes: ["account", "traffic_matrix"], status: "cached", caveats: ["GA-02"], is_additive: true },
              totalRevenue: { label: "Total revenue", unit: "property_currency", reducer: "sum", scopes: ["account", "traffic_matrix", "ad_touchpoint_matrix"], status: "cached", is_additive: true },
              averageSessionDuration: { label: "Average session duration", unit: "seconds", reducer: "session_weighted_average", scopes: ["traffic_matrix"], status: "cached", caveats: ["GA-02"], is_additive: true },
              conversions: { label: "Key Events", sources: ["conversions", "keyEvents"], cache_name: "conversions", unit: "count", reducer: "sum", scopes: ["traffic_matrix", "event_matrix", "ad_touchpoint_matrix"], status: "cached", caveats: ["GA-04"], is_additive: true },
              eventCount: { label: "Event count", unit: "count", reducer: "sum", scopes: ["event_matrix"], status: "cached", is_additive: true },
              newUsers: { label: "New users", unit: "users", reducer: "source_distinct_for_requested_window", cached_reducer: "sum_of_reported_user_counts_not_window_unique", scopes: ["acquisition_matrix"], status: "cached", caveats: ["GA-02"], is_additive: false },
              activeUsers: { label: "Active users", unit: "users", reducer: "source_distinct_for_requested_window", cached_reducer: "sum_of_reported_user_counts_not_window_unique", scopes: ["acquisition_matrix"], status: "cached", caveats: ["GA-02"], is_additive: false },
              totalUsers: { label: "Total users", unit: "users", reducer: "source_distinct_for_requested_window", cached_reducer: "sum_of_reported_user_counts_not_window_unique", scopes: ["acquisition_matrix"], status: "cached", caveats: ["GA-02"], is_additive: false },
              engagedSessions: { label: "Engaged sessions", unit: "count", reducer: "sum", scopes: ["engagement_matrix"], status: "conditional", is_additive: true },
              userEngagementDuration: { label: "User engagement duration", unit: "seconds", reducer: "sum", scopes: ["engagement_matrix"], status: "conditional", is_additive: true },
              engagementRate: { label: "Engagement rate", unit: "ratio", reducer: "session_weighted_average", scopes: ["engagement_matrix"], status: "conditional", is_additive: true },
              ecommercePurchases: { label: "Purchases", unit: "count", reducer: "sum", scopes: ["ecommerce_matrix"], status: "conditional", is_additive: true },
              transactions: { label: "Transactions", unit: "count", reducer: "sum", scopes: ["ecommerce_matrix"], status: "conditional", is_additive: true },
              purchaseRevenue: { label: "Purchase revenue", unit: "property_currency", reducer: "sum", scopes: ["ecommerce_matrix"], status: "conditional", is_additive: true },
              grossPurchaseRevenue: { label: "Gross purchase revenue", unit: "property_currency", reducer: "sum", scopes: ["ecommerce_matrix"], status: "conditional", is_additive: true },
              refundAmount: { label: "Refund amount", unit: "property_currency", reducer: "sum", scopes: ["ecommerce_matrix"], status: "conditional", is_additive: true },
              itemsViewed: { label: "Items viewed", unit: "count", reducer: "sum", scopes: ["product_matrix"], status: "conditional", is_additive: true },
              itemsAddedToCart: { label: "Items added to cart", unit: "count", reducer: "sum", scopes: ["product_matrix"], status: "conditional", is_additive: true },
              itemsPurchased: { label: "Items purchased", unit: "count", reducer: "sum", scopes: ["product_matrix"], status: "conditional", is_additive: true },
              itemsRefunded: { label: "Items refunded", unit: "count", reducer: "sum", scopes: [], status: "conditional", is_additive: true },
              itemRevenue: { label: "Item revenue", unit: "property_currency", reducer: "sum", scopes: ["product_matrix"], status: "conditional", is_additive: true },
              grossItemRevenue: { label: "Gross item revenue", unit: "property_currency", reducer: "sum", scopes: ["product_matrix"], status: "conditional", is_additive: true },
              itemRefundAmount: { label: "Item refund amount", unit: "property_currency", reducer: "sum", scopes: ["product_matrix"], status: "conditional", is_additive: true },
              spend: { label: "Advertising spend", unit: "property_currency", reducer: "sum", scopes: [], status: "unsupported", caveats: ["GA-01"], is_additive: true }
            },
            scopes: {
              account: { label: "Account", metrics: ["screenPageViews", "sessions", "bounceRate", "totalRevenue"], grain: ["daily"], filter: { "dimensions.scope": { operator: "is_null" } }, merge_with_other_scopes: false },
              traffic_matrix: { label: "Traffic Matrix", metrics: ["screenPageViews", "sessions", "bounceRate", "averageSessionDuration", "totalRevenue", "conversions"], grain: ["daily", "dimensions.sessionDefaultChannelGroup", "dimensions.source", "dimensions.medium", "dimensions.landing_page", "channeledCampaign", "adGroup", "country", "device"], filter: { "dimensions.scope": "traffic_matrix" }, merge_with_other_scopes: false },
              event_matrix: { label: "Event Matrix", metrics: ["eventCount", "conversions"], grain: ["daily", "event", "dimensions.page", "dimensions.sessionDefaultChannelGroup", "dimensions.source", "dimensions.medium", "channeledCampaign", "adGroup", "ad"], filter: { "dimensions.scope": "event_matrix" }, merge_with_other_scopes: false },
              acquisition_matrix: { label: "Acquisition Matrix", metrics: ["newUsers", "activeUsers", "totalUsers"], grain: ["daily", "dimensions.firstUserDefaultChannelGroup", "dimensions.firstUserSourceMedium", "channeledCampaign", "adGroup", "ad"], filter: { "dimensions.scope": "acquisition_matrix" }, merge_with_other_scopes: false },
              ad_touchpoint_matrix: { label: "Ad Touchpoint Matrix", metrics: ["sessions", "conversions", "totalRevenue"], grain: ["daily", "channeledCampaign", "adGroup", "ad"], filter: { "dimensions.scope": "ad_touchpoint_matrix" }, merge_with_other_scopes: false },
              engagement_matrix: { label: "Engagement Matrix", metrics: ["sessions", "engagedSessions", "userEngagementDuration", "engagementRate"], grain: ["daily", "dimensions.sessionDefaultChannelGroup", "dimensions.sessionSourceMedium", "dimensions.landingPagePlusQueryString", "device", "country"], filter: { "dimensions.scope": "engagement_matrix", "dimensions.recipe_revision": "1" }, requires_opt_in: true, merge_with_other_scopes: false },
              ecommerce_matrix: { label: "Ecommerce Matrix", metrics: ["ecommercePurchases", "purchaseRevenue", "grossPurchaseRevenue", "refundAmount", "transactions"], grain: ["daily", "dimensions.sessionDefaultChannelGroup", "dimensions.sessionSourceMedium", "dimensions.sessionCampaignName", "device", "country"], filter: { "dimensions.scope": "ecommerce_matrix", "dimensions.recipe_revision": "1" }, requires_opt_in: true, merge_with_other_scopes: false },
              product_matrix: { label: "Product Matrix", metrics: ["itemsViewed", "itemsAddedToCart", "itemsPurchased", "itemRevenue", "grossItemRevenue", "itemRefundAmount"], grain: ["daily", "dimensions.itemId", "dimensions.itemName", "dimensions.itemBrand", "dimensions.itemCategory", "dimensions.itemVariant"], filter: { "dimensions.scope": "product_matrix", "dimensions.recipe_revision": "1" }, requires_opt_in: true, merge_with_other_scopes: false }
            },
            virtual_dimensions: {
              "dimensions.sessionSourceMedium": { parts: ["dimensions.source", "dimensions.medium"], separator: " / ", scopes: ["traffic_matrix", "event_matrix"] }
            },
            canonical_aliases: {
              sessions: "sessions", conversions: "conversions", reach: "activeUsers", impressions: "screenPageViews", new_users: "newUsers", revenue: "totalRevenue", bounce_rate: "bounceRate", average_session_duration: "averageSessionDuration", total_users: "totalUsers", event_count: "eventCount",
              engaged_sessions: "engagedSessions", user_engagement_duration: "userEngagementDuration", engagement_rate: "engagementRate", ecommerce_purchases: "ecommercePurchases", purchase_revenue: "purchaseRevenue", gross_purchase_revenue: "grossPurchaseRevenue", refund_amount: "refundAmount", transactions: "transactions",
              items_viewed: "itemsViewed", items_added_to_cart: "itemsAddedToCart", items_purchased: "itemsPurchased", item_revenue: "itemRevenue", gross_item_revenue: "grossItemRevenue", item_refund_amount: "itemRefundAmount"
            },
            caveats: {
              "GA-01": "Cost metrics are unsupported; revenue is not advertising spend.",
              "GA-02": "Reported user-count sums are not window uniques. Rates require complete same-grain session weights.",
              "GA-03": "Combined session source/medium is virtual and requires tenant query contract v1.",
              "GA-04": "Source key-event names are property-validated; cache identity remains conversions.",
              "GA-05": "Quality metadata is retained on new observations; historical quality and report-level badges are not reconstructed."
            }
          },
          google_ads: {
            channel: "google_ads",
            contract_version: 1,
            audited_at: "2026-10-30",
            metrics: {
              spend: { label: "Spend", sources: ["metrics.cost_micros"], unit: "account_currency", reducer: "sum", scopes: ["account", "campaign", "ad_group", "ad", "query"], status: "cached", is_additive: true },
              impressions: { label: "Impressions", sources: ["metrics.impressions"], unit: "count", reducer: "sum", scopes: ["account", "campaign", "ad_group", "ad", "query"], status: "cached", is_additive: true },
              clicks: { label: "Clicks", sources: ["metrics.clicks"], unit: "count", reducer: "sum", scopes: ["account", "campaign", "ad_group", "ad", "query"], status: "cached", is_additive: true },
              conversions: { label: "Conversions", sources: ["metrics.conversions"], unit: "count", reducer: "sum", scopes: ["account", "campaign", "ad_group", "ad", "query"], status: "cached", is_additive: true },
              conversion_value: { label: "Conversion value", sources: ["metrics.conversions_value"], unit: "account_currency", reducer: "sum", scopes: ["account", "campaign", "ad_group", "ad", "query"], status: "known_issue", caveats: ["ADS-01"], is_additive: true },
              roas_purchase: { label: "Return on ad spend", sources: ["metrics.conversions_value", "metrics.cost_micros"], unit: "ratio", reducer: "sum(conversion_value) / sum(spend)", scopes: ["account", "campaign", "ad_group", "ad", "query"], status: "known_issue", caveats: ["ADS-01"], is_additive: true },
              ctr: { label: "CTR", unit: "ratio", reducer: "clicks / impressions", scopes: ["account", "campaign", "ad_group", "ad", "query"], status: "derived", caveats: ["ADS-02"], is_additive: true },
              cpc: { label: "Cost per click", unit: "account_currency", reducer: "spend / clicks", scopes: ["account", "campaign", "ad_group", "ad", "query"], status: "derived", caveats: ["ADS-02"], is_additive: true },
              cpm: { label: "Cost per thousand impressions", unit: "account_currency", reducer: "1000 * spend / impressions", scopes: ["account", "campaign", "ad_group", "ad", "query"], status: "derived", caveats: ["ADS-02"], is_additive: true },
              cost_per_conversion: { label: "Cost per conversion", unit: "account_currency", reducer: "spend / conversions", scopes: ["account", "campaign", "ad_group", "ad", "query"], status: "derived", caveats: ["ADS-02"], is_additive: true },
              conversion_rate: { label: "Conversion rate (click based)", unit: "ratio", reducer: "conversions / clicks", scopes: ["account", "campaign", "ad_group", "ad", "query"], status: "derived", caveats: ["ADS-02"], is_additive: true },
              video_views: { label: "Video views", sources: ["metrics.video_views"], unit: "count", reducer: "sum", scopes: [], status: "source_only", caveats: ["ADS-03"], is_additive: true }
            },
            scopes: ["account", "campaign", "ad_group", "ad", "query"],
            ui_dependencies: ["campaign_level", "adgroup_level", "ad_level", "device_level", "search_term_level"],
            dimensions: {
              device: { source: "segments.device", scopes: ["account", "campaign", "ad_group", "ad", "query"] },
              campaignType: { source: "campaign.advertising_channel_type", scopes: ["campaign", "ad"] },
              query: { source: "search_term_view.search_term", scopes: ["query"] }
            },
            filters: { account: "channeledAccount", semantic: ["dimensions.intent", "dimensions.brand_relation", "dimensions.business_relevance"] },
            canonical_aliases: { revenue: "conversion_value", total_revenue: "conversion_value", search_term: "query (dimension, not a numeric metric)" },
            caveats: {
              "ADS-01": "Conversion value is assigned to ROAS; the default conversion_value key does not match the normalized conversions_value key.",
              "ADS-02": "Ratios must be recomputed from totals. Click-based conversion rate is not necessarily Google interaction-based conversion rate.",
              "ADS-03": "Requested metrics are not forwarded to the converter whitelist. Dictionary membership does not prove ingestion."
            }
          },
          facebook_marketing: {
            channel: "facebook_marketing",
            contract_version: 1,
            audited_at: "2026-10-30",
            metrics: {
              spend: { label: "Spend", unit: "currency", reducer: "sum", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: true },
              clicks: { label: "Clicks", unit: "count", reducer: "sum", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: true },
              impressions: { label: "Impressions", unit: "count", reducer: "sum", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: true },
              reach: { label: "Reach", unit: "count", reducer: "provider_reach", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: false },
              results: { label: "Results", unit: "count", reducer: "sum", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: true },
              purchases: { label: "Purchases", unit: "count", reducer: "sum", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: true },
              purchase_value: { label: "Purchase Value", unit: "currency", reducer: "sum", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: true },
              leads: { label: "Leads", unit: "count", reducer: "sum", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: true },
              frequency: { label: "Frequency", unit: "ratio", reducer: "impressions / reach", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: false },
              cpm: { label: "CPM", unit: "currency", reducer: "1000 * spend / impressions", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: false },
              ctr: { label: "CTR", unit: "ratio", reducer: "clicks / impressions", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: false },
              cpc: { label: "CPC", unit: "currency", reducer: "spend / clicks", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: false },
              purchase_roas: { label: "ROAS", unit: "ratio", reducer: "provider_purchase_roas", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: false },
              cost_per_result: { label: "Cost per Result", unit: "currency", reducer: "spend / results", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: false },
              result_rate: { label: "Result Rate", unit: "ratio", reducer: "results / impressions", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: false },
              cost_per_purchase: { label: "Cost per Purchase", unit: "currency", reducer: "spend / purchases", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: false },
              cost_per_lead: { label: "Cost per Lead", unit: "currency", reducer: "spend / leads", scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], status: "conditional", is_additive: false }
            },
            scopes: ["account_level", "campaign_level", "adset_level", "ad_level"],
            dimensions: {
              channeledCampaign: { scopes: ["campaign_level", "adset_level", "ad_level"] },
              adGroup: { scopes: ["adset_level", "ad_level"] },
              ad: { scopes: ["ad_level"] },
              "dimensions.age": { scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], requires_pass: "demographics" },
              "dimensions.gender": { scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], requires_pass: "demographics" },
              "dimensions.country": { scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], requires_pass: "geography" },
              "dimensions.publisher_platform": { scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], requires_pass: "placement" },
              "dimensions.platform_position": { scopes: ["account_level", "campaign_level", "adset_level", "ad_level"], requires_pass: "placement" }
            }
          },
          facebook_organic: {
            channel: "facebook_organic",
            contract_version: 1,
            audited_at: "2026-10-30",
            metrics: {
              likes: { label: "Likes / mapped reactions", unit: "count", reducer: "sum", scopes: ["facebook_page", "instagram_account", "fb_post", "ig_post"], is_additive: true },
              comments: { label: "Comments", unit: "count", reducer: "sum", scopes: ["facebook_page", "instagram_account", "fb_post", "ig_post"], is_additive: true },
              reach: { label: "Reach", unit: "users", reducer: "source_distinct_for_requested_window", scopes: ["facebook_page", "instagram_account", "fb_post", "ig_post"], caveats: ["ORG-03"], is_additive: true },
              views: { label: "Views / plays", unit: "count", reducer: "sum", scopes: ["facebook_page", "instagram_account", "fb_post", "ig_post"], is_additive: true },
              content_views: { label: "Content views", unit: "count", reducer: "sum", scopes: ["instagram_account"], is_additive: true },
              page_views_total: { label: "Page views", unit: "count", reducer: "sum", scopes: ["facebook_page"], is_additive: true },
              video_views: { label: "Video views", unit: "count", reducer: "sum", scopes: ["facebook_page", "fb_post"], is_additive: true },
              profile_views: { label: "Profile views", unit: "count", reducer: "sum", scopes: ["instagram_account"], is_additive: true },
              website_clicks: { label: "Website clicks", unit: "count", reducer: "sum", scopes: ["instagram_account"], is_additive: true },
              profile_links_taps: { label: "Profile link taps", unit: "count", reducer: "sum", scopes: ["instagram_account"], is_additive: true },
              follows_and_unfollows: { label: "Follows and unfollows", unit: "count", reducer: "preserve_follow_type", scopes: ["instagram_account", "facebook_page"], is_additive: true },
              saves: { label: "Saves", unit: "count", reducer: "sum", scopes: ["instagram_account", "ig_post"], is_additive: true },
              shares: { label: "Shares", unit: "count", reducer: "sum", scopes: ["facebook_page", "instagram_account", "fb_post", "ig_post"], is_additive: true },
              total_interactions: { label: "Interactions", unit: "count", reducer: "sum", scopes: ["facebook_page", "instagram_account", "fb_post", "ig_post"], is_additive: true },
              replies: { label: "Replies", unit: "count", reducer: "sum", scopes: ["instagram_account", "ig_post"], is_additive: true },
              accounts_engaged: { label: "Accounts engaged", unit: "users", reducer: "source_distinct_for_requested_window", scopes: ["instagram_account"], caveats: ["ORG-03"], is_additive: true },
              post_clicks: { label: "Post clicks", unit: "count", reducer: "sum", scopes: ["fb_post"], is_additive: true },
              ig_reels_avg_watch_time: { label: "Reels average watch time", unit: "source_time_unit", reducer: "view_weighted_average", scopes: ["ig_post"], is_additive: true },
              post_video_avg_time_watched: { label: "Average video watch time", unit: "source_time_unit", reducer: "view_weighted_average", scopes: ["fb_post"], is_additive: true },
              ig_reels_video_view_total_time: { label: "Reels total watch time", unit: "source_time_unit", reducer: "sum", scopes: ["ig_post"], is_additive: true },
              profile_activity: { label: "Profile activity", unit: "count", reducer: "sum", scopes: ["ig_post"], is_additive: true },
              profile_visits: { label: "Profile visits", unit: "count", reducer: "sum", scopes: ["ig_post"], is_additive: true },
              reposts: { label: "Reposts", unit: "count", reducer: "sum", scopes: ["ig_post"], is_additive: true },
              follows: { label: "Follows", unit: "count", reducer: "sum", scopes: ["ig_post"], is_additive: true },
              follower_total: { label: "Followers (snapshot)", unit: "count", reducer: "latest_snapshot_per_account", scopes: ["facebook_page", "instagram_account"], status: "conditional", is_additive: false }
            },
            scopes: ["facebook_page", "instagram_account", "fb_post", "ig_post"],
            dimensions: {
              "dimensions.media_product_type": { scopes: ["instagram_account"] },
              "dimensions.follow_type": { scopes: ["instagram_account"] },
              "dimensions.contact_button_type": { scopes: ["instagram_account"] },
              "dimensions.reaction_type": { scopes: ["facebook_page", "fb_post"] }
            }
          },
          mailchimp: {
            channel: "mailchimp",
            contract_version: 1,
            audited_at: "2026-10-30",
            metrics: {
              sends: { label: "Sends", sources: ["sends"], unit: "count", reducer: "sum", scopes: ["campaign_engagement"], status: "preaggregated", is_additive: true },
              opens: { label: "Opens", sources: ["opens_total"], unit: "count", reducer: "sum", scopes: ["campaign_engagement"], status: "preaggregated", is_additive: true },
              opens_standard: { label: "Opens Standard", sources: ["opens_standard"], unit: "count", reducer: "sum", scopes: ["campaign_engagement"], status: "preaggregated", is_additive: true },
              opens_proxy: { label: "Opens Proxy", sources: ["opens_proxy"], unit: "count", reducer: "sum", scopes: ["campaign_engagement"], status: "preaggregated", is_additive: true },
              opens_unique_daily: { label: "Daily unique openers", sources: ["opens_unique_daily"], unit: "count", reducer: "distinct_identity_per_campaign_day", scopes: ["campaign_engagement"], status: "preaggregated", is_additive: false },
              clicks: { label: "Clicks", sources: ["clicks_total"], unit: "count", reducer: "sum", scopes: ["campaign_engagement"], status: "preaggregated", is_additive: true },
              clicks_unique: { label: "Clicks Unique", sources: ["clicks_unique"], unit: "count", reducer: "sum_of_daily_distinct_counts_within_slice", scopes: ["campaign_engagement"], status: "preaggregated", is_additive: true },
              bounces: { label: "Bounces", sources: ["bounces_total"], unit: "count", reducer: "sum", scopes: ["campaign_engagement"], status: "preaggregated", is_additive: true },
              bounces_hard: { label: "Bounces Hard", sources: ["bounces_hard"], unit: "count", reducer: "sum", scopes: ["campaign_engagement"], status: "preaggregated", is_additive: true },
              bounces_soft: { label: "Bounces Soft", sources: ["bounces_soft"], unit: "count", reducer: "sum", scopes: ["campaign_engagement"], status: "preaggregated", is_additive: true },
              unsubscribes: { label: "Unsubscribes", sources: ["unsubscribes"], unit: "count", reducer: "sum", scopes: ["campaign_engagement"], status: "preaggregated", is_additive: true },
              open_rate: { label: "Open Rate", unit: "ratio", reducer: "opens / sends", scopes: ["campaign_engagement"], status: "derived", is_additive: true },
              click_rate: { label: "Click Rate", unit: "ratio", reducer: "clicks / sends", scopes: ["campaign_engagement"], status: "derived", is_additive: true },
              orders: { label: "Orders", sources: ["orders_count"], unit: "count", reducer: "sum", scopes: ["store_conversions"], status: "preaggregated", is_additive: true },
              revenue: { label: "Revenue", sources: ["revenue"], unit: "store_currency", reducer: "sum", scopes: ["store_conversions"], status: "preaggregated", is_additive: true }
            },
            scopes: {
              campaign_engagement: { label: "Campaign engagement", metrics: ["sends", "opens", "opens_standard", "opens_proxy", "opens_unique_daily", "clicks", "clicks_unique", "bounces", "bounces_hard", "bounces_soft", "unsubscribes", "open_rate", "click_rate"], grain: ["daily", "campaign", "optional URL slice"] },
              store_conversions: { label: "Connected store orders", metrics: ["orders", "revenue"], grain: ["daily", "audience", "store_id", "currency"] }
            },
            dimensions: {
              channeledCampaign: { scopes: ["campaign_engagement"] },
              campaignType: { scopes: ["campaign_engagement"] },
              "dimensions.page": { scopes: ["campaign_engagement"], metrics: ["clicks", "clicks_unique"] },
              "dimensions.store_id": { scopes: ["store_conversions"] },
              "dimensions.currency": { scopes: ["store_conversions"] }
            }
          }
        },
        data_scopes: {
          scope_global: {
            name: "Global Scope",
            description: "Cross-channel blended metrics aggregated across all active providers.",
            applicable_channels: ["all"],
            sample_metrics: ["total_spend", "total_clicks", "total_impressions", "blended_roas", "blended_cpa", "true_blended_marginal_cost"]
          },
          scope_channel: {
            name: "Channel Scope",
            description: "Metrics specific to a marketing provider or platform channel.",
            applicable_channels: ["meta", "google", "shopify", "klaviyo", "amazon", "tiktok"],
            sample_metrics: ["spend", "clicks", "impressions", "reach", "frequency", "ctr", "cpc", "cpm", "roas", "spend_elasticity"]
          },
          scope_asset: {
            name: "Asset Scope",
            description: "Sub-channel metrics isolated to specific ad accounts, profiles, stores or properties.",
            applicable_channels: ["facebook_marketing", "facebook_organic", "google_search_console", "google_analytics", "google_ads"],
            sample_metrics: ["position", "sessions", "conversions", "bounce_rate", "asset_ctr_anomaly", "organic_vs_paid_clicks"]
          }
        },
        supported_channels: {
          meta: ["spendable", "clickable", "impressionable", "paid_media", "organic_social", "reach_driven"],
          google: ["spendable", "clickable", "impressionable", "seo", "traffic_tracked", "conversion_tracked", "revenue_tracked", "behavior_tracked", "analytics", "paid_media"],
          klaviyo: ["revenue_tracked", "conversion_tracked", "email_marketing"],
          mailchimp: ["audience_tracked", "campaign_tracked", "email_marketing", "clickable", "conversion_tracked", "revenue_tracked"],
          shopify: ["revenue_tracked", "conversion_tracked", "ecommerce"],
          facebook_marketing: ["spendable", "clickable", "impressionable", "paid_media"],
          facebook_organic: ["organic_social", "reach_driven", "impressionable"],
          google_search_console: ["clickable", "impressionable", "seo"],
          google_analytics: ["traffic_tracked", "conversion_tracked", "revenue_tracked", "behavior_tracked", "analytics"],
          google_ads: ["spendable", "clickable", "impressionable", "paid_media"]
        },
        canonical_metrics: [
          "spend", "clicks", "impressions", "reach", "frequency", "ctr", "cpc", "cpm",
          "sessions", "new_users", "conversions", "cost_per_conversion", "conversion_rate",
          "roas_purchase", "position", "engagement", "page_views", "event_count", "bounce_rate",
          "average_session_duration", "total_users", "total_revenue",
          "sends", "opens", "opens_standard", "opens_proxy", "clicks_unique", "bounces", "bounces_hard", "bounces_soft", "unsubscribes", "orders", "revenue"
        ],
        derived_formulas: [
          { formula: "spend", description: "Total advertising cost" },
          { formula: "clicks", description: "Total link or ad clicks" },
          { formula: "impressions", description: "Total visual impressions" },
          { formula: "ctr", description: "Click-Through Rate (clicks / impressions * 100)" },
          { formula: "cpc", description: "Cost Per Click (spend / clicks)" },
          { formula: "cpm", description: "Cost Per Mille (spend / impressions * 1000)" },
          { formula: "roas", description: "Return on Ad Spend (revenue / spend)" },
          { formula: "cost_per_result", description: "Cost Per Conversion / Result (spend / results)" },
          { formula: "result_rate", description: "Conversion Rate (results / clicks * 100)" },
          { formula: "position", description: "Weighted Average Ranking Position (weighted by impressions)" },
          { formula: "sends", description: "Total email sends dispatched" },
          { formula: "opens", description: "Total email open events" },
          { formula: "open_rate", description: "Email Open Rate (opens / sends * 100)" },
          { formula: "click_to_open_rate", description: "Click-to-Open Rate (clicks / opens * 100)" },
          { formula: "unsubscribe_rate", description: "Unsubscribe Rate (unsubscribes / sends * 100)" }
        ],
        granularities: {
          temporal: ["daily", "weekly", "monthly", "quarterly", "yearly"],
          dimensional: ["device", "country", "query", "page", "campaign", "ad_set", "placement", "dimensions.*"]
        },
        channel_datascopes: {
          mailchimp: {
            contract_version: 1,
            audited_at: "2026-10-30",
            scopes: ["campaign_engagement", "store_conversions"],
            default_scope: "campaign_engagement",
            scope_rules: {
              "campaign_engagement": {
                description: "Campaign-level email performance, opens, clicks, bounces, unsubscribes, and URL slices.",
                metrics: ["sends", "opens", "opens_standard", "opens_proxy", "opens_unique_daily", "clicks", "clicks_unique", "bounces", "bounces_hard", "bounces_soft", "unsubscribes", "open_rate", "click_rate"],
                breakdowns: ["channeledCampaign", "campaignType", "dimensions.page"]
              },
              "store_conversions": {
                description: "Connected eCommerce store attribution orders and revenue driven by campaigns.",
                metrics: ["orders", "revenue"],
                breakdowns: ["dimensions.store_id", "dimensions.currency"]
              }
            }
          },
          google_search_console: {
            contract_version: 1,
            minimum_release: "1.18.3",
            audited_at: "2026-10-30",
            search_types: ["web", "image", "video", "news", "discover", "googleNews"],
            scopes: ["non-searchAppearance", "searchAppearance"],
            default_scope: "non-searchAppearance",
            scope_rules: {
              "non-searchAppearance": {
                description: "Standard search queries, landing pages, devices, and countries. Always requires dimensions.searchAppearance='standard' to avoid duplicate counting across search features.",
                enforced_filter: { "dimensions.searchAppearance": "standard" },
                metrics: ["clicks", "impressions", "ctr", "position"],
                breakdowns: ["query", "dimensions.page", "country", "device"]
              },
              "searchAppearance": {
                description: "Aggregations by Google Search feature (AMP, Good Page Experience, Review Snippets, etc). Overlaps standard scope; never merge with standard.",
                metrics: ["clicks", "impressions", "ctr", "position"],
                breakdowns: ["dimensions.searchAppearance"]
              }
            }
          },
          google_analytics: {
            contract_version: 1,
            query_contract_version: 1,
            audited_at: "2026-10-30",
            scopes: ["account", "traffic_matrix", "acquisition_matrix", "event_matrix", "ad_touchpoint_matrix", "engagement_matrix", "ecommerce_matrix", "product_matrix"],
            default_scope: "traffic_matrix",
            scope_rules: {
              "account": {
                description: "Property-level daily totals (dimensions.scope IS NULL).",
                metrics: ["screenPageViews", "sessions", "bounceRate", "totalRevenue"],
                breakdowns: []
              },
              "traffic_matrix": {
                description: "Session-level traffic and landing page metrics.",
                metrics: ["screenPageViews", "sessions", "bounceRate", "averageSessionDuration", "totalRevenue", "conversions"],
                breakdowns: ["dimensions.sessionDefaultChannelGroup", "dimensions.sessionSourceMedium", "dimensions.source", "dimensions.medium", "dimensions.landing_page", "channeledCampaign", "adGroup", "device", "country"]
              },
              "acquisition_matrix": {
                description: "First-user attribution and user acquisition (non-additive reported user counts).",
                metrics: ["newUsers", "activeUsers", "totalUsers"],
                breakdowns: ["dimensions.firstUserDefaultChannelGroup", "dimensions.firstUserSourceMedium", "channeledCampaign", "adGroup", "ad"]
              },
              "event_matrix": {
                description: "Event-level interactions and key event conversions.",
                metrics: ["eventCount", "conversions"],
                breakdowns: ["event", "dimensions.page", "dimensions.sessionDefaultChannelGroup", "dimensions.sessionSourceMedium", "channeledCampaign", "adGroup", "ad"]
              },
              "ad_touchpoint_matrix": {
                description: "Paid touchpoints linking campaigns, ad groups, and ads.",
                metrics: ["sessions", "conversions", "totalRevenue"],
                breakdowns: ["channeledCampaign", "adGroup", "ad"]
              },
              "engagement_matrix": {
                description: "Opt-in engagement sessions, duration, and rate (recipe_revision='1').",
                metrics: ["sessions", "engagedSessions", "userEngagementDuration", "engagementRate"],
                breakdowns: ["dimensions.sessionDefaultChannelGroup", "dimensions.sessionSourceMedium", "dimensions.landingPagePlusQueryString", "device", "country"]
              },
              "ecommerce_matrix": {
                description: "Opt-in purchase revenue, gross revenue, refunds, and transactions (recipe_revision='1').",
                metrics: ["ecommercePurchases", "purchaseRevenue", "grossPurchaseRevenue", "refundAmount", "transactions"],
                breakdowns: ["dimensions.sessionDefaultChannelGroup", "dimensions.sessionSourceMedium", "dimensions.sessionCampaignName", "device", "country"]
              },
              "product_matrix": {
                description: "Opt-in item-level views, cart additions, purchases, and item revenue (recipe_revision='1').",
                metrics: ["itemsViewed", "itemsAddedToCart", "itemsPurchased", "itemRevenue", "grossItemRevenue", "itemRefundAmount"],
                breakdowns: ["dimensions.itemId", "dimensions.itemName", "dimensions.itemBrand", "dimensions.itemCategory", "dimensions.itemVariant"]
              }
            }
          },
          google_ads: {
            contract_version: 1,
            audited_at: "2026-10-30",
            scopes: ["account", "campaign", "ad_group", "ad", "query"],
            ui_dependencies: ["campaign_level", "adgroup_level", "ad_level", "device_level", "search_term_level"],
            default_scope: "campaign",
            scope_rules: {
              "campaign": {
                description: "Campaign-level Google Ads performance and conversions.",
                metrics: ["spend", "impressions", "clicks", "conversions", "conversion_value", "roas_purchase", "ctr", "cpc", "cpm", "cost_per_conversion", "conversion_rate"],
                breakdowns: ["channeledCampaign", "campaignType", "device"]
              },
              "query": {
                description: "Search term view performance (opt-in, privacy-limited coverage).",
                metrics: ["spend", "impressions", "clicks", "conversions", "conversion_value", "roas_purchase", "ctr", "cpc", "cpm", "cost_per_conversion", "conversion_rate"],
                breakdowns: ["query", "device"]
              }
            }
          },
          facebook_marketing: {
            contract_version: 1,
            audited_at: "2026-10-30",
            scopes: ["ad_level", "adset_level", "campaign_level", "account_level"],
            default_scope: "ad_level",
            scope_rules: {
              "ad_level": {
                description: "Finest atomized granularity. All metrics are collected at the ad level and roll up hierarchically.",
                metrics: ["spend", "clicks", "impressions", "reach", "results", "purchases", "purchase_value", "leads", "frequency", "ctr", "cpc", "cpm", "purchase_roas", "cost_per_result", "result_rate", "cost_per_purchase", "cost_per_lead"],
                breakdowns: ["ad", "adGroup", "channeledCampaign", "channeledAccount", "dimensions.age", "dimensions.gender", "dimensions.country", "dimensions.publisher_platform", "dimensions.platform_position"]
              }
            }
          },
          facebook_organic: {
            contract_version: 1,
            audited_at: "2026-10-30",
            scopes: ["instagram_account", "ig_post", "facebook_page", "fb_post"],
            default_scope: "instagram_account",
            scope_rules: {
              "instagram_account": {
                description: "Account-level organic reach, views, follower snapshots, and profile clicks.",
                metrics: ["reach", "views", "content_views", "follows_and_unfollows", "profile_views", "website_clicks", "profile_links_taps", "accounts_engaged", "total_interactions", "likes", "comments", "saves", "shares", "replies", "follower_total"],
                breakdowns: ["dimensions.contact_button_type", "dimensions.follow_type", "dimensions.media_product_type"]
              },
              "ig_post": {
                description: "Media/post-level engagement, reels watch time, and comments.",
                metrics: ["reach", "views", "likes", "comments", "shares", "saves", "replies", "total_interactions", "ig_reels_avg_watch_time", "ig_reels_video_view_total_time", "profile_activity", "profile_visits", "reposts", "follows"],
                breakdowns: ["post", "ig_post"]
              },
              "facebook_page": {
                description: "Facebook Page aggregate metrics and follower snapshots.",
                metrics: ["reach", "page_views_total", "views", "follows_and_unfollows", "likes", "comments", "shares", "total_interactions", "video_views", "follower_total"],
                breakdowns: ["dimensions.reaction_type"]
              },
              "fb_post": {
                description: "Facebook post interactions and video watch time.",
                metrics: ["reach", "views", "video_views", "likes", "post_clicks", "total_interactions", "comments", "shares", "post_video_avg_time_watched"],
                breakdowns: ["post", "fb_post", "dimensions.reaction_type"]
              }
            }
          }
        },
        recipes_and_guidance: {
          guide_tool: "Call 'get_mcp_guide' with topic='datascopes', topic='entity_measures', or topic='examples' for detailed recipes.",
          discovery_tool: "Call 'list_connected_assets' to discover exact asset IDs for filters: { channeledAccount: '<id>' } or account_ids in 'aggregate_entities'."
        },
        entity_measures: {
          description: "Two-Stage dynamic Entity Measures aggregation over APIs Hub's 10 cached entity tables. Can be queried directly via 'aggregate_entities' or added to Custom Dashboard widgets via 'create_dashboard_draft' / 'update_dashboard_draft' using source_type='entity_measure' or source_config.raw_series with type='entity_measure'.",
          canonical_deduplication_rule: "Pass deduplicate_canonical=true (default when not grouping by 'channel') to deduplicate orders shared across multiple channels (e.g. Shopify, Mailchimp, Klaviyo, GA4) by canonical order_id before summing revenue or computing AOV. Pass dimensions=['channel'] with deduplicate_canonical=false to compare channel-attributed orders side-by-side.",
          multi_currency_safety_rule: "Monetary measures ('revenue', 'subtotal', 'total_tax', 'total_discount', 'aov', 'customer_ltv', 'avg_revenue_per_customer', 'average_price', 'min_price', 'max_price', 'total_budget', 'average_budget') require a single currency in scope, filters.currency='USD', or dimensions=['currency']. If currencies are mixed without a filter/dimension, monetary measures return null and meta.currency_mixed=true.",
          time_buckets: ["day", "week", "month", "year"],
          entities: {
            orders: {
              label: "Orders",
              measures: {
                order_count: { label: "Order Count", type: "integer", reducer: "count", monetary: false },
                canonical_order_count: { label: "Distinct Canonical Orders", type: "integer", reducer: "count", monetary: false },
                unique_customers: { label: "Unique Buying Customers", type: "integer", reducer: "count", monetary: false },
                revenue: { label: "Total Revenue", type: "float", reducer: "sum", monetary: true },
                subtotal: { label: "Subtotal", type: "float", reducer: "sum", monetary: true },
                total_tax: { label: "Total Tax", type: "float", reducer: "sum", monetary: true },
                total_discount: { label: "Total Discount", type: "float", reducer: "sum", monetary: true },
                aov: { label: "Average Order Value (AOV)", type: "float", reducer: "ratio (revenue / order_count)", monetary: true }
              },
              dimensions: ["store", "channel", "account", "product", "discount_code", "financial_status", "fulfillment_status", "currency", "shipping_country"]
            },
            customers: {
              label: "Customers",
              measures: {
                customer_count: { label: "Source Profile Count", type: "integer", reducer: "count", monetary: false },
                canonical_customer_count: { label: "Distinct Canonical Customers", type: "integer", reducer: "count", monetary: false },
                customer_ltv: { label: "Cross-Store Customer Lifetime Value (LTV)", type: "float", reducer: "custom_customer_commerce", monetary: true },
                avg_revenue_per_customer: { label: "Average Revenue per Customer", type: "float", reducer: "custom_customer_commerce", monetary: true },
                repeat_customer_count: { label: "Repeat Buyers (>1 Order)", type: "integer", reducer: "custom_customer_commerce", monetary: false },
                high_frequency_customer_count: { label: "High-Frequency Buyers (>=3 Orders)", type: "integer", reducer: "custom_customer_commerce", monetary: false },
                repeat_buyer_rate: { label: "Repeat Buyer Rate (%)", type: "float", reducer: "custom_customer_commerce", monetary: false },
                repeat_frequency: { label: "Customer Purchase Frequency Distribution", type: "distribution", reducer: "custom", monetary: false },
                pre_purchase_touches: { label: "Pre-Purchase Touchpoints Attribution", type: "attribution", reducer: "custom", monetary: false }
              },
              dimensions: ["store", "channel", "status", "account"]
            },
            stores: {
              label: "Stores",
              measures: {
                store_count: { label: "Connected Stores Count", type: "integer", reducer: "count", monetary: false }
              },
              dimensions: ["channel", "currency", "account"]
            },
            products: {
              label: "Products",
              measures: {
                product_count: { label: "Product Count", type: "integer", reducer: "count", monetary: false },
                canonical_product_count: { label: "Distinct Canonical Products", type: "integer", reducer: "count", monetary: false },
                average_price: { label: "Average Price", type: "float", reducer: "avg", monetary: true },
                min_price: { label: "Minimum Price", type: "float", reducer: "min", monetary: true },
                max_price: { label: "Maximum Price", type: "float", reducer: "max", monetary: true }
              },
              dimensions: ["store", "channel", "vendor", "product_type", "sku", "currency"]
            },
            discounts: {
              label: "Discounts & Promo Codes",
              aliases: ["promotions", "promo-codes"],
              measures: {
                discount_count: { label: "Discount Codes Count", type: "integer", reducer: "count", monetary: false },
                total_usage: { label: "Total Usage Count", type: "integer", reducer: "sum", monetary: false }
              },
              dimensions: ["store", "channel", "code", "status"]
            },
            campaigns: {
              label: "Campaigns",
              measures: {
                campaign_count: { label: "Campaign Count", type: "integer", reducer: "count", monetary: false },
                total_budget: { label: "Total Budget", type: "float", reducer: "sum", monetary: true },
                average_budget: { label: "Average Budget", type: "float", reducer: "avg", monetary: true }
              },
              dimensions: ["status", "objective", "buying_type", "channel", "account"]
            },
            "ad-groups": {
              label: "Ad Sets & Ad Groups",
              aliases: ["ad-sets"],
              measures: {
                ad_group_count: { label: "Ad Sets / Groups Count", type: "integer", reducer: "count", monetary: false }
              },
              dimensions: ["status", "campaign", "optimization_goal", "billing_event", "channel", "account"]
            },
            ads: {
              label: "Ads",
              measures: {
                ad_count: { label: "Ad Count", type: "integer", reducer: "count", monetary: false }
              },
              dimensions: ["status", "campaign", "ad_group", "channel", "account"]
            },
            posts: {
              label: "Posts (Organic)",
              measures: {
                post_count: { label: "Published Posts Count", type: "integer", reducer: "count", monetary: false }
              },
              dimensions: ["page", "media_type", "account"]
            },
            events: {
              label: "Events",
              measures: {
                event_occurrence_count: { label: "Event Occurrences Count", type: "integer", reducer: "count", monetary: false },
                distinct_event_names: { label: "Distinct Event Names", type: "integer", reducer: "count", monetary: false }
              },
              dimensions: ["channel", "action", "account"]
            }
          }
        },
        benchmarks: {
          google_search_console: {
            top_3_ctr: "25% - 35% (Pos 1); 12% - 16% (Pos 2); 8% - 11% (Pos 3)",
            top_10_ctr: "2% - 4% (Pos 4-10)",
            health_rule: "Position <= 3 with CTR < 5% signals snippet mismatch or rich snippet displacement."
          },
          google_analytics_ga4: {
            bounce_rate: "40% - 60% (content/blog); 25% - 45% (ecommerce)",
            engagement_rate: "55% - 70% (healthy across search/direct)",
            session_duration_minutes: "1.5 - 3.5 mins"
          },
          facebook_marketing: {
            ctr: "0.90% - 1.60% (ecommerce); 0.70% - 1.10% (B2B)",
            cpc: "$0.80 - $2.50 (geography and competition dependent)",
            frequency_7d: "1.5 - 3.0 (frequency > 4.5 signals creative exhaustion)"
          },
          ecommerce_funnel: {
            cvr: "1.8% - 3.5% (sessions to orders)",
            cart_abandonment_rate: "65% - 75%",
            blended_mer: "3.0x - 5.0x (mature brands); 1.8x - 2.5x (growth phase)"
          }
        }
      };

      // Load reference library of predefined platform KPIs & Derived Metrics from project_context.json if available
      try {
        const filePath = path.join(APIS_HUB_ROOT, "config", "project_context.json");
        const fileExists = fs.existsSync(filePath);
        if (fileExists) {
          const raw = fs.readFileSync(filePath, "utf-8");
          const ctx = JSON.parse(raw);
          if (ctx.catalog_versions && typeof ctx.catalog_versions === "object") {
            catalog.catalog_versions = {
              ...catalog.catalog_versions,
              ...ctx.catalog_versions
            };
          }
          if (ctx.channel_metrics_catalog && typeof ctx.channel_metrics_catalog === "object") {
            catalog.channel_metrics_catalog = {
              ...catalog.channel_metrics_catalog,
              ...ctx.channel_metrics_catalog
            };
          }
          if (ctx.reference_library) {
            catalog.reference_library = {
              notice: "These predefined KPIs and Derived Metrics are platform-wide reference definitions and templates provided for analytical inspiration and deep analysis. They are NOT active custom KPIs created specifically for this project.",
              predefined_kpis_count: Object.keys(ctx.reference_library.predefined_kpis || {}).length,
              predefined_kpis: ctx.reference_library.predefined_kpis || {},
              predefined_derived_metrics_count: Object.keys(ctx.reference_library.predefined_derived_metrics || {}).length,
              predefined_derived_metrics: ctx.reference_library.predefined_derived_metrics || {}
            };
          } else {
            catalog.reference_library_debug = {
              filePath,
              fileExists: true,
              synced_at: ctx.synced_at || null,
              ctxKeys: Object.keys(ctx),
              hasReferenceLibrary: false
            };
          }
        } else {
          catalog.reference_library_debug = {
            filePath,
            fileExists: false
          };
        }
      } catch (e) {
        catalog.reference_library_debug = { error: e.message };
      }

      if (section) {
        if (section === "reference_library") {
          return {
            content: [{
              type: "text",
              text: JSON.stringify(catalog.reference_library || { 
                notice: "Reference library not loaded yet.",
                debug: catalog.reference_library_debug || "No exception, but ctx.reference_library was falsy or config/project_context.json was not found."
              }, null, 2)
            }]
          };
        }
        if (section === "predefined_kpis") {
          return {
            content: [{
              type: "text",
              text: JSON.stringify({
                notice: catalog.reference_library?.notice || "Platform-wide reference library",
                count: catalog.reference_library?.predefined_kpis_count || 0,
                predefined_kpis: catalog.reference_library?.predefined_kpis || {}
              }, null, 2)
            }]
          };
        }
        if (section === "predefined_derived_metrics") {
          return {
            content: [{
              type: "text",
              text: JSON.stringify({
                notice: catalog.reference_library?.notice || "Platform-wide reference library",
                count: catalog.reference_library?.predefined_derived_metrics_count || 0,
                predefined_derived_metrics: catalog.reference_library?.predefined_derived_metrics || {}
              }, null, 2)
            }]
          };
        }
        if (section === "benchmarks") {
          return {
            content: [{
              type: "text",
              text: JSON.stringify(catalog.benchmarks || {}, null, 2)
            }]
          };
        }
        if (catalog[section]) {
          return {
            content: [{
              type: "text",
              text: JSON.stringify({ section, data: catalog[section] }, null, 2)
            }]
          };
        }
      }

      if (scope) {
        const scopeKey = scope.startsWith("scope_") ? scope : `scope_${scope}`;
        if (catalog.data_scopes[scopeKey]) {
          return {
            content: [{
              type: "text",
              text: JSON.stringify({ scope: scopeKey, details: catalog.data_scopes[scopeKey] }, null, 2)
            }]
          };
        }
      }

      if (channel) {
        const normalizedChannel =
          channel === "google_analytics_4"
            ? "google_analytics"
            : channel === "meta_organic"
            ? "facebook_organic"
            : channel;
        const channelCapabilities = catalog.supported_channels[normalizedChannel] || catalog.supported_channels[channel];
        const datascopes = catalog.channel_datascopes[normalizedChannel] || null;
        const metricsCatalog = catalog.channel_metrics_catalog[normalizedChannel] || null;
        const catalogVersion = catalog.catalog_versions[normalizedChannel] || null;
        return {
          content: [{
            type: "text",
            text: JSON.stringify({
              channel: normalizedChannel,
              catalog_version: catalogVersion,
              metrics_catalog: metricsCatalog,
              capabilities: channelCapabilities || "Channel not specifically registered or uses generic adapter",
              datascopes: datascopes || "Channel uses unified un-scoped metrics",
              available_canonical_metrics: catalog.canonical_metrics,
              allowed_breakdowns: catalog.granularities,
              recipes_and_guidance: catalog.recipes_and_guidance
            }, null, 2)
          }]
        };
      }

      return {
        content: [{
          type: "text",
          text: JSON.stringify(catalog, null, 2)
        }]
      };
    }

    if (name === "list_custom_kpis") {
      try {
        const filePath = path.join(APIS_HUB_ROOT, "config", "project_context.json");
        if (fs.existsSync(filePath)) {
          const raw = fs.readFileSync(filePath, "utf-8");
          const ctx = JSON.parse(raw);
          const kpis = ctx.custom_kpis || [];
          return {
            content: [
              {
                type: "text",
                text: JSON.stringify(
                  {
                    status: "success",
                    count: kpis.length,
                    custom_kpis: kpis,
                  },
                  null,
                  2
                ),
              },
            ],
          };
        }
        return {
          content: [
            {
              type: "text",
              text: JSON.stringify({
                status: "success",
                count: 0,
                custom_kpis: [],
                message: "No custom KPIs configured or project context not yet synchronized.",
              }, null, 2),
            },
          ],
        };
      } catch (error) {
        return {
          content: [{ type: "text", text: `Failed to load custom KPIs: ${error.message}` }],
          isError: true,
        };
      }
    }

    if (name === "list_project_dashboards") {
      const { dashboard_id } = args;
      try {
        const filePath = path.join(APIS_HUB_ROOT, "config", "project_context.json");
        if (fs.existsSync(filePath)) {
          const raw = fs.readFileSync(filePath, "utf-8");
          const ctx = JSON.parse(raw);
          const dashboards = ctx.dashboards || [];

          if (dashboard_id) {
            const found = dashboards.find((d) => d.id === Number(dashboard_id));
            if (found) {
              return {
                content: [{ type: "text", text: JSON.stringify(found, null, 2) }],
              };
            }
            return {
              content: [
                {
                  type: "text",
                  text: JSON.stringify({ error: `Dashboard with ID ${dashboard_id} not found.` }, null, 2),
                },
              ],
              isError: true,
            };
          }

          // Return high-level dashboard summaries with widget counts and metadata
          return {
            content: [
              {
                type: "text",
                text: JSON.stringify(
                  {
                    status: "success",
                    count: dashboards.length,
                    dashboards: dashboards.map((d) => ({
                      id: d.id,
                      name: d.name,
                      description: d.description,
                      is_default: d.is_default,
                      widgets_count: d.widgets_count,
                      widgets: d.widgets.map((w) => ({
                        id: w.id,
                        title: w.title,
                        name: w.name,
                        widget_type: w.widget_type,
                        source_type: w.source_type,
                        source_config: w.source_config,
                      })),
                    })),
                  },
                  null,
                  2
                ),
              },
            ],
          };
        }
        return {
          content: [
            {
              type: "text",
              text: JSON.stringify({
                status: "success",
                count: 0,
                dashboards: [],
                message: "No dashboards configured or project context not yet synchronized.",
              }, null, 2),
            },
          ],
        };
      } catch (error) {
        return {
          content: [{ type: "text", text: `Failed to load dashboards: ${error.message}` }],
          isError: true,
        };
      }
    }

    if (name === "get_dashboard_authoring_context") {
      try {
        const data = await callFacadeInternal("/api/internal/v1/tenant-dashboards/context", "GET");
        return {
          content: [{ type: "text", text: JSON.stringify(data, null, 2) }],
        };
      } catch (error) {
        return {
          content: [
            {
              type: "text",
              text: JSON.stringify(
                {
                  status: "error",
                  message: `Failed to fetch dashboard authoring context from Facade: ${error.message}`,
                  details: error.data || null,
                },
                null,
                2
              ),
            },
          ],
          isError: true,
        };
      }
    }

    if (name === "read_dashboard_spec") {
      const { dashboard_id } = args;
      try {
        const data = await callFacadeInternal(`/api/internal/v1/tenant-dashboards/spec/${dashboard_id}`, "GET");
        return {
          content: [{ type: "text", text: JSON.stringify(data, null, 2) }],
        };
      } catch (error) {
        return {
          content: [
            {
              type: "text",
              text: JSON.stringify(
                {
                  status: "error",
                  message: `Failed to read dashboard spec for dashboard ID ${dashboard_id}: ${error.message}`,
                  details: error.data || null,
                },
                null,
                2
              ),
            },
          ],
          isError: true,
        };
      }
    }

    if (name === "create_dashboard_draft") {
      try {
        const data = await callFacadeInternal("/api/internal/v1/tenant-dashboards/drafts", "POST", args);
        return {
          content: [{ type: "text", text: JSON.stringify(data, null, 2) }],
        };
      } catch (error) {
        return {
          content: [
            {
              type: "text",
              text: JSON.stringify(
                {
                  status: "error",
                  message: `Failed to create dashboard draft: ${error.message}`,
                  details: error.data || null,
                },
                null,
                2
              ),
            },
          ],
          isError: true,
        };
      }
    }

    if (name === "update_dashboard_draft") {
      const { draft_token, ...payload } = args;
      try {
        const data = await callFacadeInternal(`/api/internal/v1/tenant-dashboards/drafts/${draft_token}`, "PUT", payload);
        return {
          content: [{ type: "text", text: JSON.stringify(data, null, 2) }],
        };
      } catch (error) {
        return {
          content: [
            {
              type: "text",
              text: JSON.stringify(
                {
                  status: "error",
                  message: `Failed to update dashboard draft ${draft_token}: ${error.message}`,
                  details: error.data || null,
                },
                null,
                2
              ),
            },
          ],
          isError: true,
        };
      }
    }

    if (name === "commit_dashboard_draft") {
      const { draft_token } = args;
      try {
        const data = await callFacadeInternal(`/api/internal/v1/tenant-dashboards/drafts/${draft_token}/commit`, "POST");
        return {
          content: [{ type: "text", text: JSON.stringify(data, null, 2) }],
        };
      } catch (error) {
        return {
          content: [
            {
              type: "text",
              text: JSON.stringify(
                {
                  status: "error",
                  message: `Failed to commit dashboard draft ${draft_token}: ${error.message}`,
                  details: error.data || null,
                },
                null,
                2
              ),
            },
          ],
          isError: true,
        };
      }
    }

    if (name === "discard_dashboard_draft") {
      const { draft_token } = args;
      try {
        const data = await callFacadeInternal(`/api/internal/v1/tenant-dashboards/drafts/${draft_token}`, "DELETE");
        return {
          content: [{ type: "text", text: JSON.stringify(data, null, 2) }],
        };
      } catch (error) {
        return {
          content: [
            {
              type: "text",
              text: JSON.stringify(
                {
                  status: "error",
                  message: `Failed to discard dashboard draft ${draft_token}: ${error.message}`,
                  details: error.data || null,
                },
                null,
                2
              ),
            },
          ],
          isError: true,
        };
      }
    }

    if (name === "list_configured_alerts") {
      const { alert_id } = args;
      try {
        let alerts = [];
        // First check project_context.json, then fallback to alerts.json
        const contextPath = path.join(APIS_HUB_ROOT, "config", "project_context.json");
        const alertsPath = path.join(APIS_HUB_ROOT, "config", "alerts.json");

        if (fs.existsSync(contextPath)) {
          const raw = fs.readFileSync(contextPath, "utf-8");
          const ctx = JSON.parse(raw);
          alerts = ctx.alerts || [];
        } else if (fs.existsSync(alertsPath)) {
          const raw = fs.readFileSync(alertsPath, "utf-8");
          alerts = JSON.parse(raw) || [];
        }

        if (alert_id) {
          const found = alerts.find((a) => a.id === Number(alert_id));
          if (found) {
            return {
              content: [{ type: "text", text: JSON.stringify(found, null, 2) }],
            };
          }
          return {
            content: [
              {
                type: "text",
                text: JSON.stringify({ error: `Alert with ID ${alert_id} not found.` }, null, 2),
              },
            ],
            isError: true,
          };
        }

        return {
          content: [
            {
              type: "text",
              text: JSON.stringify(
                {
                  status: "success",
                  count: alerts.length,
                  alerts,
                },
                null,
                2
              ),
            },
          ],
        };
      } catch (error) {
        return {
          content: [{ type: "text", text: `Failed to load alerts: ${error.message}` }],
          isError: true,
        };
      }
    }

    if (name === "summarize_performance") {
      const {
        entity,
        channel,
        scope,
        aggregations,
        groupBy,
        startDate,
        endDate,
        filters,
      } = args;

      // Enforce user-scoped asset isolation if caller is restricted
      let effectiveFilters = filters && typeof filters === "object" ? { ...filters } : {};
      if (userContext && userContext.allowedAssets && Object.keys(userContext.allowedAssets).length > 0) {
        if (channel && userContext.allowedAssets[channel]) {
          const allowedChannelAssets = userContext.allowedAssets[channel];
          // Restrict ad_account / site / instance to only allowed IDs
          if (Array.isArray(allowedChannelAssets) && allowedChannelAssets.length > 0) {
            const targetId = allowedChannelAssets.length === 1 ? allowedChannelAssets[0] : allowedChannelAssets;
            effectiveFilters["channeledAccount"] = targetId;
            effectiveFilters["account_id"] = targetId;
          }
        }
      }

      // Safeguard: GSC stores impressions separated by searchAppearance.
      // If the caller does not group by dimensions.searchAppearance and did not explicitly specify a searchAppearance filter,
      // default to 'standard' to prevent duplicate row counting and skewed metrics.
      if (channel === "google_search_console") {
        const isGroupingByAppearance = typeof groupBy === "string" && (
          groupBy.includes("searchAppearance") || groupBy.includes("search_appearance")
        );
        if (!isGroupingByAppearance && !effectiveFilters["dimensions.searchAppearance"]) {
          effectiveFilters["dimensions.searchAppearance"] = "standard";
        }
      }

      const aggregationsStr =
        typeof aggregations === "object"
          ? JSON.stringify(aggregations)
          : aggregations;
      const filtersStr =
        Object.keys(effectiveFilters).length > 0
          ? JSON.stringify(effectiveFilters)
          : "";

      // Deterministic Cache Key to avoid redundant CLI & DB invocations by agents
      const cacheKey = JSON.stringify({ entity, channel, aggregationsStr, groupBy, startDate, endDate, filtersStr, userId: userContext?.userId });
      const cached = getCachedAggregation(cacheKey);
      if (cached) {
        return { content: [{ type: "text", text: cached }] };
      }

      let cmd = `php bin/cli.php app:aggregate --entity="${entity}" --aggregations='${aggregationsStr}' --pretty`;
      if (channel) cmd += ` --channel="${channel}"`;
      if (groupBy) cmd += ` --group-by="${groupBy}"`;
      if (startDate) cmd += ` --start-date="${startDate}"`;
      if (endDate) cmd += ` --end-date="${endDate}"`;
      if (filtersStr) cmd += ` --filters='${filtersStr}'`;

      try {
        const stdout = await runCliCommand(cmd);
        setCachedAggregation(cacheKey, stdout);
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        return {
          content: [
            { type: "text", text: `Aggregation failed: ${error.message}` },
          ],
          isError: true,
        };
      }
    }

    if (name === "aggregate_entities") {
      const {
        entity_type,
        measures,
        dimensions = [],
        time_bucket = null,
        channel = null,
        account_ids = null,
        deduplicate_canonical,
        filters = {},
        limit = 100,
      } = args;

      if (!entity_type || !Array.isArray(measures) || measures.length === 0) {
        return {
          content: [
            {
              type: "text",
              text: "Error: 'entity_type' and a non-empty 'measures' array are required for aggregate_entities.",
            },
          ],
          isError: true,
        };
      }

      const payload = {
        entity_type,
        measures,
        dimensions: Array.isArray(dimensions) ? dimensions : [],
        time_bucket: time_bucket || null,
        filters: filters && typeof filters === "object" ? { ...filters } : {},
        deduplicate_canonical:
          deduplicate_canonical !== undefined
            ? Boolean(deduplicate_canonical)
            : !channel && !(Array.isArray(dimensions) && dimensions.includes("channel")),
        limit: Number(limit) || 100,
      };

      if (channel) {
        payload.channel = channel;
        if (!payload.filters.channel) {
          payload.filters.channel = channel;
        }
      }
      if (Array.isArray(account_ids) && account_ids.length > 0) {
        payload.account_ids = account_ids;
      }

      // Enforce user-scoped asset isolation if caller is restricted
      if (userContext && userContext.allowedAssets && Object.keys(userContext.allowedAssets).length > 0) {
        const scopedChannels = {};
        for (const [ch, ids] of Object.entries(userContext.allowedAssets)) {
          if (Array.isArray(ids) && ids.length > 0) {
            if (!channel || channel === ch) {
              scopedChannels[ch] = { account_ids: ids };
            }
          }
        }
        payload.authorized_scope = { channels: scopedChannels };
      }

      const payloadJson = JSON.stringify(payload);
      const cacheKey = JSON.stringify({ tool: "aggregate_entities", payloadJson, userId: userContext?.userId });
      const cached = getCachedAggregation(cacheKey);
      if (cached) {
        return { content: [{ type: "text", text: cached }] };
      }

      const escapedPayload = payloadJson.replace(/'/g, "'\\''");
      const cmd = `php bin/cli.php app:aggregate-catalog --payload='${escapedPayload}' --pretty`;

      try {
        const stdout = await runCliCommand(cmd);
        setCachedAggregation(cacheKey, stdout);
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        return {
          content: [
            { type: "text", text: `Entity aggregation failed: ${error.message}` },
          ],
          isError: true,
        };
      }
    }

    if (name === "get_available_instances") {
      try {
        const filePath = path.join(APIS_HUB_ROOT, "config", "instances.yaml");
        if (fs.existsSync(filePath)) {
          const content = fs.readFileSync(filePath, "utf-8");
          return {
            content: [
              {
                type: "text",
                text: `Configured Instances (config/instances.yaml):\n\n${content}`,
              },
            ],
          };
        }
        return {
          content: [{ type: "text", text: "No instances found in config/instances.yaml." }],
          isError: true,
        };
      } catch (error) {
        return {
          content: [
            {
              type: "text",
              text: `Failed to load instances: ${error.message}`,
            },
          ],
          isError: true,
        };
      }
    }

    throw new Error(`Tool not found: ${name}`);
  }

  server.setRequestHandler(ListToolsRequestSchema, async () => {
    return { tools: activeTools };
  });

  server.setRequestHandler(CallToolRequestSchema, async (request) => {
    const { name, arguments: args } = request.params;
    const isAllowed = activeTools.some((t) => t.name === name);
    if (!isAllowed) {
      throw new Error(`Unauthorized tool call: Administrative privileges required for tool '${name}'`);
    }
    return await executeToolCall(name, args);
  });

  server.MCP_TOOLS = activeTools;
  server.executeToolCall = async (name, args) => {
    const isAllowed = activeTools.some((t) => t.name === name);
    if (!isAllowed) {
      throw new Error(`Unauthorized tool call: Administrative privileges required for tool '${name}'`);
    }
    return await executeToolCall(name, args);
  };

  return server;
}

/**
 * Server Startup Logic
 */
const MODE = process.env.MCP_MODE || "stdio";

if (MODE === "sse") {
  const app = express();
  app.use(cors());
  app.set("trust proxy", true); // Permitir detección correcta tras proxies/docker
  const PORT = process.env.MCP_PORT || 3000;

  // Track active sessions and their transports
  const sessions = new Map();

  app.get("/", (req, res) => {
    res.send("APIs Hub MCP Server (SSE Mode) is running. Connect to /mcp/sse");
  });

  // Removed express.json to parse manually using pure streams

  // Middleware de logging total para debuggear peticiones
  app.use((req, res, next) => {
    const headersJson = JSON.stringify(req.headers);
    const logStr = `[${new Date().toISOString()}] ${req.method} ${req.url} | HEADERS: ${headersJson}\n`;
    fs.appendFileSync(path.join(APIS_HUB_ROOT, "mcp-debug.log"), logStr);
    next();
  });

  // MANEJO DE DISCOVERY: Iniciar flujo SSE solo en la ruta específica
  app.get("/mcp/sse", async (req, res) => {
    console.error(`[DISC] Discovery GET detectado en ${req.url}`);

    // Autenticación de la conexión SSE
    const auth = extractAuth(req);
    if (!auth.authenticated) {
      console.error(`[AUTH] Conexión SSE rechazada: Clave no válida o ausente.`);
      return res.status(401).json({
        error: "Unauthorized",
        message: "Valid API key required via Authorization header (Bearer <key>), X-API-Key, or ?key=<key>",
      });
    }

    // Rate Limiting Check
    const rateIdentifier = auth.key || req.ip || "unknown";
    const rateCheck = checkRateLimit(rateIdentifier);
    res.setHeader("X-RateLimit-Limit", MAX_REQUESTS_PER_WINDOW);
    res.setHeader("X-RateLimit-Remaining", rateCheck.remaining);
    res.setHeader("X-RateLimit-Reset", rateCheck.resetInSec);

    if (rateCheck.exceeded) {
      console.error(`[RATE_LIMIT] Conexión SSE rechazada por rate limit: ${rateIdentifier}`);
      return res.status(429).json({
        error: "Too Many Requests",
        message: `Rate limit exceeded (Max ${MAX_REQUESTS_PER_WINDOW} req/min). Retry in ${rateCheck.resetInSec} seconds.`,
      });
    }

    res.setHeader("X-Accel-Buffering", "no");
    res.setHeader("Access-Control-Allow-Origin", "*");
    res.setHeader("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
    res.setHeader("Access-Control-Allow-Headers", "*");

    const protocol = req.headers["x-forwarded-proto"] || req.protocol || "https";
    const host = req.get("host");
    const baseUrl = `${protocol}://${host}`;
    const endpoint = `${baseUrl}/mcp/messages`;

    // Intercept to write absolute URL in endpoint event for Go MCP clients (like Antigravity)
    const originalWrite = res.write.bind(res);
    res.write = (chunk, encoding, callback) => {
      let data = chunk;
      if (typeof chunk === 'string' && chunk.startsWith('event: endpoint\ndata: /')) {
        data = chunk.replace('data: /', `data: ${baseUrl}/`);
      } else if (Buffer.isBuffer(chunk)) {
        const str = chunk.toString();
        if (str.startsWith('event: endpoint\ndata: /')) {
          data = str.replace('data: /', `data: ${baseUrl}/`);
        }
      }
      const result = originalWrite(data, encoding, callback);
      if (typeof res.flush === 'function') {
        res.flush();
      }
      return result;
    };

    const transport = new SSEServerTransport(endpoint, res);
    sessions.set(transport.sessionId, {
      transport,
      role: auth.role,
      key: auth.key,
      userContext: auth.userContext,
    });
    console.error(`[DISC] Sesión iniciada: ${transport.sessionId} con rol: ${auth.role}`);

    const server = createMcpServer(auth.role, auth.userContext);
    await server.connect(transport);

    // Keepalive ping every 15s to prevent proxy/Cloudflare timeout
    const keepAliveTimer = setInterval(() => {
      try {
        if (!res.writableEnded) {
          res.write(": keepalive\n\n");
          if (typeof res.flush === 'function') {
            res.flush();
          }
        }
      } catch (e) {
        clearInterval(keepAliveTimer);
      }
    }, 15000);

    res.on("close", () => {
      clearInterval(keepAliveTimer);
      console.error(`[DISC] Conexión SSE cerrada para sesión ${transport.sessionId}`);
      setTimeout(() => {
        sessions.delete(transport.sessionId);
      }, 300000); // 5 minutos de retención
    });
  });

  async function handleIncomingMessage(req, res) {
    let sessionId =
      req.query.sessionId ||
      req.headers["x-session-id"] ||
      req.headers["sse-session-id"] ||
      (req.body && req.body.sessionId);

    if (Array.isArray(sessionId)) sessionId = sessionId[0];

    // Fallback: usar la última sesión viva si el cliente no la envía en la URL
    if (!sessionId && sessions.size > 0) {
      sessionId = Array.from(sessions.keys())[sessions.size - 1];
    }

    const sessionData = sessionId ? sessions.get(sessionId) : null;
    const transport = sessionData ? sessionData.transport : null;

    // Verificar autenticación: ya sea por encabezados en este POST o por la sesión activa establecida
    const directAuth = extractAuth(req);
    let effectiveRole = null;
    let effectiveKey = null;
    let effectiveUserContext = null;

    if (directAuth.authenticated) {
      effectiveRole = directAuth.role;
      effectiveKey = directAuth.key;
      effectiveUserContext = directAuth.userContext;
    } else if (sessionData && sessionData.role) {
      effectiveRole = sessionData.role;
      effectiveKey = sessionData.key;
      effectiveUserContext = sessionData.userContext;
    } else {
      console.error(`[AUTH] Mensaje POST rechazado: No autenticado`);
      return res.status(401).json({
        jsonrpc: "2.0",
        error: {
          code: -32000,
          message: "Unauthorized: Valid API key required",
        },
      });
    }

    // Rate limiting para llamadas de mensajes
    const rateIdentifier = effectiveKey || req.ip || "unknown";
    const rateCheck = checkRateLimit(rateIdentifier);
    res.setHeader("X-RateLimit-Limit", MAX_REQUESTS_PER_WINDOW);
    res.setHeader("X-RateLimit-Remaining", rateCheck.remaining);
    res.setHeader("X-RateLimit-Reset", rateCheck.resetInSec);

    if (rateCheck.exceeded) {
      console.error(`[RATE_LIMIT] Mensaje rechazado por rate limit: ${rateIdentifier}`);
      return res.status(429).json({
        jsonrpc: "2.0",
        error: {
          code: -32000,
          message: `Rate limit exceeded (Max ${MAX_REQUESTS_PER_WINDOW} req/min). Retry in ${rateCheck.resetInSec} seconds.`,
        },
      });
    }

    const body = req.body;

    // Detect if this is a JSON-RPC request / notification
    if (body && body.jsonrpc === "2.0") {
      const method = body.method;
      const id = body.id;

      // Notifications don't expect a result payload
      if (!id && (method?.startsWith("notifications/") || method === "notifications/initialized")) {
        if (transport) {
          try {
            await transport.handleMessage(body);
          } catch (e) {
            console.error(`Error handling notification via transport: ${e.message}`);
          }
        }
        return res.status(200).json({ jsonrpc: "2.0" });
      }

      // server/discover or discovery requests
      if (method === "server/discover" || method === "discovery") {
        const discoverResult = {
          jsonrpc: "2.0",
          id,
          result: {
            protocolVersion: "2024-11-05",
            serverInfo: {
              name: "apis-hub-mcp",
              version: "1.0.0",
            },
            capabilities: {
              tools: {},
              resources: {},
            },
          },
        };
        if (transport) {
          try {
            transport.send(discoverResult).catch(() => {});
          } catch (e) {}
        }
        return res.status(200).json(discoverResult);
      }

      // Initialize request
      if (method === "initialize") {
        const initResult = {
          jsonrpc: "2.0",
          id,
          result: {
            protocolVersion: body.params?.protocolVersion || "2024-11-05",
            capabilities: {
              tools: {},
              resources: {},
            },
            serverInfo: {
              name: "apis-hub-mcp",
              version: "1.0.0",
            },
          },
        };
        if (transport) {
          try {
            transport.send(initResult).catch(() => {});
          } catch (e) {}
        }
        return res.status(200).json(initResult);
      }

      // Tools List request: filtered by caller's role (admin vs user)
      if (method === "tools/list") {
        const serverInstance = createMcpServer(effectiveRole, effectiveUserContext);
        const listResult = {
          jsonrpc: "2.0",
          id,
          result: {
            tools: serverInstance.MCP_TOOLS || [],
          },
        };
        if (transport) {
          try {
            transport.send(listResult).catch(() => {});
          } catch (e) {}
        }
        return res.status(200).json(listResult);
      }

      // Tool Call request: role authorization enforced
      if (method === "tools/call") {
        const serverInstance = createMcpServer(effectiveRole, effectiveUserContext);
        const toolName = body.params?.name;
        const toolArgs = body.params?.arguments || {};
        try {
          const executionResult = await serverInstance.executeToolCall(toolName, toolArgs);
          const callResponse = {
            jsonrpc: "2.0",
            id,
            result: executionResult,
          };
          if (transport) {
            try {
              transport.send(callResponse).catch(() => {});
            } catch (e) {}
          }
          return res.status(200).json(callResponse);
        } catch (callErr) {
          const errResponse = {
            jsonrpc: "2.0",
            id,
            error: {
              code: -32603,
              message: callErr.message || "Internal error",
            },
          };
          if (transport) {
            try {
              transport.send(errResponse).catch(() => {});
            } catch (e) {}
          }
          return res.status(200).json(errResponse);
        }
      }

      // Resources List request
      if (method === "resources/list") {
        const resList = {
          jsonrpc: "2.0",
          id,
          result: {
            resources: [
              {
                uri: "apis-hub://config/instances",
                name: "Current Instances Configuration",
                mimeType: "text/yaml",
                description: "The current instances.yaml generated from rules",
              },
              {
                uri: "apis-hub://logs/recent",
                name: "Recent Jobs Logs",
                mimeType: "text/plain",
                description: "Recent logs from jobs.log",
              },
            ],
          },
        };
        if (transport) {
          try {
            transport.send(resList).catch(() => {});
          } catch (e) {}
        }
        return res.status(200).json(resList);
      }
    }

    // Standard SSE transport handling if not intercepted
    if (transport) {
      try {
        if (!transport._sseResponse) {
          transport._sseResponse = transport.res;
        }
        await transport.handlePostMessage(req, res, req.body);
        return;
      } catch (err) {
        console.error(`Error en handlePostMessage: ${err.message}`);
      }
    }

    res.status(404).send("Session expired. Please reconnect.");
  }

  const postMiddleware = [
    (req, res, next) => {
      if (req.headers["transfer-encoding"] === "chunked") {
        return res.status(400).send("StreamableHttp not supported");
      }
      res.setHeader("Content-Type", "application/json");

      // Antigravity Go client strictly unmarshals the HTTP response body as a JSON-RPC message.
      // If the SDK's SSEServerTransport calls res.writeHead(202).end('Accepted'),
      // replace 'Accepted' with a valid JSON-RPC body '{"jsonrpc":"2.0"}'.
      const originalEnd = res.end.bind(res);
      res.end = (chunk, encoding, callback) => {
        if (
          chunk === "Accepted" ||
          (Buffer.isBuffer(chunk) && chunk.toString() === "Accepted")
        ) {
          const reqId = req.body?.id;
          const fallbackBody = reqId !== undefined
            ? JSON.stringify({ jsonrpc: "2.0", id: reqId, result: {} })
            : JSON.stringify({ jsonrpc: "2.0" });
          return originalEnd(fallbackBody, "utf-8", callback);
        }
        return originalEnd(chunk, encoding, callback);
      };

      next();
    },
    express.text({ type: "*/*" }),
    async (req, res) => {
      let parsedBody = {};
      if (req.body && typeof req.body === "string") {
        try {
          parsedBody = JSON.parse(req.body);
        } catch (e) {}
      } else if (req.body && typeof req.body === "object") {
        parsedBody = req.body;
      }
      req.body = parsedBody;
      if (
        !req.headers["content-type"] ||
        !req.headers["content-type"].includes("application/json")
      ) {
        req.headers["content-type"] = "application/json";
      }
      await handleIncomingMessage(req, res);
    },
  ];

  app.post("/mcp/messages", ...postMiddleware);
  app.post("/mcp/sse", ...postMiddleware);

  // Wait for PHP server to be ready before starting MCP (useful in Docker)
  const waitForPhp = async () => {
    if (process.env.INSTANCE_NAME) {
      // Simpler check for "inside docker"
      const phpHost = process.env.PHP_HOST || "master";
      console.error(`Waiting for PHP server on ${phpHost}:8080...`);
      await waitPort({ host: phpHost, port: 8080, timeout: 60000 });
    }
  };

  waitForPhp().then(() => {
    app.listen(PORT, "0.0.0.0", () => {
      console.error(
        `APIs Hub MCP Server running on SSE at http://0.0.0.0:${PORT}/mcp/sse`,
      );
    });
  });
} else {
  const server = createMcpServer();
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("APIs Hub MCP Server running on stdio");
}

process.on("uncaughtException", (error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
