-- Migration: No-punch reason governance, escalation routing, and transfer camp support
-- Date: 2026-02-16
--
-- Run this with the target database selected (gcc_attendance_master).
-- Windows (PowerShell):
--   Get-Content docs\\sql\\20260216_no_punch_reason_governance.sql -Raw | mysql -u root gcc_attendance_master
-- Windows (cmd.exe):
--   mysql -u root gcc_attendance_master < docs\\sql\\20260216_no_punch_reason_governance.sql

/* ============================================================
   1) attendance_no_punch_reasons: scope + behavior metadata
   ============================================================ */

CREATE TABLE IF NOT EXISTS `attendance_no_punch_reasons` (
  `reason_code` varchar(20) NOT NULL,
  `reason_text` varchar(100) NOT NULL,
  `override_work_hours` decimal(9,2) DEFAULT NULL,
  `override_work_code` varchar(10) DEFAULT NULL,
  `visible_to_timekeeper` tinyint(1) NOT NULL DEFAULT 1,
  `visible_to_campboss` tinyint(1) NOT NULL DEFAULT 1,
  `auto_escalate` tinyint(1) NOT NULL DEFAULT 0,
  `requires_transfer_project` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `default_behavior` varchar(30) NOT NULL DEFAULT 'NONE',
  `default_work_code` varchar(10) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`reason_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'attendance_no_punch_reasons' AND column_name = 'visible_to_timekeeper'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE attendance_no_punch_reasons ADD COLUMN visible_to_timekeeper tinyint(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'attendance_no_punch_reasons' AND column_name = 'visible_to_campboss'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE attendance_no_punch_reasons ADD COLUMN visible_to_campboss tinyint(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'attendance_no_punch_reasons' AND column_name = 'auto_escalate'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE attendance_no_punch_reasons ADD COLUMN auto_escalate tinyint(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'attendance_no_punch_reasons' AND column_name = 'requires_transfer_project'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE attendance_no_punch_reasons ADD COLUMN requires_transfer_project tinyint(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'attendance_no_punch_reasons' AND column_name = 'is_active'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE attendance_no_punch_reasons ADD COLUMN is_active tinyint(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'attendance_no_punch_reasons' AND column_name = 'default_behavior'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE attendance_no_punch_reasons ADD COLUMN default_behavior varchar(30) NOT NULL DEFAULT ''NONE''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'attendance_no_punch_reasons' AND column_name = 'default_work_code'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE attendance_no_punch_reasons ADD COLUMN default_work_code varchar(10) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'attendance_no_punch_reasons' AND index_name = 'idx_reason_scope'
);
SET @sql := IF(@idx = 0, 'CREATE INDEX idx_reason_scope ON attendance_no_punch_reasons (visible_to_timekeeper, visible_to_campboss, is_active)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'attendance_no_punch_reasons' AND index_name = 'idx_auto_escalate'
);
SET @sql := IF(@idx = 0, 'CREATE INDEX idx_auto_escalate ON attendance_no_punch_reasons (auto_escalate)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* Code migration: ABD -> EMP_ABSCONDING, ERC -> EMP_RESIGNED */
INSERT INTO attendance_no_punch_reasons (
  reason_code, reason_text, override_work_hours, override_work_code,
  visible_to_timekeeper, visible_to_campboss, auto_escalate, requires_transfer_project,
  is_active, default_behavior, default_work_code
)
SELECT 'EMP_ABSCONDING', reason_text, override_work_hours, override_work_code,
       visible_to_timekeeper, visible_to_campboss, auto_escalate, requires_transfer_project,
       is_active, default_behavior, default_work_code
FROM attendance_no_punch_reasons src
WHERE src.reason_code = 'ABD'
  AND NOT EXISTS (SELECT 1 FROM attendance_no_punch_reasons dst WHERE dst.reason_code = 'EMP_ABSCONDING');

INSERT INTO attendance_no_punch_reasons (
  reason_code, reason_text, override_work_hours, override_work_code,
  visible_to_timekeeper, visible_to_campboss, auto_escalate, requires_transfer_project,
  is_active, default_behavior, default_work_code
)
SELECT 'EMP_RESIGNED', reason_text, override_work_hours, override_work_code,
       visible_to_timekeeper, visible_to_campboss, auto_escalate, requires_transfer_project,
       is_active, default_behavior, default_work_code
FROM attendance_no_punch_reasons src
WHERE src.reason_code = 'ERC'
  AND NOT EXISTS (SELECT 1 FROM attendance_no_punch_reasons dst WHERE dst.reason_code = 'EMP_RESIGNED');

UPDATE attendance_no_punch_reviews SET campboss_reason_code = 'EMP_ABSCONDING' WHERE campboss_reason_code = 'ABD';
UPDATE attendance_no_punch_reviews SET campboss_reason_code = 'EMP_RESIGNED' WHERE campboss_reason_code = 'ERC';
UPDATE employee_att_daily_overrides SET override_reason_code = 'EMP_ABSCONDING' WHERE override_reason_code = 'ABD';
UPDATE employee_att_daily_overrides SET override_reason_code = 'EMP_RESIGNED' WHERE override_reason_code = 'ERC';

DELETE FROM attendance_no_punch_reasons WHERE reason_code IN ('ABD', 'ERC');

/* Governance seed */
INSERT INTO attendance_no_punch_reasons (
  reason_code, reason_text, override_work_hours, override_work_code,
  visible_to_timekeeper, visible_to_campboss, auto_escalate, requires_transfer_project,
  is_active, default_behavior, default_work_code
) VALUES
  ('COMP_OFF', 'Compensatory Off', NULL, NULL, 1, 0, 0, 0, 1, 'FULL_DAY', NULL),
  ('MISS_PUNCH', 'Miss Punch', NULL, NULL, 1, 0, 0, 0, 1, 'FULL_DAY', NULL),
  ('NIGHT_DAY_SHIFT', 'Night Day Shift', NULL, NULL, 1, 0, 0, 0, 1, 'NONE', NULL),
  ('NIGHT_SHIFT', 'Night Shift', NULL, NULL, 1, 0, 0, 0, 1, 'FULL_DAY', NULL),
  ('NO_LUNCH', 'No Lunch', NULL, NULL, 1, 0, 0, 0, 1, 'FULL_DAY_PLUS_1H', NULL),
  ('TIME_INCORRECT', 'Time Captured Incorrectly', NULL, NULL, 1, 0, 0, 0, 1, 'FULL_DAY', NULL),
  ('MED', 'Medical Visit', NULL, NULL, 0, 1, 0, 0, 1, 'FULL_DAY', NULL),
  ('TRAN_CAMP', 'Employee transfered to another camp', NULL, NULL, 0, 1, 0, 1, 1, 'NONE', NULL),
  ('OTH', 'Others', NULL, NULL, 1, 1, 0, 0, 1, 'NONE', NULL),
  ('VISA', 'Visa Related', NULL, NULL, 1, 1, 0, 0, 1, 'FULL_DAY', NULL),
  ('EMP_ABSCONDING', 'Employee Abduction', NULL, NULL, 1, 1, 1, 0, 1, 'NONE', NULL),
  ('EMP_RESIGNED', 'Employee Resigned Company', NULL, NULL, 1, 1, 1, 0, 1, 'NONE', NULL),
  ('NOT_IN_CAMP', 'Not in Camp', NULL, NULL, 0, 1, 1, 0, 1, 'NONE', NULL),
  ('SICK', 'Sick Leave', NULL, NULL, 1, 1, 0, 0, 1, 'WORK_CODE', 'SIC')
ON DUPLICATE KEY UPDATE
  reason_text = VALUES(reason_text),
  override_work_hours = VALUES(override_work_hours),
  override_work_code = VALUES(override_work_code),
  visible_to_timekeeper = VALUES(visible_to_timekeeper),
  visible_to_campboss = VALUES(visible_to_campboss),
  auto_escalate = VALUES(auto_escalate),
  requires_transfer_project = VALUES(requires_transfer_project),
  is_active = VALUES(is_active),
  default_behavior = VALUES(default_behavior),
  default_work_code = VALUES(default_work_code);

/* Ensure SICK default code exists in work type master */
INSERT INTO work_type_master (wt_cd, wt_desc)
VALUES ('SIC', 'Sick Leave')
ON DUPLICATE KEY UPDATE
  wt_desc = VALUES(wt_desc);

/* ============================================================
   2) attendance_no_punch_reviews: transfer + auto-escalate flags
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
  `transfer_to_project_code` varchar(20) DEFAULT NULL,
  `transfer_to_project_name` varchar(200) DEFAULT NULL,
  `auto_escalated_reason` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`emp_code`,`att_date`),
  KEY `idx_campboss_reason` (`campboss_reason_code`),
  KEY `idx_escalated` (`is_escalated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'attendance_no_punch_reviews' AND column_name = 'transfer_to_project_code'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE attendance_no_punch_reviews ADD COLUMN transfer_to_project_code varchar(20) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'attendance_no_punch_reviews' AND column_name = 'transfer_to_project_name'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE attendance_no_punch_reviews ADD COLUMN transfer_to_project_name varchar(200) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'attendance_no_punch_reviews' AND column_name = 'auto_escalated_reason'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE attendance_no_punch_reviews ADD COLUMN auto_escalated_reason tinyint(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'attendance_no_punch_reviews' AND index_name = 'idx_transfer_to_project'
);
SET @sql := IF(@idx = 0, 'CREATE INDEX idx_transfer_to_project ON attendance_no_punch_reviews (transfer_to_project_code)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
