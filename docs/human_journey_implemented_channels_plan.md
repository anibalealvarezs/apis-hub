# Human Journey Expansion Plans for Implemented Drivers

> **Target Paradigm:** Elevating implemented drivers from aggregate calculation engines to dual-track **Human Journey & Causality Intelligence Engines**.  
> **Conformed Identity Key:** $\text{identity\_hash} = \text{MD5}(\text{LOWER}(\text{TRIM}(\text{email\_address})))$

---

## 🗺️ Channel Capability & Journey Feasibility Assessment

Below is the strategic evaluation of our 6 production channels against the **Human Journey Framework**:

| Implemented Channel | Journey Feasibility | Human Journey Role | Implementation Scope Required |
| :--- | :--- | :--- | :--- |
| **Mailchimp** | **HIGH (Tier 1 Gold-Mine)** | **Lifecycle Nurture & Engagement Engine** | Hydrate unhashed emails, capture audience contact traits into `channeled_customers`, link recipient events to `Customer`. |
| **Facebook Marketing** | **HIGH (Tier 2 Causality)** | **Causal Ad Touchpoint Anchor** | Inject campaign, ad set, and ad parameters as attribution metadata on incoming lead/order journeys. |
| **Google Ads** | **MEDIUM-HIGH (Tier 2 Causality)** | **Search Intent & Enhanced Conversion Anchor** | Map click identifiers (GCLID), search intent terms, and conversion actions to user purchase events. |
| **Google Analytics 4** | **LOW-MEDIUM (Session Graph)** | **Aggregated Journey Funnel Modeling** | Anonymized `user_pseudo_id` journey staging (path-to-conversion sequences); no direct unhashed PII. |
| **Google Search Console** | **NONE (Pure Aggregate)** | **Macro Discovery Only** | Search impressions and rankings are mathematically aggregated; cannot link to individual humans. |

---

## 📌 Implementation Plan 1: Mailchimp Human Journey Expansion

### 1. The Architectural Opportunity
Currently, `MailchimpDriver` streams recipient activity events (`opens`, `clicks`, `bounces`, `unsubscribes`) into `channeled_events` with `identity_hash` set to `email_id` (Mailchimp's internal MD5 hash).
However:
1. It does **not** ingest the actual unhashed contact record into `channeled_customers`.
2. It does not pull the subscriber's profile attributes: `email_address`, `first_name`, `last_name`, `opt-in status`, `VIP status`, `member rating (1-5)`, or `tags`.

### 2. Implementation Steps
- [ ] **Step 1: Ingest Audience Members as `channeled_customers` (`syncEntities`)**
  - In `MailchimpDriver::syncEntities()`, call `$api->getAllMembersAndProcess($listId)`:
    - Extract plain `email_address`, `merge_fields` (FNAME, LNAME, PHONE), `member_rating`, `vip`, and `tags`.
    - Compute `identity_hash = md5(strtolower(trim($member['email_address'])))`.
    - Convert to `UniversalEntity` and push to:
      ```php
      ($this->dataProcessor)($customerCollection, 'channeled_customer');
      ```
- [ ] **Step 2: Unify `identity_hash` across Events & Customer Records**
  - Verify that `MailchimpConvert::events()` maps `identity_hash` identically to the member's MD5 hash.
  - Ingest `tags` into `lead_data` on `channeled_customers` for behavioral segmentation in the CRM view.
- [ ] **Step 3: Facade Journey View in Mailchimp Data Explorer**
  - Add a **"Subscribers & Engagement Timeline"** tab in `MailchimpDashboard.php`:
    - Shows individual subscribers, their Mailchimp engagement rating, open rate, and full history of campaign emails opened/clicked.

---

## 📌 Implementation Plan 2: Facebook Marketing (Causal Attribution Anchor)

### 2.1 The Architectural Opportunity
Facebook Marketing (`FacebookMarketingDriver`) does not capture human PII directly, but it provides the **monetary and creative causality** for Meta Leads and eCommerce orders.

Currently, if a lead comes from Facebook Leads or an order comes from Shopify with UTMs, we know the `campaign_id` and `ad_id`, but the marketing driver doesn't expose a unified attribution resolution service to translate raw IDs into creative and budget context.

### 2.2 Implementation Steps
- [ ] **Step 1: Attribution Cross-Reference Resolver in `apis-hub`**
  - Implement an agnostic `AttributionEnrichmentService` in `apis-hub`:
    - When a `channeled_event` or `channeled_customer` is ingested with `campaign_id` or `ad_id`, automatically link it to the corresponding `channeled_campaigns` and `channeled_ads` rows created by `FacebookMarketingDriver`.
- [ ] **Step 2: Creative & Spend Context Injection**
  - Enrich the human journey timeline with the specific ad creative name, thumbnail, and targeting context:
    ```json
    {
      "touchpoint": "submitted_lead_form",
      "attribution": {
        "channel": "facebook_marketing",
        "campaign_name": "Summer_Retargeting_2026",
        "ad_name": "Video_Testimonial_02",
        "spend_tier": "High Intent"
      }
    }
    ```

---

## 📌 Implementation Plan 3: Google Ads (Search Intent & Conversion Journey)

### 3.1 The Architectural Opportunity
Similar to Facebook Marketing, Google Ads provides the search intent context (the exact query or keyword that prompted the human to click).

### 3.2 Implementation Steps
- [ ] **Step 1: Search Terms & Intent Linkage**
  - In `GoogleAdsDriver`, leverage the newly implemented `search_terms` breakdown:
  - When customers convert on eCommerce stores carrying Google Click IDs (`gclid`) or UTM campaign parameters, join the conversion event to the search term query.
- [ ] **Step 2: Journey Attribution Metadata**
  - Attach the search intent category (Navigational, Informational, Commercial, Transactional) from `QueryClassificationService` to the human's first-touch discovery event.

---

## 📌 Implementation Plan 4: Unified Human Journey Hub Core & Facade

### 4.1 Orchestrator Core (`apis-hub`)
- [ ] **Step 1: The Agnostic Journey Graph Service (`HumanJourneyService`)**
  - Create `src/Services/Analytics/HumanJourneyService.php`:
    - Method: `getTimelineForIdentity(string $identityHash): array`
    - Queries `channeled_customers` where `email = $email` OR `identity_hash = $hash`.
    - Queries `channeled_events` across **all channels** (`facebook_leads`, `mailchimp`, `shopify`) matching that customer.
    - Sorts events chronologically (`timestamp ASC`).
    - Returns the structured multi-channel timeline.

### 4.2 Facade UI (`apis-hub-facade`)
- [ ] **Step 2: Universal Customer Journey Explorer**
  - Create a dedicated **Customer Journey / CDP Explorer** page in Facade:
    - Search by Email or Name.
    - Displays the **Unified Human Profile Card** (Name, email, phone, first contact date, attributed acquisition campaign, total lifetime spend).
    - Renders an interactive vertical timeline:
      - 🟢 **Lead Acquired:** Meta Leads (Instant Form X, Campaign Y)
      - 🔵 **Email Nurtured:** Mailchimp (Welcome email opened, Link clicked)
      - 🟠 **Converted:** Shopify (Order #1042 placed, $149.50)
