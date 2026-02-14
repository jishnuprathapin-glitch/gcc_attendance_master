-- Migration: No-Punch Overrides (Timekeeper) + Reasons
-- Date: 2026-02-14
--
-- Run this with the target database selected (gcc_attendance_master).
--
-- Windows (PowerShell):
--   Get-Content docs\\sql\\20260214_no_punch_overrides_prod.sql -Raw | mysql -u root gcc_attendance_master
-- Windows (cmd.exe):
--   mysql -u root gcc_attendance_master < docs\\sql\\20260214_no_punch_overrides_prod.sql
--

/* ============================================================
   1) employee_att_daily_overrides (ensure required columns)
   ============================================================ */

CREATE TABLE IF NOT EXISTS `employee_att_daily_overrides` (
  `emp_code` varchar(10) NOT NULL,
  `att_date` date NOT NULL,
  `override_work_hours` decimal(9,2) DEFAULT NULL,
  `override_work_code` varchar(10) DEFAULT NULL,
  `override_reason_code` varchar(20) DEFAULT NULL,
  `override_reason_note` varchar(255) DEFAULT NULL,
  `override_change_date` datetime DEFAULT NULL,
  `override_changed_by_email` varchar(255) DEFAULT NULL,
  `override_changed_by_name` varchar(100) DEFAULT NULL,
  `override_approved_by_email` varchar(255) DEFAULT NULL,
  `override_approved_by_name` varchar(100) DEFAULT NULL,
  `override_is_approved` tinyint(1) DEFAULT NULL,
  `override_approved_date` datetime DEFAULT NULL,
  PRIMARY KEY (`emp_code`,`att_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_att_daily_overrides'
    AND column_name = 'override_reason_code'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE employee_att_daily_overrides ADD COLUMN override_reason_code varchar(20) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_att_daily_overrides'
    AND column_name = 'override_reason_note'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE employee_att_daily_overrides ADD COLUMN override_reason_note varchar(255) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* ============================================================
   2) attendance_no_punch_reasons (reason dropdown)
   ============================================================ */

CREATE TABLE IF NOT EXISTS `attendance_no_punch_reasons` (
  `reason_code` varchar(20) NOT NULL,
  `reason_text` varchar(100) NOT NULL,
  `override_work_hours` decimal(9,2) DEFAULT NULL,
  `override_work_code` varchar(10) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`reason_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `attendance_no_punch_reasons` (`reason_code`, `reason_text`, `override_work_hours`, `override_work_code`) VALUES
  ('TIME_INCORRECT','Time Captured Incorrectly',NULL,NULL),
  ('NO_LUNCH','No Lunch',NULL,NULL),
  ('MISS_PUNCH','Miss Punch',NULL,NULL),
  ('NIGHT_SHIFT','Night Shift',NULL,NULL),
  ('NIGHT_DAY_SHIFT','Night Day Shift',NULL,NULL),
  ('COMP_OFF','Compensatory Off',NULL,NULL),
  ('OTH','Others',NULL,NULL)
ON DUPLICATE KEY UPDATE
  `reason_text` = VALUES(`reason_text`);

/* ============================================================
   3) attendance_no_punch_reviews (camp boss submission tracking)
   ============================================================ */

CREATE TABLE IF NOT EXISTS `attendance_no_punch_reviews` (
  `emp_code` varchar(10) NOT NULL,
  `att_date` date NOT NULL,
  `timekeeper_note` varchar(255) DEFAULT NULL,
  `timekeeper_email` varchar(255) DEFAULT NULL,
  `timekeeper_name` varchar(100) DEFAULT NULL,
  `timekeeper_submitted_at` datetime DEFAULT NULL,
  `campboss_reason_code` varchar(20) DEFAULT NULL,
  `campboss_note` varchar(255) DEFAULT NULL,
  `campboss_email` varchar(255) DEFAULT NULL,
  `campboss_name` varchar(100) DEFAULT NULL,
  `campboss_reviewed_at` datetime DEFAULT NULL,
  `is_escalated` tinyint(1) NOT NULL DEFAULT 0,
  `escalated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`emp_code`,`att_date`),
  KEY `idx_campboss_reason` (`campboss_reason_code`),
  KEY `idx_escalated` (`is_escalated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* ============================================================
   4) New work code: COM (Compensatory Off)
   ============================================================ */

INSERT INTO `work_type_master` (`wt_cd`, `wt_desc`)
VALUES ('COM', 'Compensatory Off')
ON DUPLICATE KEY UPDATE
  `wt_desc` = VALUES(`wt_desc`);

