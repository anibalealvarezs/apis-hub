# Plan de Implementación: Hardening Empresarial, Auth Middleware y Productización SaaS de APIs Hub MCP

**Estado:** ✅ Completado e Implementado  
**Fecha:** 2026-09-25  
**Autor:** Antigravity AI Pair-Programmer & Lead Architecture  
**Repositorios Impactados:**
- `d:\laragon\www\apis-hub` (Servidor MCP, endpoints de proxy, scripts de deployment)
- `d:\laragon\www\apis-hub-facade` (Panel Filament, vistas públicas, planes, knowledge base)

---

## 1. Resumen Ejecutivo
El servidor MCP (Model Context Protocol) de APIs Hub ya se encuentra funcional, securizado y producto de SaaS maduro y diferenciador para planes altos (**Ultra** y **Enterprise**). Se aislaron las herramientas administrativas de las herramientas de usuario final, se previenen abusos mediante rate limiting en ventana deslizante (60 req/min), se optimizaron las respuestas analíticas con caché en memoria y se completó toda la experiencia de usuario en el panel y la web pública.

---

## 2. Matriz de Requerimientos y Tareas

| # | Módulo / Tarea | Prioridad | Repositorio | Estado | Descripción Detallada |
|---|---|---|---|---|---|
| **1** | **Autenticación MCP (Middleware)** | 🔴 Crítica | `apis-hub` | ✅ Completado | Validar `Authorization: Bearer <key>`, `X-API-Key` y query `?key=<key>` en SSE (`/mcp/sse`) y JSON-RPC (`/mcp/messages`, `/mcp`). Rechaza peticiones sin clave con `401 Unauthorized`. |
| **2** | **Segregación de Roles & Herramientas** | 🔴 Crítica | `apis-hub` | ✅ Completado | `ADMIN_API_KEY` recibe todas las herramientas (incluyendo diagnósticos y CLI). `APP_API_KEY` solo recibe herramientas de analítica (`summarize_performance`, `check_coverage`, `get_available_instances`). Invocaciones de usuario a tools admin son bloqueadas con error JSON-RPC. |
| **3** | **Rate Limiting & Anti-Abuso** | 🟡 Alta | `apis-hub` | ✅ Completado | Ventana deslizante en memoria de 60 req/min por clave o IP con encabezados `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` y respuesta `429 Too Many Requests`. |
| **4** | **Optimización de Respuestas & Caching** | 🟡 Alta | `apis-hub` | ✅ Completado | Caché con TTL de 10 minutos para agregaciones de rendimiento idénticas en `summarize_performance`, reduciendo carga a MySQL y latencia para LLMs. |
| **5** | **Knowledge Base Page del MCP en Panel** | 🟢 Media | `apis-hub-facade` | ✅ Completado | Creada la página `McpAccessReference` y vista Blade en el cluster `KnowledgeBase\Integrations` con snippets listos para Antigravity, Claude Desktop y Cursor, además de prompts de ejemplo. |
| **6** | **Sync Settings: Gating de Tier & Upsell** | 🟢 Media | `apis-hub-facade` | ✅ Completado | Actualizada `SyncSettings` para informar el estado activo en Ultra/Enterprise (con endpoint y enlace a la guía) o mostrar banner de upsell con CTA de suscripción en Free/Pro. |
| **7** | **Páginas Públicas & Comparativa de Planes** | 🟢 Media | `apis-hub-facade` | ✅ Completado | Actualizado `plans.blade.php` en las tarjetas de Ultra y Enterprise, y en la matriz comparativa de la sección "Developer & Programmatic API" con sus traducciones en `lang/es.json`. |

---

## 3. Plan Detallado Paso a Paso

### Fase 1: Middleware de Autenticación y Segregación de Tools (`apis-hub/mcp-server/index.js`)
1. **Extracción y Validación de Credenciales:**
   - Crear helper `extractApiKey(req)` que inspeccione:
     - Header `Authorization` (formato `Bearer <token>`).
     - Header `x-api-key` / `X-API-KEY`.
     - Query string `req.query.key` o `req.query.api_key`.
   - Comparar contra `ADMIN_API_KEY` y `APP_API_KEY` (soporte multi-tenant mediante validación contra variable o API interna si aplica).
2. **Definición de Catálogo de Herramientas por Rol:**
   - **Herramientas Admin (`role: 'admin'`):**
     - `get_system_health`
     - `process_jobs`
     - `trigger_instance_sync`
     - `inspect_job_queue`
     - `log_analyzer`
     - `summarize_performance`
     - `check_coverage`
     - `get_available_instances`
   - **Herramientas Usuario / Tenant (`role: 'user'`):**
     - `summarize_performance` (con filtrado a sus instancias asignadas)
     - `check_coverage`
     - `get_available_instances` (o `get_available_channels`)
     - Nuevas tools de analítica optimizada.
3. **Filtrado en Métodos MCP:**
   - En `tools/list`: Filtrar la lista de tools antes de responder según el rol autenticado.
   - En `tools/call`: Rechazar con error JSON-RPC `-32601` o mensaje de permiso denegado si un usuario intenta invocar una tool administrativa.

### Fase 2: Rate Limiting y Protección contra Agent Swarms
1. Implementar un bucket en memoria o Redis (clave `mcp:ratelimit:<key>:<minute>`).
2. Configurar ventana de 60 peticiones/minuto por API key con respuesta `429 Too Many Requests` (o error JSON-RPC apropiado).
3. Añadir encabezados de rate limit (`X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`).

### Fase 3: Optimizaciones para Agentes AI
1. Incorporar caché en memoria (TTL 10 min para consultas con fechas pasadas/cerradas).
2. Estructurar outputs con resúmenes ejecutivos compactos en JSON o Markdown conciso, reduciendo el token footprint.

### Fase 4: Knowledge Base MCP en el Panel Facade (`apis-hub-facade`)
1. **Clase Filament:**
   - Archivo: `app/Filament/App/Pages/McpAccessReference.php`
   - Cluster: `App\Filament\App\Clusters\KnowledgeBase\Integrations`
   - Icono: `heroicon-o-cpu-chip` o `heroicon-o-sparkles`
   - Título y navegación coherentes con `ApiAccessReference`.
2. **Vista Blade:**
   - Archivo: `resources/views/filament/app/pages/mcp-access-reference.blade.php`
   - Secciones:
     - Qué es el MCP de APIs Hub y casos de uso.
     - URL de conexión del servidor: `https://<tenant>.apis-hub.cloud/mcp/sse?key=<API_KEY>`.
     - Snippets de configuración para **Antigravity IDE**, **Claude Desktop** y **Cursor**.
     - Ejemplos de prompts recomendados para agentes ("Analiza el tráfico orgánico del último trimestre", "Compara conversión entre Google Ads y Meta").
     - Estado de activación según el tier del proyecto actual.

### Fase 5: Sync Settings - Estado y Upsell de MCP (`apis-hub-facade`)
1. En `app/Filament/App/Pages/SyncSettings.php` (y su vista blade correspondiente):
   - Consultar el tier del proyecto activo (`$project->plan` o equivalente: `ultra`, `enterprise` vs `free`, `pro`).
   - Si el tier es **Ultra** o **Enterprise**:
     - Mostrar tarjeta con badge verde "🟢 Servidor MCP Activo".
     - Mostrar endpoint SSE y botón para copiar configuración.
   - Si el tier es **Free** o **Pro**:
     - Mostrar tarjeta con badge gris/ámbar "🔒 Servidor MCP (Exclusivo Ultra & Enterprise)".
     - Explicación de capacidades para agentes autónomos.
     - Botón CTA de Upsell directo a la página de suscripción / cambio de plan.

### Fase 6: Actualización de Features Públicos y Matriz de Precios
1. Editar `resources/views/plans.blade.php`:
   - En la sección comparativa de planes (Features Matrix):
     - Añadir fila: **"Servidor MCP para Agentes AI (Claude, Antigravity, Cursor)"**
     - Free: ❌
     - Pro: ❌
     - Ultra: ✅ (Conexión SSE dedicada)
     - Enterprise: ✅ (Conexión SSE dedicada + herramientas avanzadas personalizadas)
2. Actualizar diccionarios de idiomas en `lang/es.json` y `lang/en.json` con los nuevos textos.

---

## 4. Criterios de Aceptación y Pruebas
1. Petición a `/mcp/sse` sin API Key devuelve `401 Unauthorized`.
2. Petición a `/mcp/sse?key=<APP_API_KEY>` lista únicamente las herramientas de analítica permitidas para clientes.
3. Petición con `ADMIN_API_KEY` lista las 8 herramientas completas.
4. Intentar llamar a `trigger_instance_sync` o `process_jobs` con `APP_API_KEY` devuelve error de autorización.
5. El panel de Filament muestra la página de Knowledge Base MCP accesible en la navegación.
6. En `SyncSettings`, proyectos Free/Pro ven el banner de upsell y proyectos Ultra/Enterprise ven su configuración activa.
7. La página pública de planes refleja con claridad la inclusión del MCP en Ultra y Enterprise.
