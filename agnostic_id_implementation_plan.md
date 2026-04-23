# Implementation Plan: Agnostic Role-Based Identification Architecture

## 1. Rationale and Objective

### The Problem: Leaky Abstractions

Currently, the Drivers in this project are tightly coupled to the APIs Hub's internal entity names. Methods like `getPagePlatformId` or `getChanneledAccountPlatformId` force the Driver to know about the Hub's database schema. This creates a maintenance burden: if an entity is renamed in the Hub, all drivers must be updated.

### The Solution: Role-Based Agnoticism

This architecture introduces a functional middle-layer using **Asset Categories**. The Driver describes the _nature_ of its data (e.g., "this is a identifiable identity", "this is a pageable asset"). The Hub then maps these functional roles to its own internal entities.

### Key Goals

- **Total Decoupling**: Remove all mentions of Hub-specific entities (Page, ChanneledAccount) from the Driver codebase.
- **Unified Truth**: Centralize identification logic in the Driver so that all parts of the Hub (Web Sync, CLI Sync, Config Update) use the same identification formulas.
- **Scalability**: Allow the Hub to support new complex domains (E-Commerce, Automation) simply by extending the agnostic vocabulary.

---

## 2. Phase 1: Core Foundation (`api-driver-core`)

### 2.1 The Agnostic Vocabulary (`AssetCategory.php`)

Define universal functional roles in a shared Enum. This is the shared dictionary both sides use.

- `IDENTITY`: The root connection/owner (e.g., Meta Ad Account, GSC Site).
- `PAGEABLE`: Assets with a URL or canonical structure (e.g., FB Page, GSC Domain).
- `CAMPAIGN`: High-level marketing containers (e.g., Ads Campaigns, Email Flows).
- `GROUPING`: Mid-level organizational structures (e.g., AdSets, Flow Steps).
- `UNIT`: Primary data-generating units (e.g., Post, Ad, Media items).
- `RESOURCE`: Shared assets (e.g., Creatives, Audiences).

### 2.2 The Unified Contract (`SyncDriverInterface.php`)

Introduce primary entry points for identification:

```php
public static function getPlatformId(array $asset, AssetCategory $category, string $context): string;
public static function getCanonicalId(array $asset, AssetCategory $category, string $context): string;
```

---

## 3. Phase 2: Driver Alignment

### 3.1 Metadata Enrichment (`getAssetPatterns`)

Tag every asset pattern with its functional category.

- **SearchConsoleDriver**: Map `gsc` sites to both `IDENTITY` and `PAGEABLE`.
- **FacebookOrganicDriver**: Map `facebook_page` to `PAGEABLE`, `facebook_post` to `UNIT`.
- **FacebookMarketingDriver**: Map `ad_account` to `IDENTITY`, `campaign` to `CAMPAIGN`.

### 3.2 Implementation of the Truth Factory

Implement the `match`-based resolution in each driver.

```php
public static function getPlatformId(array $asset, AssetCategory $category, string $context): string {
    return match($category) {
        AssetCategory::PAGEABLE => self::deriveSearchConsoleId($asset),
        AssetCategory::UNIT     => self::derivePostId($asset),
        default => (string) ($asset['id'] ?? '')
    };
}
```

---

## 4. Phase 3: Hub Refactoring (`apis-hub`)

### 3.1 Internal Mapping

The Hub maintains an internal map between its Entities and the Categories.

- `Entities\Analytics\Page` -> `AssetCategory::PAGEABLE`
- `Entities\Analytics\ChanneledAccount` -> `AssetCategory::IDENTITY`

### 3.2 Component Refactoring

- **SyncService**: Update `identityMapper` to use `$driver::getPlatformId` passing the category matching the repository.
- **MetricsProcessor**: Update page resolution to use the driver's role-based methods instead of legacy helpers.
- **InitializeEntitiesCommand**: Replace the hardcoded initialization loop with one that discovers patterns via their category tags.

---

## 5. Phase 4: Migration & Cleanup

1. **Verification**: Execute sync for all major channels to confirm metrics are correctly mapped using the new role-based logic.
2. **Removal**: Delete all Hub-specific strings from drivers. Rename or inline internal helper methods.
3. **Deprecation**: Remove legacy identification helpers (`PagePatternsHelper`, `Helpers::getCanonicalPageId`). Every identification request MUST go through the Driver role methods.
