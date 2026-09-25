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
  // If running directly inside a PHP-enabled container with php CLI installed
  try {
    const { stdout } = await execAsync(command, {
      cwd: APIS_HUB_ROOT,
    });
    return stdout;
  } catch (directError) {
    // If direct execution fails (e.g. executed from host or from a node-only environment),
    // delegate to the master container via docker compose.
    const dockerPrefix = "docker compose exec -T master";
    const fullCommand = `${dockerPrefix} ${command}`;

    try {
      const { stdout } = await execAsync(fullCommand, {
        cwd: APIS_HUB_ROOT,
      });
      return stdout;
    } catch (innerError) {
      throw new Error(`Command failed: ${directError.message || innerError.message}`);
    }
  }
}

// Load .env relative to project root
dotenv.config({ path: path.join(APIS_HUB_ROOT, ".env") });

function createMcpServer() {
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

  server.setRequestHandler(ReadResourceRequestSchema, async (request) => {
    const uri = request.params.uri;

    if (uri === "apis-hub://config/instances") {
      const filePath = path.join(APIS_HUB_ROOT, "config", "instances.yaml");
      if (!fs.existsSync(filePath)) {
        return {
          contents: [{ uri, mimeType: "text/yaml", text: "File not found." }],
        };
      }
      const content = fs.readFileSync(filePath, "utf-8");
      return {
        contents: [
          {
            uri,
            mimeType: "text/yaml",
            text: content,
          },
        ],
      };
    }

    if (uri === "apis-hub://logs/recent") {
      const logPath = path.join(APIS_HUB_ROOT, "logs", "jobs.log");
      if (!fs.existsSync(logPath)) {
        return {
          contents: [
            { uri, mimeType: "text/plain", text: "Log file not found." },
          ],
        };
      }
      const content = fs.readFileSync(logPath, "utf-8");
      const lines = content.split("\n").slice(-50).join("\n");
      return {
        contents: [
          {
            uri,
            mimeType: "text/plain",
            text: lines,
          },
        ],
      };
    }

    throw new Error(`Resource not found: ${uri}`);
  });

  /**
   * Tools
   */
  server.setRequestHandler(ListToolsRequestSchema, async () => {
    return {
      tools: [
        {
          name: "get_system_health",
          description:
            "Get a comprehensive health check of the APIs Hub infrastructure",
          inputSchema: {
            type: "object",
            properties: {},
          },
        },
        {
          name: "trigger_instance_sync",
          description: "Trigger a manual sync for a specific instance",
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
          name: "check_coverage",
          description:
            "Analyze data gaps for a specific channel (e.g. facebook_marketing, gsc)",
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
          name: "process_jobs",
          description: "Manually trigger the job processing command",
          inputSchema: {
            type: "object",
            properties: {},
          },
        },
        {
          name: "inspect_job_queue",
          description:
            "Get detailed statistics about current jobs (scheduled, failed, completed)",
          inputSchema: {
            type: "object",
            properties: {},
          },
        },
        {
          name: "log_analyzer",
          description:
            "Scan system logs for recent errors or critical failures",
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
        {
          name: "summarize_performance",
          description:
            "Get aggregated performance data using Channeled Metrics and intelligent formulas (spend, clicks, ctr, etc).",
          inputSchema: {
            type: "object",
            properties: {
              entity: {
                type: "string",
                description:
                  "The entity name (use 'channeled_metric' for performance data)",
              },
              channel: {
                type: "string",
                description:
                  "The channel identifier (e.g. 'google_search_console', 'facebook')",
              },
              aggregations: {
                type: "object",
                description:
                  "Object mapping alias to formula. Formulas: 'spend', 'clicks', 'impressions', 'reach', 'results', 'ctr', 'cpc', 'cpm', 'roas', 'cost_per_result', 'result_rate', 'position'. e.g. {\"total_spend\":\"spend\"}",
              },
              filters: {
                type: "object",
                description:
                  'Optional: Object containing filters. e.g. {"dimensions.gender":"male"}',
              },
              groupBy: {
                type: "string",
                description:
                  "Comma separated fields to group by (e.g. 'daily', 'weekly', 'dimensions.gender')",
              },
              startDate: { type: "string", description: "Start date (Y-m-d)" },
              endDate: { type: "string", description: "End date (Y-m-d)" },
            },
            required: ["entity", "aggregations"],
          },
        },
        {
          name: "get_available_instances",
          description:
            "List all configured worker instances from instances.yaml",
          inputSchema: {
            type: "object",
            properties: {},
          },
        },
      ],
    };
  });

  server.setRequestHandler(CallToolRequestSchema, async (request) => {
    const { name, arguments: args } = request.params;

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

    if (name === "summarize_performance") {
      const {
        entity,
        channel,
        aggregations,
        groupBy,
        startDate,
        endDate,
        filters,
      } = args;

      // Ensure aggregations and filters are stringified for the CLI
      const aggregationsStr =
        typeof aggregations === "object"
          ? JSON.stringify(aggregations)
          : aggregations;
      const filtersStr =
        filters && typeof filters === "object"
          ? JSON.stringify(filters)
          : filters;

      let cmd = `php bin/cli.php app:aggregate --entity="${entity}" --aggregations='${aggregationsStr}' --pretty`;
      if (channel) cmd += ` --channel="${channel}"`;
      if (groupBy) cmd += ` --group-by="${groupBy}"`;
      if (startDate) cmd += ` --start-date="${startDate}"`;
      if (endDate) cmd += ` --end-date="${endDate}"`;
      if (filtersStr) cmd += ` --filters='${filtersStr}'`;

      try {
        const stdout = await runCliCommand(cmd);
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
        // Fallback if --list is not available or fails
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
  });

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
    sessions.set(transport.sessionId, transport);
    console.error(`[DISC] Sesión iniciada: ${transport.sessionId}`);

    const server = createMcpServer();
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

    const transport = sessionId ? sessions.get(sessionId) : null;
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

    // STATELESS JSON-RPC FALLBACK (Para clientes stateless como 2026-07-28 spec o cuando se pierde sesión)
    if (req.body && req.body.jsonrpc === "2.0") {
      try {
        const method = req.body.method;
        const id = req.body.id;

        if (method === "initialize") {
          return res.json({
            jsonrpc: "2.0",
            id,
            result: {
              protocolVersion: req.body.params?.protocolVersion || "2024-11-05",
              capabilities: { tools: {}, resources: {} },
              serverInfo: { name: "apis-hub-mcp", version: "1.0.0" }
            }
          });
        }

        if (method === "notifications/initialized") {
          return res.status(202).send("Accepted");
        }

        if (method === "tools/list") {
          const server = createMcpServer();
          // Obtener lista de herramientas directamente
          const tools = await server.getTools?.() || [];
          return res.json({
            jsonrpc: "2.0",
            id,
            result: { tools }
          });
        }
      } catch (statelessErr) {
        console.error("Error en stateless fallback:", statelessErr);
      }
    }

    res.status(404).send("Session expired. Please reconnect.");
  }

  const postMiddleware = [
    (req, res, next) => {
      if (req.headers['transfer-encoding'] === 'chunked') {
        return res.status(400).send("StreamableHttp not supported");
      }
      // Antigravity Go client fails if 202 Accepted response has an empty Content-Type
      const originalWriteHead = res.writeHead.bind(res);
      res.writeHead = (statusCode, statusMessage, headers) => {
        res.setHeader('Content-Type', 'text/plain; charset=utf-8');
        return originalWriteHead(statusCode, statusMessage, headers);
      };
      next();
    },
    express.text({ type: '*/*' }),
    async (req, res) => {
      let parsedBody = {};
      if (req.body && typeof req.body === 'string') {
        try {
          parsedBody = JSON.parse(req.body);
        } catch (e) {}
      } else if (req.body && typeof req.body === 'object') {
        parsedBody = req.body;
      }
      req.body = parsedBody;
      if (!req.headers['content-type'] || !req.headers['content-type'].includes('application/json')) {
        req.headers['content-type'] = 'application/json';
      }
      await handleIncomingMessage(req, res);
    }
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
