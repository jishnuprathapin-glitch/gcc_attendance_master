-- Migration: camp boss camp mapping + transfer-to-camp fields
-- Date: 2026-02-16
--
-- Windows (PowerShell):
--   Get-Content docs\sql\20260216_campboss_camp_mapping.sql -Raw | mysql -u root gcc_attendance_master
-- Windows (cmd.exe):
--   mysql -u root gcc_attendance_master < docs\sql\20260216_campboss_camp_mapping.sql
-- Linux/macOS:
--   mysql -u root gcc_attendance_master < docs/sql/20260216_campboss_camp_mapping.sql

/* 1) Camp boss user->camp mapping table */
CREATE TABLE IF NOT EXISTS `campboss_camp_map` (
  `user_id` varchar(50) NOT NULL,
  `camp_code` varchar(10) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`,`camp_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @idx := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'campboss_camp_map'
    AND index_name = 'idx_camp_code'
);
SET @sql := IF(@idx = 0, 'CREATE INDEX idx_camp_code ON campboss_camp_map (camp_code)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* 2) attendance_no_punch_reviews transfer-to-camp columns */
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'attendance_no_punch_reviews'
    AND column_name = 'transfer_to_camp_code'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE attendance_no_punch_reviews ADD COLUMN transfer_to_camp_code varchar(20) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'attendance_no_punch_reviews'
    AND column_name = 'transfer_to_camp_name'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE attendance_no_punch_reviews ADD COLUMN transfer_to_camp_name varchar(200) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'attendance_no_punch_reviews'
    AND index_name = 'idx_transfer_to_camp'
);
SET @sql := IF(@idx = 0, 'CREATE INDEX idx_transfer_to_camp ON attendance_no_punch_reviews (transfer_to_camp_code)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* 3) Backfill from legacy campboss_project_map (when available) */
SET @has_legacy := (
  SELECT COUNT(*)
  FROM information_schema.tables
  WHERE table_schema = DATABASE()
    AND table_name = 'campboss_project_map'
);

SET @sql := IF(
  @has_legacy = 1,
  'INSERT INTO campboss_camp_map (user_id, camp_code)
   SELECT src.user_id, src.resolved_camp_code
   FROM (
     SELECT
       p.user_id,
       CASE
         WHEN c1.camp_code IS NOT NULL THEN UPPER(TRIM(p.project_code))
         WHEN c2.camp_code IS NOT NULL THEN CONCAT(LEFT(UPPER(TRIM(p.project_code)), 1), LPAD(CAST(SUBSTRING(UPPER(TRIM(p.project_code)), 2) AS UNSIGNED), 2, ''0''))
         ELSE NULL
       END AS resolved_camp_code
     FROM campboss_project_map p
     LEFT JOIN hrms_camp_sync c1
       ON c1.is_deleted = 0
      AND UPPER(TRIM(c1.camp_code)) = UPPER(TRIM(p.project_code))
     LEFT JOIN hrms_camp_sync c2
       ON c2.is_deleted = 0
      AND UPPER(TRIM(c2.camp_code)) = CONCAT(LEFT(UPPER(TRIM(p.project_code)), 1), LPAD(CAST(SUBSTRING(UPPER(TRIM(p.project_code)), 2) AS UNSIGNED), 2, ''0''))
   ) src
   WHERE src.user_id IS NOT NULL
     AND TRIM(src.user_id) <> ''''
     AND src.resolved_camp_code IS NOT NULL
   ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* 4) For now: map all active camps to user id 1 */
INSERT IGNORE INTO campboss_camp_map (user_id, camp_code)
SELECT '1', UPPER(TRIM(camp_code))
FROM hrms_camp_sync
WHERE is_deleted = 0
  AND TRIM(COALESCE(camp_code, '')) <> '';
