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

// Load .env relative to project root
dotenv.config({ path: path.join(APIS_HUB_ROOT, ".env") });

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
        name: "process_jobs",
        description: "Manually trigger the job processing command",
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
      const { stdout } = await execAsync("php bin/cli.php app:health-check", {
        cwd: APIS_HUB_ROOT,
      });
      return {
        content: [{ type: "text", text: stdout }],
      };
    } catch (error) {
      return {
        content: [{ type: "text", text: `Health check failed: ${error.message}` }],
        isError: true,
      };
    }
  }

  if (name === "trigger_instance_sync") {
    const instance = args.instance_name;
    try {
      const { stdout } = await execAsync(`php bin/cli.php app:schedule-initial-jobs --instance="${instance}"`, {
        cwd: APIS_HUB_ROOT,
      });
      return {
        content: [{ type: "text", text: stdout }],
      };
    } catch (error) {
      return {
        content: [{ type: "text", text: `Sync trigger failed: ${error.message}` }],
        isError: true,
      };
    }
  }

  if (name === "process_jobs") {
    try {
      const { stdout } = await execAsync("php bin/cli.php app:process-jobs", {
        cwd: APIS_HUB_ROOT,
      });
      return {
        content: [{ type: "text", text: stdout }],
      };
    } catch (error) {
      return {
        content: [{ type: "text", text: `Job processing failed: ${error.message}` }],
        isError: true,
      };
    }
  }

  throw new Error(`Tool not found: ${name}`);
});

/**
 * Server Startup Logic
 */
const MODE = process.env.MCP_MODE || "stdio";

if (MODE === "sse") {
  const app = express();
  app.use(cors());
  const PORT = process.env.MCP_PORT || 3000;

  let transport = null;

  app.get("/", (req, res) => {
    res.send("APIs Hub MCP Server (SSE Mode) is running. Connect to /mcp/sse");
  });

  app.get("/mcp/sse", async (req, res) => {
    console.error("New SSE connection established");
    transport = new SSEServerTransport("/mcp/messages", res);
    await server.connect(transport);
  });

  app.post("/mcp/messages", async (req, res) => {
    if (transport) {
      await transport.handlePostMessage(req, res);
    } else {
      res.status(400).send("No active SSE transport");
    }
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
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("APIs Hub MCP Server running on stdio");
}

process.on("uncaughtException", (error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
