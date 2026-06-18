-- Migration: Add Location, State, City entities and geo FK columns on metric_configs
-- Generated from Doctrine ORM entity annotations

BEGIN;

-- ============================================================
-- Table: states
-- ============================================================
CREATE TABLE IF NOT EXISTS states (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    code        VARCHAR(255) DEFAULT NULL,
    country_id  INT NOT NULL,
    created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    version     INT NOT NULL DEFAULT 1
);

CREATE UNIQUE INDEX UNIQ_STATE_NAME_COUNTRY ON states (name, country_id);

ALTER TABLE states
    ADD CONSTRAINT FK_STATE_COUNTRY
    FOREIGN KEY (country_id) REFERENCES countries (id)
    ON DELETE CASCADE
    NOT DEFERRABLE INITIALLY IMMEDIATE;

-- ============================================================
-- Table: cities
-- ============================================================
CREATE TABLE IF NOT EXISTS cities (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    state_id    INT DEFAULT NULL,
    country_id  INT NOT NULL,
    created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    version     INT NOT NULL DEFAULT 1
);

CREATE UNIQUE INDEX UNIQ_CITY_NAME_COUNTRY ON cities (name, country_id);

ALTER TABLE cities
    ADD CONSTRAINT FK_CITY_STATE
    FOREIGN KEY (state_id) REFERENCES states (id)
    ON DELETE SET NULL
    NOT DEFERRABLE INITIALLY IMMEDIATE;

ALTER TABLE cities
    ADD CONSTRAINT FK_CITY_COUNTRY
    FOREIGN KEY (country_id) REFERENCES countries (id)
    ON DELETE CASCADE
    NOT DEFERRABLE INITIALLY IMMEDIATE;

-- ============================================================
-- Table: locations
-- ============================================================
CREATE TABLE IF NOT EXISTS locations (
    id                      SERIAL PRIMARY KEY,
    platform_id             VARCHAR(255) NOT NULL,
    title                   VARCHAR(255) NOT NULL,
    store_code              VARCHAR(255) DEFAULT NULL,
    lat                     DOUBLE PRECISION DEFAULT NULL,
    lng                     DOUBLE PRECISION DEFAULT NULL,
    zip_code                VARCHAR(255) DEFAULT NULL,
    data                    JSON DEFAULT NULL,
    account_id              INT DEFAULT NULL,
    channeled_account_id    INT DEFAULT NULL,
    city_id                 INT DEFAULT NULL,
    state_id                INT DEFAULT NULL,
    country_id              INT DEFAULT NULL,
    created_at              TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    version                 INT NOT NULL DEFAULT 1
);

CREATE UNIQUE INDEX UNIQ_LOCATION_PLATFORM ON locations (platform_id);
CREATE INDEX IDX_LOCATIONS_PLATFORM_ID ON locations (platform_id);
CREATE INDEX IDX_LOCATIONS_PLATFORM_ACCOUNT ON locations (platform_id, account_id);
CREATE INDEX IDX_LOCATIONS_PLATFORM_CACCOUNT ON locations (platform_id, channeled_account_id);
CREATE INDEX IDX_LOCATIONS_CITY ON locations (city_id);
CREATE INDEX IDX_LOCATIONS_STATE ON locations (state_id);
CREATE INDEX IDX_LOCATIONS_COUNTRY ON locations (country_id);

ALTER TABLE locations
    ADD CONSTRAINT FK_LOCATION_ACCOUNT
    FOREIGN KEY (account_id) REFERENCES accounts (id)
    ON DELETE SET NULL
    NOT DEFERRABLE INITIALLY IMMEDIATE;

ALTER TABLE locations
    ADD CONSTRAINT FK_LOCATION_CHANNELED_ACCOUNT
    FOREIGN KEY (channeled_account_id) REFERENCES channeled_accounts (id)
    ON DELETE SET NULL
    NOT DEFERRABLE INITIALLY IMMEDIATE;

ALTER TABLE locations
    ADD CONSTRAINT FK_LOCATION_CITY
    FOREIGN KEY (city_id) REFERENCES cities (id)
    ON DELETE SET NULL
    NOT DEFERRABLE INITIALLY IMMEDIATE;

ALTER TABLE locations
    ADD CONSTRAINT FK_LOCATION_STATE
    FOREIGN KEY (state_id) REFERENCES states (id)
    ON DELETE SET NULL
    NOT DEFERRABLE INITIALLY IMMEDIATE;

ALTER TABLE locations
    ADD CONSTRAINT FK_LOCATION_COUNTRY
    FOREIGN KEY (country_id) REFERENCES countries (id)
    ON DELETE SET NULL
    NOT DEFERRABLE INITIALLY IMMEDIATE;

-- ============================================================
-- Add geo FK columns to metric_configs
-- ============================================================
ALTER TABLE metric_configs
    ADD COLUMN location_id INT DEFAULT NULL,
    ADD COLUMN state_id    INT DEFAULT NULL,
    ADD COLUMN city_id     INT DEFAULT NULL;

CREATE INDEX IDX_METRIC_CONFIGS_LOCATION ON metric_configs (location_id);

ALTER TABLE metric_configs
    ADD CONSTRAINT FK_METRIC_CONFIG_LOCATION
    FOREIGN KEY (location_id) REFERENCES locations (id)
    ON DELETE SET NULL
    NOT DEFERRABLE INITIALLY IMMEDIATE;

ALTER TABLE metric_configs
    ADD CONSTRAINT FK_METRIC_CONFIG_STATE
    FOREIGN KEY (state_id) REFERENCES states (id)
    ON DELETE SET NULL
    NOT DEFERRABLE INITIALLY IMMEDIATE;

ALTER TABLE metric_configs
    ADD CONSTRAINT FK_METRIC_CONFIG_CITY
    FOREIGN KEY (city_id) REFERENCES cities (id)
    ON DELETE SET NULL
    NOT DEFERRABLE INITIALLY IMMEDIATE;

COMMIT;
