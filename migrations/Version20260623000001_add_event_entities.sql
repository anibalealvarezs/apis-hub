-- Migration: Add Event, ChanneledEvent entities and event FK columns on metric_configs
-- Generated from Doctrine ORM entity annotations

BEGIN;

-- ============================================================
-- Table: events
-- ============================================================
CREATE TABLE IF NOT EXISTS events (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    version     INT NOT NULL DEFAULT 1
);

CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_EVENT_NAME ON events (name);

-- ============================================================
-- Table: channeled_events
-- ============================================================
CREATE TABLE IF NOT EXISTS channeled_events (
    id                      SERIAL PRIMARY KEY,
    platform_id             VARCHAR(255) NOT NULL,
    channel                 INT NOT NULL,
    channeled_account_id    INT DEFAULT NULL,
    event_id                INT DEFAULT NULL,
    created_at              TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    version                 INT NOT NULL DEFAULT 1
);

CREATE INDEX IF NOT EXISTS idx_channeled_events_platform_id_idx ON channeled_events (platform_id);
CREATE INDEX IF NOT EXISTS idx_channeled_events_channel_idx ON channeled_events (channel);
CREATE INDEX IF NOT EXISTS idx_channeled_events_platform_channel_idx ON channeled_events (platform_id, channel);
CREATE INDEX IF NOT EXISTS idx_channeled_events_channeled_account_id_idx ON channeled_events (channeled_account_id);
CREATE INDEX IF NOT EXISTS idx_channeled_events_event_idx ON channeled_events (event_id);
CREATE INDEX IF NOT EXISTS idx_channeled_events_channeled_account_id_event_id_idx ON channeled_events (channeled_account_id, event_id);
CREATE UNIQUE INDEX IF NOT EXISTS channeled_events_platform_id_account_id_uidx ON channeled_events (platform_id, channeled_account_id);

ALTER TABLE channeled_events
    ADD CONSTRAINT FK_CHANNELED_EVENTS_EVENT
    FOREIGN KEY (event_id) REFERENCES events (id)
    ON DELETE CASCADE
    NOT DEFERRABLE INITIALLY IMMEDIATE;

ALTER TABLE channeled_events
    ADD CONSTRAINT FK_CHANNELED_EVENTS_CHANNELED_ACCOUNT
    FOREIGN KEY (channeled_account_id) REFERENCES channeled_accounts (id)
    ON DELETE CASCADE
    NOT DEFERRABLE INITIALLY IMMEDIATE;

-- ============================================================
-- Add event FK columns to metric_configs
-- ============================================================
ALTER TABLE metric_configs
    ADD COLUMN IF NOT EXISTS event_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS channeled_event_id INT DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_metric_configs_lookup_event_idx ON metric_configs (channel, name, period, event_id);
CREATE INDEX IF NOT EXISTS idx_metric_configs_lookup_channeled_event_idx ON metric_configs (channel, name, period, channeled_event_id);
CREATE INDEX IF NOT EXISTS idx_metric_configs_event_idx ON metric_configs (event_id);
CREATE INDEX IF NOT EXISTS idx_metric_configs_channeled_event_idx ON metric_configs (channeled_event_id);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'metric_configs'::regclass AND conname = 'fk_metric_configs_event') THEN
        ALTER TABLE metric_configs ADD CONSTRAINT FK_METRIC_CONFIGS_EVENT FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'metric_configs'::regclass AND conname = 'fk_metric_configs_channeled_event') THEN
        ALTER TABLE metric_configs ADD CONSTRAINT FK_METRIC_CONFIGS_CHANNELED_EVENT FOREIGN KEY (channeled_event_id) REFERENCES channeled_events (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
    END IF;
END $$;

-- ============================================================
-- Add GA4 and GBP optimized indexes to metric_configs
-- ============================================================
CREATE INDEX IF NOT EXISTS idx_metric_configs_event_matrix_idx ON metric_configs (channel, channeled_account_id, channeled_event_id, dimension_set_id, name);
CREATE INDEX IF NOT EXISTS idx_metric_configs_traffic_matrix_idx ON metric_configs (channel, channeled_account_id, page_id, country_id, device_id, dimension_set_id, name);
CREATE INDEX IF NOT EXISTS idx_metric_configs_acquisition_matrix_idx ON metric_configs (channel, channeled_account_id, channeled_campaign_id, dimension_set_id, name);
CREATE INDEX IF NOT EXISTS idx_metric_configs_gbp_lookup_idx ON metric_configs (channel, channeled_account_id, location_id, dimension_set_id, name);

COMMIT;
