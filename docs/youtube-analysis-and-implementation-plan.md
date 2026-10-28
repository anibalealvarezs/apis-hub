# YouTube Analytics Integration: Domain Analysis & Master Implementation Plan

Este plan maestro orquesta la integración integral del canal **YouTube Analytics** (`youtube_analytics`) en el ecosistema **APIs Hub SaaS**, rigiéndose por el playbook oficial de implementación de drivers (`D:\laragon\www\_shared\skills\hub-driver-implementation.md`).

---

## 🗺️ Progression Dynamic & State Tracker

| Etapa | Área de Enfoque | Estado | Paquete Objetivo |
| :--- | :--- | :--- | :--- |
| **Fase 0** | Análisis de Dominio y Superficie API | `DOCUMENTED` | `D:\laragon\www\apis-hub\docs\youtube-analysis-and-implementation-plan.md` |
| **Etapa 1** | SDK (`google-api-anibal`): YouTube Analytics & Data API | `PENDING` | `D:\laragon\www\google-api-anibal` |
| **Etapa 2** | Driver (`google-hub-driver`): `YouTubeAnalyticsDriver` | `PENDING` | `D:\laragon\www\google-hub-driver` |
| **Etapa 3** | Motor Orquestador (`apis-hub`): Migraciones, Canales & Seeder | `PENDING` | `D:\laragon\www\apis-hub` |
| **Etapa 4** | Facade UI & Analytics (`apis-hub-facade`): Data Sources, Explorer & KPIs | `PENDING` | `D:\laragon\www\apis-hub-facade` |
| **Etapa 5** | Quality Gates & Verificación Multi-Nivel | `PENDING` | Todo el Ecosistema |

---

# 🔍 FASE 0: Exhaustive Source API & Domain Analysis

### 0.1 Propósito del Proveedor y Propuesta de Valor en Marketing
- **Identidad de Dominio:** YouTube es la plataforma global líder de contenido en video y streaming. Su valor no reside en la atribución transaccional directa simple ni en búsquedas de texto puro, sino en **consumo de contenido (audiencia y retención)**, **engagement orgánico** (likes, comentarios, shares, suscriptores) y **distribución cross-media**.
- **Casos de Uso Principales:**
  1. *Monitoreo de Rendimiento de Contenido:* Identificación de videos top en visualizaciones y tiempo de reproducción (`estimatedMinutesWatched`).
  2. *Retención y Fidelización:* Análisis de duración promedio de vista (`averageViewDuration`) y tasa de finalización por video.
  3. *Crecimiento de Comunidad:* Seguimiento del flujo neto de suscriptores (`subscribersGained` vs. `subscribersLost`).
  4. *Demografía y Distribución Geográfica:* Segmentación por país, edad, género y dispositivo.
- **Relación con Canales Existentes:**
  - Actúa como canal de **Atracción Orgánica y Engagement / Branding** (`reach_driven`, `video_marketing`, `organic_social`).
  - Alimenta la etapa alta del embudo (*top-of-funnel / awareness*), complementando a *Google Search Console* (SEO web), *Google Ads / Meta Ads* (adquisición de pago) y *Shopify / Klaviyo* (conversión y retención).

---

### 0.2 Inventario de Entidades y Superficie de Métricas
- **Topología de Entidades:**
  - **Canal (`ChanneledAccount`):** Representa el canal de YouTube (`channel_id`, p. ej. `UC...`). Es el activo almacenable y seleccionable en APIs Hub (*Tracked Asset*).
  - **Video (`ChanneledAd` / `Post` / Granular Content Entity):** Cada video publicado (`video_id`, título, fecha de publicación, duración). Se sincroniza durante la ingesta de entidades para permitir desglose a nivel video.
  - **Listas de Reproducción (`Playlist`):** Agrupaciones temáticas de videos (opcional para fases avanzadas).
- **Disponibilidad de Series Temporales (Time-Series):**
  - La **YouTube Analytics API v2** entrega métricas discretas **pre-agregadas por día** (`dimensions=day`) o por dimensiones combinadas (`day,video` o `day,country`).
  - **No** requiere agregación de streams atómicos de eventos en el cliente (a diferencia de Mailchimp/Klaviyo). El motor de YouTube ya calcula los rollups diarios.

---

### 0.3 Retos Técnicos de Ingestión y Caché
- **Ventana de Retención Histórica:**
  - La API de YouTube Analytics permite consultar desde la fecha de creación del canal/video.
  - *Retraso de Datos (Data Freshness / Latency):* YouTube Analytics procesa métricas con un desfase de **24 a 48 horas**. Por tanto, la ventana de sincronización "reciente" debe contemplar refrescar los últimos 3 a 7 días para absorber consolidaciones de métricas tardías.
  - *Ventanas de Caché:* Tier Estándar = 1 año; Tier Pro = histórico completo.
- **Estrategia de Paginación y Guardias de Bucle:**
  - Endpoint `reports`: utiliza `startIndex` y `maxResults` (hasta 10,000 filas por llamada).
  - Paginación con guardia estricto: Si `count(rows) == 0` o `count(rows) < maxResults`, terminar. Además, verificar que el cursor avance estrictamente (`$startIndex += count($rows)`).
- **Consumo de Cuotas (Quotas) y Rate Limits:**
  - YouTube Data API v3 tiene un límite estricto de **10,000 unidades/día** por defecto (las llamadas `channels.list` cuestan 1 unidad, `search` cuesta 100 unidades). **Regla de Oro:** Minimizar llamadas a `search` en Data API v3; para listar videos del canal, usar la playlist de uploads del canal (`contentDetails.relatedPlaylists.uploads` -> `playlistItems.list`), cuyo costo es solo 1 unidad.
  - YouTube Analytics API v2 opera bajo cuotas separadas de consultas de reportes (típicamente cientos de queries/minuto sin consumir unidades de Data API v3).
  - Concurrencia de Workers (`getDefaultMaxWorkers()`): **1 a 2 workers** para prevenir errores `429 Too Many Requests` o `quotaExceeded`.

---

### 0.4 Canonicalización de Métricas & Diccionario APIs Hub
Para cumplir con la **Regla de Desambiguación** (estricto mapeo 1:1, nunca arrays multidimensionales en la clave canónica):

| Métrica YouTube Analytics | Clave Canónica APIs Hub | Tipo / Unidad | Descripción en APIs Hub |
| :--- | :--- | :--- | :--- |
| `views` | `views` | Integer | Reproducciones totales del video/canal |
| `estimatedMinutesWatched` | `watch_time_minutes` | Float | Minutos totales de visualización |
| `averageViewDuration` | `avg_view_duration_seconds` | Float | Duración promedio por vista (segundos) |
| `likes` | `likes` | Integer | Interacciones de me gusta |
| `dislikes` | `dislikes` | Integer | Interacciones de no me gusta |
| `comments` | `comments` | Integer | Comentarios generados |
| `shares` | `shares` | Integer | Veces compartido |
| `subscribersGained` | `subscribers_gained` | Integer | Nuevos suscriptores ganados |
| `subscribersLost` | `subscribers_lost` | Integer | Suscriptores perdidos |
| *(Derivado Canónico: `views`)* | `impressions` | Integer | Equivalencia canónica para gráficos multi-canal |
| *(Derivado Canónico: `shares`+`likes`)* | `engagements` | Integer | Interacciones sociales directas |

---

### 0.5 Criterios de Marketing para Breakdowns y Segmentación
- **Jerarquía:** `HierarchyType::ENGAGEMENT` (`channel` $\rightarrow$ `video`).
- **Dimensiones Soportadas:**
  - Temporal: `day`, `month`.
  - Contenido: `video` (ID de video individual).
  - Geográfico: `country`.
  - Dispositivo: `deviceType` (`DESKTOP`, `MOBILE`, `TABLET`, `TV`, `GAME_CONSOLE`).
  - Demográfico: `ageGroup`, `gender`.
  - Fuentes de Tráfico: `insightTrafficSourceType` (Búsqueda de YouTube, Videos sugeridos, Externo, Notificaciones).

---

### 0.6 Expansión Analítica: Métricas Derivadas (DMs), KPIs y Correlaciones

#### A. Métricas Derivadas (DMs - Aritmética Determinista Plana)
1. **Tasa de Engagement de Video (`video_engagement_rate`):**
   $$\text{Engagement Rate} = \frac{\text{likes} + \text{comments} + \text{shares}}{\text{views}}$$
2. **Crecimiento Neto de Suscriptores (`net_subscribers`):**
   $$\text{Net Subscribers} = \text{subscribers\_gained} - \text{subscribers\_lost}$$
3. **Porcentaje de Retención Promedio (`avg_retention_rate`):**
   $$\text{Retention Rate} = \frac{\text{avg\_view\_duration\_seconds}}{\text{video\_duration\_seconds}}$$
4. **Ratio de Conversión de Suscriptores (`subscriber_conversion_rate`):**
   $$\text{Sub Conversion Rate} = \frac{\text{subscribers\_gained}}{\text{views}}$$

#### B. Key Performance Indicators (KPIs - Modelos Estadísticos y Predictivos)
1. **Elasticidad de Publicación vs. Retención (`calculate_elasticity`):**
   - Evalúa cómo el incremento en frecuencia de subida de videos afecta la duración promedio de reproducción.
2. **Detección de Anomalías de Viralidad (`calculate_anomaly`):**
   - Detección de picos inusuales en visualizaciones (`views`) y compartidos (`shares`) en una ventana de 30 días.
3. **Regresión de Crecimiento de Audiencia (`calculate_regression`):**
   - Tendencia predictiva de acumulación de suscriptores frente al volumen de minutos reproducidos.

#### C. Escenarios de Correlación Cruzada (Joint Correlation Playbooks)
1. **YouTube Video Reach to Google Search SEO Lift:**
   - *Hipótesis:* Un incremento en reproducciones de video en YouTube genera un aumento con rezago (*lag* de 2 a 5 días) en impresiones de búsqueda de marca y clics orgánicos en Google Search Console.
2. **Blended Organic Video vs. Paid Social Video Efficiency:**
   - *Hipótesis:* Comparación de costo por visualización de Meta Video Ads frente al tiempo de retención orgánico en YouTube para optimizar la producción de creatividades.

---

# 🛠️ Plan de Implementación en 5 Etapas

## 📌 Etapa 1: Comunicación SDK (`google-api-anibal`)
- [ ] **1.1 Soporte OAuth & Discovery en `GoogleApi`:**
  - Configurar scopes de YouTube en `Helpers::parseScopes`:
    - `https://www.googleapis.com/auth/yt-analytics.readonly`
    - `https://www.googleapis.com/auth/youtube.readonly` (para listar metadatos de canales y videos vía Data API v3).
- [ ] **1.2 Crear / Completar `YouTubeDataApi`:**
  - Implementar método para obtener canales asociados al usuario autenticado (`channels.list?mine=true`).
  - Implementar método eficiente para listar videos del canal vía `playlistItems.list` de la lista `uploads`.
- [ ] **1.3 Robustecer `YouTubeAnalyticsApi`:**
  - Incorporar soporte para loop guard en paginación (`queryAllAndProcess`).
  - Implementar método de consulta tipado con validación de dimensiones y filtros (`queryDailyMetrics`, `queryVideoMetrics`, `queryGeographicMetrics`).
  - Conectar el manejador con `GoogleErrorClassifier` clasificando errores de cuota (`quotaExceeded`, `rateLimitExceeded`) y fallos de permisos (`insufficientPermissions`, `forbidden`).
- [ ] **1.4 Tests de Calidad SDK:**
  - Tests unitarios de parsing de respuestas y validación de dimensiones/métricas.
  - Tests de clasificación de errores específicos de YouTube.

---

## 📌 Etapa 2: Driver de Normalización (`google-hub-driver`)
- [ ] **2.1 Registrar Canal en Enums:**
  - Añadir `case YOUTUBE = 'youtube_analytics';` en `src/Enums/GoogleChannel.php`.
- [ ] **2.2 Crear `YouTubeAnalyticsDriver`:**
  - Ubicación: `src/Drivers/YouTubeAnalyticsDriver.php`.
  - Implementar interfaces: `SyncDriverInterface`, `PageableInterface`, `ChanneledAccountableInterface`, `MetricProfileProviderInterface`, `AggregationProfileProviderInterface`, `CanonicalMetricDictionaryProviderInterface`.
  - Implementar `getInstanceRules()`:
    ```php
    public static function getInstanceRules(): array
    {
        return [
            'entities_sync' => true,
            'metrics_sync' => true,
        ];
    }
    ```
  - Implementar `fetchAvailableAssets()`:
    - Retornar los canales del usuario autenticado bajo el grupo `channels` con `id`, `name`, `thumbnail_url`.
  - Implementar `updateConfiguration()` compatible con configuraciones anidadas y planas.
  - Implementar `getCanonicalMetricDictionary()` con el mapeo 1:1 establecido en la Fase 0.
- [ ] **2.3 Conversiones Canónicas (`YouTubeMetricConvert`):**
  - Ubicación: `src/Conversions/YouTubeMetricConvert.php`.
  - Normalizar respuestas matriciales de YouTube Analytics (`columnHeaders` + `rows`) a estructuras asociativas canónicas para el ingestor de APIs Hub.
- [ ] **2.4 Sincronización de Entidades (`syncEntities`):**
  - Mapear canal a `ChanneledAccount`.
  - Mapear videos a `ChanneledAd` / granular con sus metadatos (título, duración, ID externo).
  - Invocar `$this->dataProcessor` pasando el tipo explícito (`'channeled_account'`, `'channeled_ad'`).
- [ ] **2.5 Sincronización de Métricas (`sync`):**
  - Bucle por chunks de fechas respetando `$shouldContinue()`.
  - Extracción de métricas a nivel canal y a nivel video con dimensiones temporales (`date`).
- [ ] **2.6 Tests de Calidad del Driver:**
  - Tests unitarios en `tests/Unit/YouTubeAnalyticsDriverTest.php` y `tests/Unit/YouTubeMetricConvertTest.php`.

---

## 📌 Etapa 3: Motor Orquestador (`apis-hub`)
- [ ] **3.1 Actualizar Dependencia y Mapeo:**
  - Asegurar `google-hub-driver` actualizado en `composer.json`.
  - Registrar driver en `config/drivers.yaml` bajo `youtube_analytics`.
- [ ] **3.2 Migración de Actualización:**
  - Crear rutina `Upgrade_v1_18_0_to_v1_19_0.php`:
    - Insertar registro de canal `youtube_analytics` en la tabla `channels`:
      - `name: 'youtube_analytics'`, `label: 'YouTube Analytics'`, `cooldown: 1800`, `maxworkers: 1`, `tier: 2`, `provider: 'google'`.
    - Crear índices específicos en `channeled_ads` y `channeled_accounts` para búsquedas eficientes por canal `youtube_analytics`.
- [ ] **3.3 Comando Seeder de Métricas Sintéticas:**
  - Crear `SeedYouTubeAnalyticsMetricsCommand.php`:
    - Generación masiva y realista de `views`, `watch_time_minutes`, `likes`, `comments`, `shares`, `subscribers_gained/lost` para pruebas de carga y desarrollo sin conexión en vivo.

---

## 📌 Etapa 4: Facade UI, Data Sources & Analytics (`apis-hub-facade`)
- [ ] **4.1 Registro de Canal y Capacidades:**
  - Registrar canal `youtube_analytics` en `ChannelCapabilityRegistry::getTags()`:
    - Tags: `['reach_driven', 'organic_social', 'video_marketing', 'impressionable', 'behavior_tracked']`.
  - Registrar en `ChannelBreakdownRegistry.php` y `ChannelGranularityRegistry.php` (soportando múltiples canales si aplica).
  - Registrar métricas derivadas en `PredefinedDerivedMetricRegistry.php` (`video_engagement_rate`, `net_subscribers`, etc.).
  - Registrar correlaciones en `JointCorrelationPlaybookRegistry.php`.
- [ ] **4.2 Data Sources UI & Branding:**
  - Añadir icono oficial SVG de YouTube en `App\Support\BrandIcon.php` (`youtubeAnalytics`).
  - Habilitar selección de activos (canales de YouTube) en la vista de Data Sources.
  - Puerta de versión (*tenant release version gate*): `$tenantRelease >= 'v1.19.0'`.
- [ ] **4.3 Controlador de Agregación API:**
  - Crear `YouTubeAnalyticsController.php`:
    - Endpoints: `/api/youtube-analytics/summary`, `/chart`, `/table`.
    - Consulta vía `RemoteEngineService::aggregateChanneledPool()` especificando `channel: 'youtube_analytics'`.
    - Manejo de filtros multi-cuenta / multi-canal en `validateRequest()`.
- [ ] **4.4 Dashboard Data Explorer:**
  - Crear página Filament `YouTubeAnalyticsDashboard.php` bajo el cluster `DataExplorer`.
  - Crear vista Blade `youtube-analytics-dashboard.blade.php`:
    1. **Tarjetas KPI:** Views, Horas Reproducidas, Suscriptores Netos, Likes, Engagement Rate.
    2. **Gráfico de Rendimiento Temporal:** Serie temporal diaria/semanal con selector interactivo de métricas.
    3. **Tabla de Desglose de Contenido:** Rendimiento por video individual con búsqueda, ordenación y miniaturas.
  - Script reactivo `resources/js/dashboards/youtube-analytics-dashboard.js`.

---

## 📌 Etapa 5: Testing & Quality Gates de Aceptación
- [ ] **G1: SDK & Autenticación:** Token refresh automático, cuota de YouTube respetada y llamadas seguras sin loops infinitos.
- [ ] **G2: Ingesta del Driver:** Validación de tipos de entidad en `$dataProcessor`, conversión canónica pura sin duplicidades.
- [ ] **G3: Migración & Base de Datos:** Migración de versión idempotente con índices optimizados en PostgreSQL.
- [ ] **G4: Integración Facade & Visual:** Dashboard reactivo con renderizado de KPIs exactos y Data Sources UI completamente funcional.
