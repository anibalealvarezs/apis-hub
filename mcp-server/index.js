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
  // Prefix with docker compose if we are on the host machine
  const dockerPrefix = "docker compose exec -T facebook-marketing-entities-sync";
  const fullCommand = `${dockerPrefix} ${command}`;
  
  try {
    const { stdout } = await execAsync(fullCommand, {
      cwd: APIS_HUB_ROOT,
    });
    return stdout;
  } catch (error) {
    // Fallback to direct execution if docker fails (e.g. not running or already inside container)
    try {
      const { stdout } = await execAsync(command, {
        cwd: APIS_HUB_ROOT,
      });
      return stdout;
    } catch (innerError) {
      throw new Error(`Command failed: ${innerError.message}`);
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
    }
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
        return { contents: [{ uri, mimeType: "text/yaml", text: "File not found." }] };
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
          contents: [{ uri, mimeType: "text/plain", text: "Log file not found." }],
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
          description: "Get a comprehensive health check of the APIs Hub infrastructure",
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
              instance_name: { type: "string", description: "The name of the instance to trigger (e.g. facebook-marketing-recent)" },
            },
            required: ["instance_name"],
          },
        },
        {
          name: "check_coverage",
          description: "Analyze data gaps for a specific channel (e.g. facebook_marketing, gsc)",
          inputSchema: {
            type: "object",
            properties: {
              channel: { type: "string", description: "The channel identifier" },
              days: { type: "number", description: "Optional: Number of days to look back (default 30)", default: 30 }
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
          description: "Get detailed statistics about current jobs (scheduled, failed, completed)",
          inputSchema: {
            type: "object",
            properties: {},
          },
        },
        {
          name: "log_analyzer",
          description: "Scan system logs for recent errors or critical failures",
          inputSchema: {
            type: "object",
            properties: {
              limit: { type: "number", description: "Max errors to show per log file", default: 5 },
              hours: { type: "number", description: "Look back timeframe in hours", default: 24 }
            },
          },
        },
        {
          name: "summarize_performance",
          description: "Get aggregated performance data using Channeled Metrics and intelligent formulas (spend, clicks, ctr, etc).",
          inputSchema: {
            type: "object",
            properties: {
              entity: { type: "string", description: "The entity name (use 'channeled_metric' for performance data)" },
              channel: { type: "string", description: "The channel identifier (e.g. 'google_search_console', 'facebook')" },
              aggregations: { 
                type: "object", 
                description: "Object mapping alias to formula. Formulas: 'spend', 'clicks', 'impressions', 'reach', 'results', 'ctr', 'cpc', 'cpm', 'roas', 'cost_per_result', 'result_rate', 'position'. e.g. {\"total_spend\":\"spend\"}" 
              },
              filters: {
                type: "object",
                description: "Optional: Object containing filters. e.g. {\"dimensions.gender\":\"male\"}"
              },
              groupBy: { type: "string", description: "Comma separated fields to group by (e.g. 'daily', 'weekly', 'dimensions.gender')" },
              startDate: { type: "string", description: "Start date (Y-m-d)" },
              endDate: { type: "string", description: "End date (Y-m-d)" }
            },
            required: ["entity", "aggregations"],
          },
        },
        {
          name: "get_available_instances",
          description: "List all configured worker instances from instances.yaml",
          inputSchema: {
            type: "object",
            properties: {},
          },
        }
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
        return { content: [{ type: "text", text: `Health check failed: ${error.message}` }], isError: true };
      }
    }

    if (name === "trigger_instance_sync") {
      const instance = args.instance_name;
      try {
        const stdout = await runCliCommand(`php bin/cli.php app:schedule-initial-jobs --instance="${instance}"`);
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Sync trigger failed: ${error.message}` }], isError: true };
      }
    }

    if (name === "process_jobs") {
      try {
        const stdout = await runCliCommand("php bin/cli.php app:process-jobs");
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Job processing failed: ${error.message}` }], isError: true };
      }
    }

    if (name === "check_coverage") {
      const { channel, days = 30 } = args;
      try {
        const stdout = await runCliCommand(`php bin/cli.php app:check-coverage --channel="${channel}" --days=${days}`);
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Coverage check failed: ${error.message}` }], isError: true };
      }
    }

    if (name === "inspect_job_queue") {
      try {
        const stdout = await runCliCommand("php bin/cli.php app:jobs-stats");
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Job inspection failed: ${error.message}` }], isError: true };
      }
    }

    if (name === "log_analyzer") {
      const { limit = 5, hours = 24 } = args;
      try {
        const stdout = await runCliCommand(`php bin/cli.php app:analyze-errors --limit=${limit} --hours=${hours}`);
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Log analysis failed: ${error.message}` }], isError: true };
      }
    }

    if (name === "summarize_performance") {
      const { entity, channel, aggregations, groupBy, startDate, endDate, filters } = args;
      
      // Ensure aggregations and filters are stringified for the CLI
      const aggregationsStr = typeof aggregations === 'object' ? JSON.stringify(aggregations) : aggregations;
      const filtersStr = filters && typeof filters === 'object' ? JSON.stringify(filters) : filters;
      
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
        return { content: [{ type: "text", text: `Aggregation failed: ${error.message}` }], isError: true };
      }
    }

    if (name === "get_available_instances") {
      try {
        const stdout = await runCliCommand("php bin/cli.php app:refresh-instances --list");
        return { content: [{ type: "text", text: stdout }] };
      } catch (error) {
        // Fallback if --list is not available or fails
        try {
          const filePath = path.join(APIS_HUB_ROOT, "config", "instances.yaml");
          if (fs.existsSync(filePath)) {
            const content = fs.readFileSync(filePath, "utf-8");
            return { content: [{ type: "text", text: `Instances from file:\n${content.substring(0, 5000)}...` }] };
          }
          return { content: [{ type: "text", text: "No instances found." }], isError: true };
        } catch (innerError) {
          return { content: [{ type: "text", text: `Failed to get instances: ${innerError.message}` }], isError: true };
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
  app.set('trust proxy', true); // Permitir detección correcta tras proxies/docker
  app.use(express.json()); // Parser para leer sessionId del cuerpo de la petición
  const PORT = process.env.MCP_PORT || 3000;

  // Track active sessions and their transports
  const sessions = new Map();

  app.get("/", (req, res) => {
    res.send("APIs Hub MCP Server (SSE Mode) is running. Connect to /mcp/sse");
  });

  // Middleware de logging total para debuggear peticiones de Antigravity
  app.use((req, res, next) => {
    console.error(`[MSG-IN] ${req.method} ${req.url} | Session: ${req.query.sessionId || 'N/A'} | Body keys: ${Object.keys(req.body || {})}`);
    next();
  });

  app.all("/mcp/sse", async (req, res) => {
    if (req.method === "POST") {
        return handleIncomingMessage(req, res);
    }

    if (req.method !== "GET") {
        return res.status(405).send("Method Not Allowed. Use GET for SSE connection.");
    }

    console.error(`[SSE] Nueva conexión. Host detectado: ${req.get('host')}`);
    
    res.setHeader('Content-Type', 'text/event-stream');
    res.setHeader('Cache-Control', 'no-cache, no-transform');
    res.setHeader('Connection', 'keep-alive');
    res.setHeader('X-Accel-Buffering', 'no');
    
    // Reconstruir URL absoluta dinámica para máxima compatibilidad con túneles
    const protocol = req.headers['x-forwarded-proto'] || req.protocol;
    const host = req.get('host');
    const endpoint = `${protocol}://${host}/mcp/messages`;
    
    console.error(`[SSE] Endpoint de mensajes: ${endpoint}`);
    const transport = new SSEServerTransport(endpoint, res);
    
    sessions.set(transport.sessionId, transport);
    console.error(`[SSE] Sesión ACTIVADA: ${transport.sessionId}. Total: ${sessions.size}`);

    const server = createMcpServer();
    await server.connect(transport);
    
    res.on("close", () => {
        console.error(`[SSE] Conexión cerrada para sesión: ${transport.sessionId}. (Manteniendo sesión en memoria 2min para mensajes tardíos)`);
        // No borramos inmediatamente para permitir que lleguen mensajes POST que vienen por otra vía
        setTimeout(() => {
            if (sessions.has(transport.sessionId)) {
                sessions.delete(transport.sessionId);
                console.error(`[SSE] Sesión EXPIRADA: ${transport.sessionId}`);
            }
        }, 120000); 
    });
  });

  async function handleIncomingMessage(req, res) {
    // Buscar sessionId en todas partes (Query -> Headers -> Body)
    let sessionId = req.query.sessionId || 
                    req.headers['x-session-id'] || 
                    req.headers['sse-session-id'] ||
                    (req.body && req.body.sessionId);

    if (Array.isArray(sessionId)) sessionId = sessionId[0];

    if (!sessionId) {
        // FALLBACK: Si no hay sessionId pero solo hay 1 sesión activa, usamos esa.
        if (sessions.size === 1) {
            sessionId = Array.from(sessions.keys())[0];
            console.error(`[MSG] Auto-Session Fallback: Usando sesión única activa: ${sessionId}`);
        } else {
            console.error(`[MSG] Error: No se pudo determinar sessionId (Activas: ${sessions.size}).`);
            return res.status(401).send("Session ID required. Please refresh the connection.");
        }
    }

    const transport = sessions.get(sessionId);
    
    if (transport) {
      console.error(`[MSG] OK: Procesando ${req.method} para ${sessionId}.`);
      try {
        await transport.handlePostMessage(req, res);
      } catch (err) {
        console.error(`[MSG] EXCEPTION en handlePostMessage: ${err.message}`);
        res.status(500).send(err.message);
      }
    } else {
      console.error(`[MSG] Error: Sesión ${sessionId} no encontrada o expirada. (Total: ${sessions.size})`);
      res.status(404).send(`Session not found or expired: ${sessionId}. Please reconnect.`);
    }
  }

  app.post("/mcp/messages", async (req, res) => {
    await handleIncomingMessage(req, res);
  });

  // Wait for PHP server to be ready before starting MCP (useful in Docker)
  const waitForPhp = async () => {
    if (process.env.INSTANCE_NAME) { // Simpler check for "inside docker"
        console.error("Waiting for PHP server on port 8080...");
        await waitPort({ host: "127.0.0.1", port: 8080, timeout: 60000 });
    }
  };

  waitForPhp().then(() => {
    app.listen(PORT, "0.0.0.0", () => {
        console.error(`APIs Hub MCP Server running on SSE at http://0.0.0.0:${PORT}/mcp/sse`);
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
