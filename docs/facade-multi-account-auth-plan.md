# Facade Multi-Account Auth & Credential Lifecycle Plan (PLAN-05)

**Repository:** `d:\laragon\www\apis-hub-facade`  
**Master Plan Reference:** [`docs/v1.17.0-master-implementation-plan.md`](file:///d:/laragon/www/apis-hub/docs/v1.17.0-master-implementation-plan.md) (Phase 5)  
**Parent Layer:** Business (Facade)

---

## 1. Executive Summary & Facade Role

In `v1.17.0`, Facade introduces **Multi-Account Credential Management** in the Data Sources dashboard. 

Currently, Facade connects channels via single global OAuth tokens. For channels like Mailchimp (and subsequently Klaviyo and Shopify multi-store), Facade will:
1. Allow users to connect $N$ separate accounts/stores under the same project.
2. Provide a dual-tab modal: Tab 1 for API Key (primary/fast), Tab 2 for OAuth 2.0.
3. Validate keys in real-time (`ping`) prior to saving.
4. Hot-inject credentials to the remote APIs Hub instance via `POST /api/credentials/{channel}`.
5. Track and isolate authentication failure via `lost_access = true` badges and in-place recovery modals.

---

## 2. UI & Component Architecture (`DataSources.php`)

### 2.1. Dual-Tab Connection Modal
When clicking **"Connect Account"** or **"Add Connection"** on Mailchimp:
- **Tab 1: API Key (Default & Recommended):**
  - Text input for API Key.
  - Automatic datacenter extraction (`...-us14` $\to$ sets `server_prefix = us14`).
  - Account label input (e.g. "US Client Account").
  - Live verification button triggering a background `ping()` to confirm validity before submitting.
- **Tab 2: OAuth 2.0:**
  - Standard "Authorize with Mailchimp" redirect button via Socialite extension.

### 2.2. Multi-Account Section Layout
Instead of a single flat asset list, `DataSources.php` renders:
```
Mailchimp Channel
├── Account Connection: "Acme Store (us4)" [Status: Connected] [Edit Key] [Disconnect]
│     ├── Audiences (Lists):
│     │     ├── [x] Main Subscribers (12,450 contacts)
│     │     └── [ ] Internal Team (14 contacts)
│     └── Connected Stores:
│           └── [x] Acme Shopify Store (acme.myshopify.com)
│
└── Account Connection: "Brand B (us19)" [Status: ⚠️ Lost Access] [Update Key] [Disconnect]
      └── Audiences (Lists):
            └── [x] Brand B Newsletter (4,200 contacts)
```

---

## 3. Data Model & Storage

### 3.1. `ProjectCredential` Model (`app/Models/ProjectCredential.php`)
Stores each account connection with encrypted casts:
- `project_id`: Current tenant ID
- `provider`: `'mailchimp'`
- `token`: Encrypted API Key or OAuth Access Token
- `external_user_id`: Mailchimp Account ID (`acc_us4_12345`)
- `meta`:
  ```json
  {
    "server_prefix": "us4",
    "account_name": "Acme Store",
    "auth_method": "api_key"
  }
  ```

### 3.2. Project `sync_config` Representation
Inside `projects.sync_config`:
```json
{
  "mailchimp": {
    "enabled": true,
    "accounts": [
      {
        "id": "acc_us4_12345",
        "name": "Acme Store",
        "server_prefix": "us4",
        "enabled": true,
        "lost_access": false,
        "audiences": [
          { "id": "list_1", "name": "Main Subscribers", "enabled": true, "lost_access": false }
        ],
        "stores": [
          { "id": "store_1", "name": "Acme Shopify", "domain": "acme.myshopify.com", "enabled": true }
        ]
      }
    ]
  }
}
```

---

## 4. Remote Injection Protocol (`DeployerService.php`)

When a user adds or updates a Mailchimp credential:
1. Facade calls the APIs Hub node's secure endpoint:
   ```http
   POST https://{tenant}.apis-hub.cloud/api/credentials/mailchimp
   Headers:
     X-Admin-API-Key: {admin_key}
   Content-Type: application/json

   {
     "account_id": "acc_us4_12345",
     "account_name": "Acme Store",
     "api_key": "md5string-us4",
     "server_prefix": "us4"
   }
   ```
2. APIs Hub's `SocialAuthController` receives the payload and invokes:
   ```php
   MailchimpDriver::storeCredentials($data);
   ```
3. The credential is saved directly to `storage/tokens/mailchimp_tokens.json` without requiring a full container restart.

---

## 5. Asset Quota & Billing Integration

In `AssetQuotaService.php`:
- Every enabled Audience counts as **1 tracked asset** towards the billing tier quota.
- Every enabled Connected Store counts as **1 tracked asset**.
- Toggling assets on/off updates the live tier quota counter instantly.
