-- ============================================================
-- MIGRATION: Rename old columns to match new schema
-- Run this in MySQL Workbench if you already imported the old DB
-- Server → Data Import → Self-Contained File → migrate.sql
-- ============================================================
USE itam_db;

-- Rename assets columns (only runs if old columns exist)
SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='itam_db' AND TABLE_NAME='assets' AND COLUMN_NAME='cpu'),
  'ALTER TABLE assets
    CHANGE COLUMN cpu        processor        VARCHAR(150),
    CHANGE COLUMN os         operating_system VARCHAR(100),
    CHANGE COLUMN os_version os_version       VARCHAR(50),
    CHANGE COLUMN vendor     supplier         VARCHAR(100),
    CHANGE COLUMN storage_type storage        VARCHAR(100),
    CHANGE COLUMN storage_cap storage_backup  VARCHAR(100)',
  'SELECT "columns already renamed, no action needed"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add missing columns if they don't exist yet
ALTER TABLE assets
  ADD COLUMN IF NOT EXISTS device_type      VARCHAR(50)  DEFAULT 'Desktop'  AFTER model,
  ADD COLUMN IF NOT EXISTS monitor          VARCHAR(100)                     AFTER gpu,
  ADD COLUMN IF NOT EXISTS connection_type  VARCHAR(50)                      AFTER mac_address,
  ADD COLUMN IF NOT EXISTS isp              VARCHAR(100)                     AFTER connection_type,
  ADD COLUMN IF NOT EXISTS po_number        VARCHAR(100)                     AFTER supplier,
  ADD COLUMN IF NOT EXISTS lifecycle_state  VARCHAR(50)  DEFAULT 'Active'    AFTER lifecycle_stage,
  ADD COLUMN IF NOT EXISTS is_flagged       TINYINT(1)   DEFAULT 0,
  ADD COLUMN IF NOT EXISTS flag_reason      VARCHAR(200),
  ADD COLUMN IF NOT EXISTS antivirus_installed TINYINT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS antivirus_name   VARCHAR(100) DEFAULT 'Windows Defender',
  ADD COLUMN IF NOT EXISTS firewall_enabled TINYINT(1)   DEFAULT 1,
  ADD COLUMN IF NOT EXISTS encryption_enabled TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS last_virus_scan  DATE,
  ADD COLUMN IF NOT EXISTS last_backup      DATE,
  ADD COLUMN IF NOT EXISTS photo_url        VARCHAR(255);

-- Rename lifecycle_stage to lifecycle_state if needed
SET @sql2 = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='itam_db' AND TABLE_NAME='assets' AND COLUMN_NAME='lifecycle_stage'),
  'ALTER TABLE assets CHANGE COLUMN lifecycle_stage lifecycle_state VARCHAR(50) DEFAULT ''Active''',
  'SELECT "lifecycle_state already correct"'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

SELECT 'Migration complete. Refresh your browser.' AS result;
