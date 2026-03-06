# 🚀 Cloud Deployment Checklist

Follow these steps to deploy `apis-hub` to Google Cloud Platform (GCP) using Artifact Registry and Cloud Run.

## 1. Prepare Docker Image

Build and push your container to the Google Artifact Registry.

```bash
# Set your GCP Project ID
export PROJECT_ID="your-project-id"
export REGION="us-central1"
export REPO="apis-hub-repo"
export IMAGE_TAG="v1"

# 1. Create Repository (Run once)
gcloud artifacts repositories create $REPO \
    --repository-format=docker \
    --location=$REGION

# 2. Build and Tag
docker build -t $REGION-docker.pkg.dev/$PROJECT_ID/$REPO/apis-hub:$IMAGE_TAG .

# 3. Configure Auth and Push
gcloud auth configure-docker $REGION-docker.pkg.dev
docker push $REGION-docker.pkg.dev/$PROJECT_ID/$REPO/apis-hub:$IMAGE_TAG
```

---

## 2. Infrastructure Requirements

Before deploying the service, ensure the following are available:

- **Cloud SQL**: A MySQL instance (recommended: 8.0, Shared CPU for dev/test).
- **VPC Connection**: If using Cloud SQL Private IP, ensure Serverless VPC Access is configured.
- **Secrets**: Use [Secret Manager](https://cloud.google.com/secret-manager) for sensitive tokens (DB_PASSWORD, FACEBOOK_TOKEN, etc.).

---

## 3. Deploy to Cloud Run

Deploy the containerized application.

```bash
gcloud run deploy apis-hub \
    --image $REGION-docker.pkg.dev/$PROJECT_ID/$REPO/apis-hub:$IMAGE_TAG \
    --region $REGION \
    --platform managed \
    --allow-unauthenticated \
    --set-env-vars "APP_ENV=production" \
    --set-env-vars "PROJECT_CONFIG_FILE=deploy/project.yaml"
```

---

## 4. Post-Deployment Verification

Verify the system health using the built-in diagnostic tools.

```bash
# Execute the health check on the live instance
php bin/cli.php app:health-check
```

### Expected Result

- **Database Connectivity**: ONLINE
- **Redis Cache Server**: ONLINE
- **Doctrine Schema Sync**: SYNCHRONIZED
- **Catalog Integrity**: INITIALIZED
