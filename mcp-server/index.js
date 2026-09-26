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
      "Comprehensive interaction guide and usage specifications for AI agents connecting to this APIs Hub MCP node. Explains data discovery, filtering by connected assets, temporal/dimensional breakdowns, data scopes, error prevention, and copy-paste ready query patterns.",
    inputSchema: {
      type: "object",
      properties: {
        topic: {
          type: "string",
          description:
            "Optional: Specific guide topic ('overview', 'workflow', 'assets', 'datascopes', 'scopes', 'filters', 'breakdowns', 'formulas', 'examples')",
          enum: [
            "overview",
            "workflow",
            "assets",
            "datascopes",
            "scopes",
            "filters",
            "breakdowns",
            "formulas",
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
            "Optional: Filter connected assets by channel (e.g. 'google_search_console', 'google_analytics', 'facebook_marketing', 'shopify', 'klaviyo', 'amazon', 'tiktok')"
        }
      }
    }
  },
  {
    name: "get_analytics_catalog",
    description:
      "Introspect and discover the full analytics capabilities of APIs Hub. Returns data scopes (scope_global, scope_channel, scope_asset), supported channels and tags, canonical metrics, formula-based derived metrics, allowed temporal/non-temporal breakdowns, and predefined KPIs.",
    inputSchema: {
      type: "object",
      properties: {
        scope: {
          type: "string",
          description: "Optional: Filter catalog by data scope ('global', 'channel', 'asset')",
          enum: ["global", "channel", "asset"]
        },
        channel: {
          type: "string",
          description: "Optional: Channel identifier to inspect specific capabilities (e.g. 'facebook_marketing', 'google_search_console', 'shopify', 'klaviyo', 'amazon', 'tiktok')"
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
            "The channel identifier (e.g. 'google_search_console', 'facebook', 'facebook_marketing', 'shopify', 'klaviyo', 'amazon', 'tiktok')",
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
            "4. Formula Normalization: Metrics (spend, clicks, impressions, ctr, cpc, cpm, roas, position, sessions) are computed uniformly across Meta, Google, Shopify, Klaviyo, Amazon, and TikTok."
          ],
          available_topics: ["overview", "workflow", "assets", "filters", "scopes", "breakdowns", "formulas", "examples"]
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
              purpose: "Inspect available canonical metrics, derived formulas, and valid dimensional or temporal breakdowns for the target channel or scope."
            },
            {
              step: 3,
              tool: "list_custom_kpis",
              purpose: "Check if the project has user-defined custom KPIs or formula AST expressions configured."
            },
            {
              step: 4,
              tool: "list_project_dashboards",
              purpose: "Inspect active dashboards, widget definitions, and chart controls configured by the user."
            },
            {
              step: 5,
              tool: "list_configured_alerts",
              purpose: "Review active monitoring threshold alerts, scheduled evaluations, and anomaly limits."
            },
            {
              step: 6,
              tool: "summarize_performance",
              purpose: "Run targeted aggregation queries passing discovered asset IDs into 'filters: { channeledAccount: \"<id>\" }' or 'groupBy'."
            }
          ]
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
          title: "Channel-Specific Datascopes & Dimension Orthogonality",
          critical_rule: "Each marketing and analytics channel in APIs Hub possesses exclusive, non-overlapping datascopes. Mixing metrics or dimensions across different scopes within the same channel produces ambiguous cross-joins or duplicate row counting.",
          channels: {
            google_search_console: {
              scopes: {
                "non-searchAppearance (default / standard)": {
                  description: "Standard web search performance metrics. Safe to cross with query, dimensions.page, country, device.",
                  enforced_filter: '{ "dimensions.searchAppearance": "standard" }',
                  explanation: "Google Search Console stores impressions separated by searchAppearance. If you do not filter to 'standard' (or group by dimensions.searchAppearance), metrics will double count because multiple feature rows exist for identical queries."
                },
                "searchAppearance": {
                  description: "Performance by Google Search feature (AMP, Good Page Experience, Merchant Listings, Review Snippets, Video).",
                  required_group_or_filter: 'groupBy: "dimensions.searchAppearance" or filters: { "dimensions.searchAppearance": { "operator": "not_equal", "value": "standard" } }'
                }
              }
            },
            google_analytics_ga4: {
              scopes: {
                "traffic_matrix (session scope)": {
                  description: "Traffic, visits, and engagement acquired in each session.",
                  metrics: ["sessions", "bounce_rate", "average_session_duration", "engaged_sessions"],
                  dimensions: ["dimensions.sessionDefaultChannelGroup", "dimensions.sessionSourceMedium", "dimensions.landing_page", "device", "country"]
                },
                "acquisition_matrix (first user scope)": {
                  description: "First touchpoint / user acquisition attribution.",
                  metrics: ["new_users", "total_users"],
                  dimensions: ["dimensions.firstUserDefaultChannelGroup", "dimensions.firstUserSourceMedium"]
                },
                "event_matrix": {
                  description: "Granular interaction event counting.",
                  metrics: ["event_count", "conversions"],
                  dimensions: ["event"]
                },
                "ad_touchpoint_matrix": {
                  description: "Paid advertising attribution linking campaign, ad group, and ad.",
                  dimensions: ["channeledCampaign", "channeledAdGroup", "channeledAd"]
                }
              }
            },
            facebook_marketing: {
              hierarchy_rule: "Facebook Ads metrics are stored at the finest granularity ('ad' level) and roll up cleanly to ad_set, campaign, and account.",
              dimensions: ["ad", "adGroup", "channeledCampaign", "channeledAccount", "dimensions.age", "dimensions.gender"],
              safeguard: "Never mix age/gender breakdown with ad level attribution unless specifically analyzing demographic reach."
            },
            facebook_organic: {
              scopes: {
                "instagram_account": {
                  description: "Metrics for the IG profile entity.",
                  metrics: ["reach", "views", "follows", "profile_views", "website_clicks", "accounts_engaged", "total_interactions"]
                },
                "ig_post / media": {
                  description: "Metrics scoped strictly to individual media / posts.",
                  metrics: ["reach", "views", "likes", "comments", "shares", "saves", "replies", "profile_visits"],
                  breakdown: "post"
                },
                "facebook_page": {
                  description: "Page-level organic performance.",
                  metrics: ["reach", "page_views_total", "views", "follows", "likes", "total_interactions", "video_views"]
                },
                "fb_post": {
                  description: "Post-level organic performance.",
                  metrics: ["reach", "views", "video_views", "likes", "post_clicks", "total_interactions", "comments", "shares"],
                  breakdown: "post"
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
          title: "Supported Derived Formulas in 'aggregations'",
          formulas: [
            { name: "spend", formula: "SUM(spend)", description: "Total advertising cost" },
            { name: "clicks", formula: "SUM(clicks)", description: "Total link or ad clicks" },
            { name: "impressions", formula: "SUM(impressions)", description: "Total impressions" },
            { name: "ctr", formula: "clicks / impressions * 100", description: "Click-Through Rate (%)" },
            { name: "cpc", formula: "spend / clicks", description: "Cost Per Click" },
            { name: "cpm", formula: "spend / impressions * 1000", description: "Cost Per Mille" },
            { name: "roas", formula: "revenue / spend", description: "Return on Ad Spend" },
            { name: "position", formula: "weighted_avg(position by impressions)", description: "Average Search Engine Rank" },
            { name: "sessions", formula: "SUM(sessions)", description: "Web/Store Traffic Sessions" },
            { name: "conversions", formula: "SUM(conversions)", description: "Total Goal / Purchase Conversions" }
          ]
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
      const { scope, channel } = args;
      const catalog = {
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
          "average_session_duration", "total_users", "total_revenue"
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
          { formula: "position", description: "Weighted Average Ranking Position (weighted by impressions)" }
        ],
        granularities: {
          temporal: ["daily", "weekly", "monthly", "quarterly", "yearly"],
          dimensional: ["device", "country", "query", "page", "campaign", "ad_set", "placement", "dimensions.*"]
        },
        channel_datascopes: {
          google_search_console: {
            scopes: ["non-searchAppearance", "searchAppearance"],
            default_scope: "non-searchAppearance",
            scope_rules: {
              "non-searchAppearance": {
                description: "Standard web search queries, landing pages, devices, and countries. Always requires dimensions.searchAppearance='standard' to avoid duplicate counting across search features.",
                enforced_filter: { "dimensions.searchAppearance": "standard" },
                metrics: ["clicks", "impressions", "ctr", "position"],
                breakdowns: ["query", "dimensions.page", "country", "device"]
              },
              "searchAppearance": {
                description: "Aggregations by Google Search feature (AMP, Good Page Experience, Review Snippets, etc). Cannot be combined with query/page dimensions without causing multi-attribution ambiguity.",
                metrics: ["clicks", "impressions", "ctr", "position"],
                breakdowns: ["dimensions.searchAppearance"]
              }
            }
          },
          google_analytics: {
            scopes: ["traffic_matrix", "acquisition_matrix", "event_matrix", "ad_touchpoint_matrix"],
            default_scope: "traffic_matrix",
            scope_rules: {
              "traffic_matrix": {
                description: "Session-level traffic and landing page metrics.",
                metrics: ["sessions", "bounce_rate", "average_session_duration", "screen_page_views"],
                breakdowns: ["dimensions.sessionDefaultChannelGroup", "dimensions.sessionSourceMedium", "dimensions.landing_page", "device", "country"]
              },
              "acquisition_matrix": {
                description: "First-user attribution and user acquisition.",
                metrics: ["new_users", "total_users"],
                breakdowns: ["dimensions.firstUserDefaultChannelGroup", "dimensions.firstUserSourceMedium"]
              },
              "event_matrix": {
                description: "Event-level interactions and goal conversions.",
                metrics: ["event_count", "conversions"],
                breakdowns: ["event"]
              },
              "ad_touchpoint_matrix": {
                description: "Paid touchpoints linking campaigns, ad groups, and ads.",
                metrics: ["conversions", "total_revenue"],
                breakdowns: ["channeledCampaign", "channeledAdGroup", "channeledAd"]
              }
            }
          },
          facebook_marketing: {
            scopes: ["ad_level", "adset_level", "campaign_level", "account_level"],
            default_scope: "ad_level",
            scope_rules: {
              "ad_level": {
                description: "Finest atomized granularity. All metrics are collected at the ad level and roll up hierarchically.",
                metrics: ["spend", "clicks", "impressions", "reach", "frequency", "ctr", "cpc", "cpm", "roas_purchase"],
                breakdowns: ["ad", "adGroup", "channeledCampaign", "channeledAccount", "dimensions.age", "dimensions.gender"]
              }
            }
          },
          facebook_organic: {
            scopes: ["instagram_account", "ig_post", "facebook_page", "fb_post"],
            default_scope: "instagram_account",
            scope_rules: {
              "instagram_account": {
                description: "Account-level organic reach, views, and profile clicks.",
                metrics: ["reach", "views", "follows", "profile_views", "website_clicks", "accounts_engaged", "total_interactions"],
                breakdowns: ["dimensions.contact_button_type", "dimensions.follow_type"]
              },
              "ig_post": {
                description: "Media/post-level engagement and comments.",
                metrics: ["reach", "views", "likes", "comments", "shares", "saves", "profile_visits"],
                breakdowns: ["post", "dimensions.media_product_type"]
              },
              "facebook_page": {
                description: "Facebook Page aggregate metrics.",
                metrics: ["reach", "page_views_total", "views", "follows", "likes", "total_interactions", "video_views"],
                breakdowns: ["dimensions.reaction_type"]
              },
              "fb_post": {
                description: "Facebook post interactions.",
                metrics: ["reach", "views", "video_views", "likes", "post_clicks", "total_interactions", "comments", "shares"],
                breakdowns: ["post"]
              }
            }
          }
        },
        recipes_and_guidance: {
          guide_tool: "Call 'get_mcp_guide' with topic='datascopes' or topic='examples' for detailed recipes.",
          discovery_tool: "Call 'list_connected_assets' to discover exact asset IDs for filters: { channeledAccount: '<id>' }."
        }
      };

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
        const channelCapabilities = catalog.supported_channels[channel];
        const datascopes = catalog.channel_datascopes[channel] || null;
        return {
          content: [{
            type: "text",
            text: JSON.stringify({
              channel,
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

    if (name === "get_available_instances") {
      try {
        const stdout = await runCliCommand(
          "php bin/cli.php app:refresh-instances --list",
        );
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        try {
          const filePath = path.join(APIS_HUB_ROOT, "config", "instances.yaml");
          if (fs.existsSync(filePath)) {
            const content = fs.readFileSync(filePath, "utf-8");
            return {
              content: [
                {
                  type: "text",
                  text: `Instances from file:\n${content.substring(0, 5000)}...`,
                },
              ],
            };
          }
          return {
            content: [{ type: "text", text: "No instances found." }],
            isError: true,
          };
        } catch (innerError) {
          return {
            content: [
              {
                type: "text",
                text: `Failed to get instances: ${innerError.message}`,
              },
            ],
            isError: true,
          };
        }
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
