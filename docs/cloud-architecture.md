# ☁️ Cloud Architecture & Data Access Strategy

This document outlines the recommended strategies for deploying `apis-hub` on Google Cloud (GCP) and establishing secure, high-performance paths for data consumption by BigQuery and third-party applications.

## 1. Core Deployment: Cloud Run + Cloud SQL

To maintain persistence and scalability, the standard deployment uses:
- **Compute**: [Cloud Run](https://cloud.google.com/run) (Serverless containers for the Workers and API).
- **Persistence**: [Cloud SQL for MySQL](https://cloud.google.com/sql) (For the external-resilient database).
- **Cache**: [Cloud Memorystore](https://cloud.google.com/memorystore) (Redis) for job locking and state.

---

## 2. Access Strategy: BigQuery (GCP Native)

For analysis where you need to join `apis-hub` metrics with other business data, **Strategy 1 (Federated Queries)** is the best-in-class approach.

### 🔗 Cloud SQL Auth Proxy
BigQuery can query your Cloud SQL instance directly without moving any data first.
1. Create a **Cloud SQL Connection** in BigQuery.
2. Grant the BigQuery Service Account permission to read from the Cloud SQL instance.
3. Run queries using the `EXTERNAL_QUERY` function:
   ```sql
   SELECT * FROM EXTERNAL_QUERY("project.us.hub-connection", "SELECT * FROM channeled_metrics;");
   ```
- **Pros**: Zero-latency data availability, standard SQL, no extra storage costs.
- **Best For**: Data Scientists and BI Dashboards (Looker, Tableau).

---

## 3. Access Strategy: Third-Party Apps (Managed API)

When a third party needs programmatic access to your cached metrics through the API, use **Strategy 3 (API Gateway)** for professional-grade security.

### 🛡️ GCP API Gateway
Instead of exposing your Cloud Run URL directly, place the [API Gateway](https://cloud.google.com/api-gateway) in front of it.
- **Security**: Centralized `X-API-Key` management.
- **Performance**: Edge-caching and rate-limiting.
- **Workflow**: The third party calls `https://api.yourdomain.com/v1/metrics`, and the Gateway forwards the request to your container only if the key is valid.

- **Best For**: External SaaS integrations and custom frontend apps.

---

## 4. Access Strategy: "Big Chunks" (Large Exports)

If a consumer needs to download millions of rows (GigaBytes of data), the API container might time out. **Strategy 2 (Storage Exports)** is the resilient solution.

### 📦 GCS + Signed URLs
1. Your worker fetches data and, in addition to the DB, saves a compressed JSON/Parquet file to a **Google Cloud Storage (GCS)** bucket.
2. A third party requests a download via your API.
3. Your API generates a **Signed URL** (a temporary, secure link) to that GCS file.
4. The consumer downloads the data directly from Google’s storage backbone.

- **Best For**: Moving massive chunks of historical data for external processing.

---

## 🏁 Summary Checklist for Production

- [ ] Disable "Allow unauthenticated" on Cloud Run (except for the API Gateway path).
- [ ] Configure `DB_HOST` to point to the Cloud SQL Private IP.
- [ ] Implement `X-API-Key` in the `PROJECT_CONFIG_FILE` environment variable.
- [ ] Create a Service Account with `roles/cloudsql.client` for the Cloud Run instance.
