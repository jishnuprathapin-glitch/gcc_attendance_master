-- Migration: production schema sync to match localhost (schema-only)
-- Date: 2026-02-14
-- Compared:
-- - Production export: gcc_attendance_master.sql (phpMyAdmin, generated 2026-02-14 10:37 AM, MariaDB 10.4.32)
-- - Localhost DB: gcc_attendance_master (MariaDB 10.4.32)
--
-- Differences found:
-- - Missing tables in production: campboss_project_map, public_holiday_calendar
-- - Missing index in production: employee_att_daily.idx_emp_att_date (emp_code, att_date)
--
-- Run this script with the target database selected.
-- Windows (PowerShell):
--   Get-Content docs\sql\20260214_prod_schema_migration.sql -Raw | mysql -u root gcc_attendance_master
-- Windows (cmd.exe):
--   mysql -u root gcc_attendance_master < docs\sql\20260214_prod_schema_migration.sql
-- Linux/macOS:
--   mysql -u root gcc_attendance_master < docs/sql/20260214_prod_schema_migration.sql

-- 1) Missing tables
CREATE TABLE IF NOT EXISTS `campboss_project_map` (
  `user_id` varchar(50) NOT NULL,
  `project_code` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`project_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `public_holiday_calendar` (
  `holiday_date` date NOT NULL,
  `holiday_name` varchar(100) NOT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT 0,
  `source` varchar(255) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`holiday_date`,`holiday_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2) Missing index
SET @has_idx := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_att_daily'
    AND index_name = 'idx_emp_att_date'
);
SET @sql := IF(@has_idx = 0, 'ALTER TABLE employee_att_daily ADD INDEX idx_emp_att_date (emp_code, att_date)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
