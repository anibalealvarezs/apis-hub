# Plan de Implementación: Paridad de Agregaciones MCP (Widgets & OpenAPI) y Claves API de Usuario con Restricción de Activos

## Contexto y Motivación
1. **Capacidades Analíticas MCP**: Actualmente el MCP ofrece una herramienta básica (`summarize_performance`) que realiza consultas agregadas simples. Para que los agentes autónomos puedan construir respuestas analíticas de igual nivel que los widgets de la plataforma, el MCP y la API deben alinearse plenamente con el catálogo de métricas de OpenAPI, definiendo con exactitud los **data scopes** (`scope_global`, `scope_channel`, `scope_asset`), granularidades, métricas base, métricas derivadas y KPIs para evitar alucinaciones y cálculos erróneos.
2. **Segregación de Claves API por Usuario**:
   - **Owners y Editores**: Tienen acceso directo y control de la clave API principal del Tenant.
   - **Viewers / Colaboradores**: No deben ver ni usar la clave maestra. En su lugar, disponen de **User-Scoped API Keys**.
   - **Restricción de Acceso**: La clave de usuario acota las consultas del MCP y la API exclusivamente a los **Asset Groups** asignados a dicho colaborador en el proyecto.
   - **Gobernanza y Rotación Forzada**: Owners y Editores pueden rotar forzosamente la clave de cualquier colaborador, enviando notificaciones inmediatas por correo electrónico e in-app (`database`) al usuario afectado.

---

## 1. Alineación Exhaustiva del Catálogo Analítico (MCP ↔ API OpenAPI ↔ Widgets)

### 1.1. Taxonomía de Scopes y Métricas
Alinear la especificación OpenAPI (`openapi.json` / schemas) y las herramientas del MCP con las dimensiones y scopes ya establecidos en `ChannelCapabilityRegistry` y `PredefinedKpiRegistry`:

| Scope | Definición | Canales / Entidades Válidas | Ejemplo de Métricas & KPIs |
| :--- | :--- | :--- | :--- |
| **`scope_global`** | Métricas agregadas transversales (blended across all channels). | Todos los canales configurados | `blended_roas`, `blended_cpa`, `true_blended_marginal_cost`, `total_spend`, `total_clicks` |
| **`scope_channel`** | Métricas específicas a la naturaleza de un canal individual. | `meta`, `google`, `klaviyo`, `shopify`, `tiktok`, `amazon` | `spend_elasticity`, `ctr`, `cpc`, `cpm`, `bounce_rate`, `frequency` |
| **`scope_asset`** | Métricas acotadas a activos específicos (ad accounts, properties, stores, campaigns). | Sub-canales (`facebook_marketing`, `google_search_console`, etc.) | `asset_ctr_anomaly`, `top_queries_impressions`, `organic_vs_paid_clicks` |

### 1.2. Herramientas MCP para Descubrimiento y Ejecución
1. **Nueva Tool de Introspección: `get_analytics_catalog`**:
   - Expone al agente el árbol estructurado de:
     - Canales activos del tenant.
     - Métricas soportadas por canal (`spendable`, `clickable`, `revenue_tracked`, etc.).
     - Lista de KPIs predefinidos con su `scope`, fórmula abstracta, y granularidades compatibles.
     - Dimensiones de desglose válidas (`breakdowns`: `device`, `campaign`, `placement`, `country`, `daily`, `weekly`, `monthly`).
2. **Ampliación de `summarize_performance` (o `query_performance_analytics`)**:
   - Parámetros:
     - `scope`: `'global' | 'channel' | 'asset'` (requerido para validar coherencia).
     - `kpis`: array de claves de KPI predefinidos (ej. `['true_blended_marginal_cost', 'blended_roas']`).
     - `derivedMetrics`: definición ad-hoc de fórmulas de métricas derivadas.
     - `breakdowns`: array de dimensiones de agrupación (temporal y categórica).
     - `filters`: filtros multidimensionales con operadores (`eq`, `in`, `between`).
     - `assetGroupIds`: opcional para Owners/Editores, **forzado e inmutable** para claves de usuario.

---

## 2. Claves API con Ámbito de Usuario (User-Scoped API Keys)

### 2.1. Modelo de Datos y Migración (`apis-hub-facade`)
- Crear tabla `project_user_api_keys`:
  - `id` (bigint)
  - `project_id` (foreign key a `projects`)
  - `user_id` (foreign key a `users`)
  - `key_hash` (string sha256)
  - `key_prefix` (string primeros 8 chars)
  - `remote_key_id` (identificador sincronizado en el nodo del tenant)
  - `last_used_at` (timestamp)
  - `created_by` (foreign key a `users`, para auditar rotación forzada)
  - `timestamps`

### 2.2. Matriz de Autorización y Flujo de UI en Filament

```
[Usuario accede a McpAccessReference / ApiAccessReference]
          │
          ├── ¿Es Owner o Editor?
          │     ├── SÍ ──► Muestra la Clave Maestra del Tenant (APP_API_KEY).
          │     │          Permite rotarla.
          │     │          Muestra tabla "Team User API Keys" con opción de "Force Rotate".
          │     │
          │     └── NO (Viewer / Colaborador)
          │           ├── Oculta Clave Maestra.
          │           └── Muestra únicamente "My Scoped API Key".
          │               Informa los Asset Groups a los que tiene acceso.
          │               Permite al usuario auto-rotar su propia clave.
```

### 2.3. Rotación Forzada por Editor/Owner y Notificaciones
1. **Acción de Filament `forceRotateUserApiKey`**:
   - Solo disponible para usuarios con rol `owner` o `editor` en el proyecto.
   - Genera un nuevo token seguro de 64 caracteres.
   - Despliega la clave y los `allowed_asset_group_ids` al nodo de `apis-hub`.
   - Invalida inmediatamente el token anterior.
2. **Notificación Dual (Email + In-App Database)**:
   - Crear notificación `UserApiKeyRotatedNotification`:
     - **Canales**: `['mail', 'database']`.
     - **Destinatario**: El `User` (viewer) cuya clave ha sido rotada.
     - **Email**: Notifica que su clave de API para el proyecto fue rotada por el administrador/editor `[Actor]`, advierte de la invalidación inmediata de scripts o conexiones MCP activas y provee el enlace directo al panel para consultar la nueva clave.
     - **Database**: Alerta en la campana de notificaciones de Filament con enlace a su vista de credenciales.

---

## 3. Sincronización y Aplicación en el Nodo Tenant (`apis-hub`)

### 3.1. Almacén de Claves de Usuario en el Tenant
- Registrar las claves de usuario en `apis-hub` (ej. tabla `user_api_keys` o mapeo JSON en caché/config):
  ```json
  {
    "api_key_hash": "<hash>",
    "user_id": 42,
    "role": "viewer",
    "allowed_asset_groups": [1, 4, 7]
  }
  ```

### 3.2. Middleware de Autenticación de MCP y API
- Cuando entra una petición con `Authorization: Bearer <key>` o `X-API-Key: <key>`:
  1. Si coincide con `APP_API_KEY`: Contexto Global (sin filtro de asset groups).
  2. Si coincide con una `UserApiKey`:
     - Asigna el contexto de usuario.
     - Recupera los IDs de activos / instancias permitidas asociadas a sus `allowed_asset_groups`.
     - En `get_available_instances`: retorna solo instancias pertenecientes a esos grupos.
     - En `summarize_performance`: inyecta de forma obligatoria la cláusula de filtrado por instancia/cuenta, impidiendo cualquier lectura fuera de su alcance.

---

## 4. Plan de Tareas Paso a Paso

- [ ] **Fase 1: Catálogo OpenAPI & Paridad MCP**
  - [ ] 1.1 Documentar el catálogo exhaustivo de métricas y data-scopes (`global`, `channel`, `asset`) sincronizado entre `openapi.json` y `ChannelCapabilityRegistry`.
  - [ ] 1.2 Implementar la herramienta MCP `get_analytics_catalog` en `apis-hub/mcp-server/index.js`.
  - [ ] 1.3 Ampliar `summarize_performance` para recibir KPIs predefinidos, granularidades y desgloses dimensionales.
- [ ] **Fase 2: Modelo de Claves de Usuario & UI en Fachada**
  - [ ] 2.1 Crear migración y modelo `ProjectUserApiKey` en `apis-hub-facade`.
  - [ ] 2.2 Actualizar `McpAccessReference` y `ApiAccessReference`: mostrar clave maestra a Owners/Editores y clave scoped a Viewers.
  - [ ] 2.3 Implementar tabla y acción de "Force Rotate Key" para que Owners/Editores puedan regenerar claves de viewers.
- [ ] **Fase 3: Notificación de Rotación a Viewers**
  - [ ] 3.1 Crear `UserApiKeyRotatedNotification` (Mailing Blade + Filament Database Notification).
  - [ ] 3.2 Disparar el evento de notificación tanto al rotar forzosamente como al auto-rotar.
- [ ] **Fase 4: Restricción de Alcance en Nodo APIs Hub**
  - [ ] 4.1 Endpoint de sincronización de claves de usuario en `apis-hub`.
  - [ ] 4.2 Middleware de validación y enriquecimiento de contexto en `mcp-server/index.js`.
  - [ ] 4.3 Filtrado estricto de Asset Groups en `summarize_performance`.
- [ ] **Fase 5: Pruebas y Validación**
  - [ ] 5.1 Test unitario y feature de autorización por rol y rotación forzada con notificaciones.
  - [ ] 5.2 Test funcional E2E llamando al MCP con clave de usuario y verificando la restricción de assets.
