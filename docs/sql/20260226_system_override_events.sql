-- Migration: Replace legacy PHP system override cron with DB procedures/events
-- Date: 2026-02-26
--
-- Target database: gcc_attendance_master
--
-- Usage (PowerShell):
--   Get-Content docs\sql\20260226_system_override_events.sql -Raw | mysql -u root gcc_attendance_master
--

USE `gcc_attendance_master`;

/* ============================================================
   1) Config + supporting tables/indexes
   ============================================================ */

CREATE TABLE IF NOT EXISTS `api_config` (
  `config_key` varchar(100) NOT NULL,
  `config_value` varchar(255) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `api_config` (`config_key`, `config_value`) VALUES
  ('system_override_php_enabled', '0'),
  ('system_override_db_enabled', '1'),
  ('system_override_db_hours_enabled', '1'),
  ('system_override_db_sunday_enabled', '1'),
  ('system_override_lookback_days', '60');

CREATE TABLE IF NOT EXISTS `attendance_override_notes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `emp_code` varchar(10) NOT NULL,
  `att_date` date NOT NULL,
  `work_hours` decimal(9,2) DEFAULT NULL,
  `work_code` varchar(10) DEFAULT NULL,
  `reason_code` varchar(50) DEFAULT NULL,
  `reason_note` varchar(255) DEFAULT NULL,
  `changed_by_email` varchar(255) DEFAULT NULL,
  `changed_by_name` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_emp_date` (`emp_code`, `att_date`),
  KEY `idx_reason_code` (`reason_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = 'gcc_attendance_master'
    AND table_name = 'employee_att_daily'
    AND index_name = 'idx_emp_att_date'
);
SET @idx_sql := IF(
  @idx_exists = 0,
  'CREATE INDEX `idx_emp_att_date` ON `gcc_attendance_master`.`employee_att_daily` (`emp_code`, `att_date`)',
  'SELECT 1'
);
PREPARE stmt FROM @idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_punch_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = 'gcc_attendance_master'
    AND table_name = 'employee_daily_punch'
    AND index_name = 'idx_punch_date_emp'
);
SET @idx_punch_sql := IF(
  @idx_punch_exists = 0,
  'CREATE INDEX `idx_punch_date_emp` ON `gcc_attendance_master`.`employee_daily_punch` (`punch_date`, `emp_code`)',
  'SELECT 1'
);
PREPARE stmt FROM @idx_punch_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

/* ============================================================
   2) Stored procedures
   ============================================================ */

DROP PROCEDURE IF EXISTS `sp_system_override_hours`;
DROP PROCEDURE IF EXISTS `sp_system_override_sunday`;
DROP PROCEDURE IF EXISTS `sp_system_override_run`;

DELIMITER $$

CREATE PROCEDURE `sp_system_override_hours`(IN p_start_date DATE, IN p_end_date DATE)
BEGIN
  DECLARE v_start DATE;
  DECLARE v_end DATE;
  DECLARE v_swap DATE;
  DECLARE v_change_dt DATETIME;

  SET v_start = IFNULL(p_start_date, CURDATE());
  SET v_end = IFNULL(p_end_date, CURDATE());
  IF v_start > v_end THEN
    SET v_swap = v_start;
    SET v_start = v_end;
    SET v_end = v_swap;
  END IF;

  SET v_change_dt = UTC_TIMESTAMP();

  DROP TEMPORARY TABLE IF EXISTS `tmp_system_override_hours`;
  CREATE TEMPORARY TABLE `tmp_system_override_hours` (
    `emp_code` varchar(10) NOT NULL,
    `att_date` date NOT NULL,
    `override_work_hours` decimal(9,2) DEFAULT NULL,
    `override_work_code` varchar(10) DEFAULT NULL,
    `reason_code` varchar(50) NOT NULL,
    `reason_note` varchar(255) NOT NULL,
    PRIMARY KEY (`emp_code`, `att_date`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  -- AUTO_8H_STAFF: ty_cd=01, at least one punch, no override row, work_code empty/SUB.
  INSERT IGNORE INTO `tmp_system_override_hours`
    (`emp_code`, `att_date`, `override_work_hours`, `override_work_code`, `reason_code`, `reason_note`)
  SELECT
    dp.emp_code,
    dp.punch_date,
    8.00,
    NULL,
    'AUTO_8H_STAFF',
    'AUTO_8H_STAFF: STAFF has at least one punch; set 8 hours'
  FROM `gcc_attendance_master`.`employee_daily_punch` dp
  INNER JOIN `gcc_attendance_master`.`hrmsvw_sync` hr
    ON hr.emp_code COLLATE utf8mb4_general_ci = dp.emp_code COLLATE utf8mb4_general_ci
  LEFT JOIN `gcc_attendance_master`.`employee_att_daily_overrides` o
    ON o.emp_code COLLATE utf8mb4_general_ci = dp.emp_code COLLATE utf8mb4_general_ci
    AND o.att_date = dp.punch_date
  LEFT JOIN `gcc_attendance_master`.`employee_att_daily` d
    ON d.emp_code COLLATE utf8mb4_general_ci = dp.emp_code COLLATE utf8mb4_general_ci
    AND d.att_date = dp.punch_date
  WHERE dp.punch_date BETWEEN v_start AND v_end
    AND hr.ty_cd = '01'
    AND o.emp_code IS NULL
    AND (d.work_code IS NULL OR TRIM(d.work_code) = '' OR UPPER(TRIM(d.work_code)) = 'SUB')
    AND (
      (dp.first_log IS NOT NULL AND dp.first_log <> '0000-00-00 00:00:00')
      OR
      (dp.last_log IS NOT NULL AND dp.last_log <> '0000-00-00 00:00:00')
    );

  -- AUTO_10H_NON_STAFF: ty_cd=02, at least one punch, no override row, work_code empty/SUB.
  INSERT IGNORE INTO `tmp_system_override_hours`
    (`emp_code`, `att_date`, `override_work_hours`, `override_work_code`, `reason_code`, `reason_note`)
  SELECT
    dp.emp_code,
    dp.punch_date,
    10.00,
    NULL,
    'AUTO_10H_NON_STAFF',
    'AUTO_10H_NON_STAFF: NON STAFF has at least one punch; set 10 hours'
  FROM `gcc_attendance_master`.`employee_daily_punch` dp
  INNER JOIN `gcc_attendance_master`.`hrmsvw_sync` hr
    ON hr.emp_code COLLATE utf8mb4_general_ci = dp.emp_code COLLATE utf8mb4_general_ci
  LEFT JOIN `gcc_attendance_master`.`employee_att_daily_overrides` o
    ON o.emp_code COLLATE utf8mb4_general_ci = dp.emp_code COLLATE utf8mb4_general_ci
    AND o.att_date = dp.punch_date
  LEFT JOIN `gcc_attendance_master`.`employee_att_daily` d
    ON d.emp_code COLLATE utf8mb4_general_ci = dp.emp_code COLLATE utf8mb4_general_ci
    AND d.att_date = dp.punch_date
  WHERE dp.punch_date BETWEEN v_start AND v_end
    AND hr.ty_cd = '02'
    AND o.emp_code IS NULL
    AND (d.work_code IS NULL OR TRIM(d.work_code) = '' OR UPPER(TRIM(d.work_code)) = 'SUB')
    AND (
      (dp.first_log IS NOT NULL AND dp.first_log <> '0000-00-00 00:00:00')
      OR
      (dp.last_log IS NOT NULL AND dp.last_log <> '0000-00-00 00:00:00')
    );

  -- OT_ELG_EMPLOYEE_9_12: ty_cd in (02,03), both punches, duration 9h..12h, no override row.
  INSERT IGNORE INTO `tmp_system_override_hours`
    (`emp_code`, `att_date`, `override_work_hours`, `override_work_code`, `reason_code`, `reason_note`)
  SELECT
    dp.emp_code,
    dp.punch_date,
    10.00,
    NULL,
    'OT_ELG_EMPLOYEE_9_12',
    'OT_ELG_EMPLOYEE_9_12: duration 9-12h; set 10 hours'
  FROM `gcc_attendance_master`.`employee_daily_punch` dp
  INNER JOIN `gcc_attendance_master`.`hrmsvw_sync` hr
    ON hr.emp_code COLLATE utf8mb4_general_ci = dp.emp_code COLLATE utf8mb4_general_ci
  LEFT JOIN `gcc_attendance_master`.`employee_att_daily_overrides` o
    ON o.emp_code COLLATE utf8mb4_general_ci = dp.emp_code COLLATE utf8mb4_general_ci
    AND o.att_date = dp.punch_date
  LEFT JOIN `gcc_attendance_master`.`employee_att_daily` d
    ON d.emp_code COLLATE utf8mb4_general_ci = dp.emp_code COLLATE utf8mb4_general_ci
    AND d.att_date = dp.punch_date
  WHERE dp.punch_date BETWEEN v_start AND v_end
    AND hr.ty_cd IN ('02', '03')
    AND o.emp_code IS NULL
    AND (d.work_code IS NULL OR TRIM(d.work_code) = '' OR UPPER(TRIM(d.work_code)) = 'SUB')
    AND (dp.first_log IS NOT NULL AND dp.first_log <> '0000-00-00 00:00:00')
    AND (dp.last_log IS NOT NULL AND dp.last_log <> '0000-00-00 00:00:00')
    AND TIMESTAMPDIFF(SECOND, dp.first_log, dp.last_log) >= 32400
    AND TIMESTAMPDIFF(SECOND, dp.first_log, dp.last_log) < 43200;

  INSERT IGNORE INTO `gcc_attendance_master`.`employee_att_daily_overrides`
    (
      `emp_code`,
      `att_date`,
      `override_work_hours`,
      `override_work_code`,
      `override_change_date`,
      `override_changed_by_email`,
      `override_changed_by_name`,
      `override_is_approved`,
      `override_approved_by_email`,
      `override_approved_by_name`,
      `override_approved_date`
    )
  SELECT
    t.emp_code,
    t.att_date,
    t.override_work_hours,
    t.override_work_code,
    v_change_dt,
    'SYSTEM',
    'SYSTEM',
    1,
    'SYSTEM',
    'SYSTEM',
    v_change_dt
  FROM `tmp_system_override_hours` t;

  INSERT INTO `gcc_attendance_master`.`attendance_override_notes`
    (
      `emp_code`,
      `att_date`,
      `work_hours`,
      `work_code`,
      `reason_code`,
      `reason_note`,
      `changed_by_email`,
      `changed_by_name`
    )
  SELECT
    t.emp_code,
    t.att_date,
    t.override_work_hours,
    t.override_work_code,
    t.reason_code,
    t.reason_note,
    'SYSTEM',
    'SYSTEM'
  FROM `tmp_system_override_hours` t
  INNER JOIN `gcc_attendance_master`.`employee_att_daily_overrides` o
    ON o.emp_code = t.emp_code
    AND o.att_date = t.att_date
  WHERE o.override_change_date = v_change_dt
    AND COALESCE(o.override_changed_by_email, '') = 'SYSTEM'
    AND (o.override_work_hours <=> t.override_work_hours)
    AND (o.override_work_code <=> t.override_work_code)
    AND NOT EXISTS (
      SELECT 1
      FROM `gcc_attendance_master`.`attendance_override_notes` n
      WHERE n.emp_code = t.emp_code
        AND n.att_date = t.att_date
        AND COALESCE(n.reason_code, '') = t.reason_code
    );

  DROP TEMPORARY TABLE IF EXISTS `tmp_system_override_hours`;
END$$

CREATE PROCEDURE `sp_system_override_sunday`(IN p_start_date DATE, IN p_end_date DATE)
BEGIN
  DECLARE v_start DATE;
  DECLARE v_end DATE;
  DECLARE v_swap DATE;
  DECLARE v_ext_start DATE;
  DECLARE v_ext_end DATE;
  DECLARE v_change_dt DATETIME;

  SET v_start = IFNULL(p_start_date, CURDATE());
  SET v_end = IFNULL(p_end_date, CURDATE());
  IF v_start > v_end THEN
    SET v_swap = v_start;
    SET v_start = v_end;
    SET v_end = v_swap;
  END IF;

  SET v_ext_start = DATE_SUB(v_start, INTERVAL 14 DAY);
  SET v_ext_end = DATE_ADD(v_end, INTERVAL 14 DAY);
  SET v_change_dt = UTC_TIMESTAMP();

  DROP TEMPORARY TABLE IF EXISTS `tmp_system_override_sunday`;
  CREATE TEMPORARY TABLE `tmp_system_override_sunday` (
    `emp_code` varchar(10) NOT NULL,
    `att_date` date NOT NULL,
    `new_code` varchar(10) NOT NULL,
    `prev_norm` varchar(10) NOT NULL,
    `next_norm` varchar(10) NOT NULL,
    PRIMARY KEY (`emp_code`, `att_date`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  INSERT INTO `tmp_system_override_sunday`
    (`emp_code`, `att_date`, `new_code`, `prev_norm`, `next_norm`)
  SELECT
    src.emp_code,
    src.att_date,
    CASE
      WHEN src.prev_norm <> '' AND src.prev_norm = src.next_norm THEN src.prev_norm
      ELSE 'HOL'
    END AS new_code,
    src.prev_norm,
    src.next_norm
  FROM (
    SELECT
      d.emp_code,
      d.att_date,
      UPPER(TRIM(COALESCE(prev_row.work_code, ''))) AS prev_norm,
      UPPER(TRIM(COALESCE(next_row.work_code, ''))) AS next_norm
    FROM `gcc_attendance_master`.`employee_att_daily` d
    LEFT JOIN `gcc_attendance_master`.`employee_att_daily_overrides` o
      ON o.emp_code COLLATE utf8mb4_general_ci = d.emp_code COLLATE utf8mb4_general_ci
      AND o.att_date = d.att_date
    LEFT JOIN `gcc_attendance_master`.`employee_att_daily` prev_row
      ON prev_row.emp_code COLLATE utf8mb4_general_ci = d.emp_code COLLATE utf8mb4_general_ci
      AND prev_row.att_date = (
        SELECT MAX(p.att_date)
        FROM `gcc_attendance_master`.`employee_att_daily` p
        WHERE p.emp_code COLLATE utf8mb4_general_ci = d.emp_code COLLATE utf8mb4_general_ci
          AND p.att_date BETWEEN v_ext_start AND v_ext_end
          AND p.att_date < d.att_date
          AND DAYOFWEEK(p.att_date) <> 1
          AND UPPER(TRIM(COALESCE(p.work_code, ''))) <> 'PHL'
      )
    LEFT JOIN `gcc_attendance_master`.`employee_att_daily` next_row
      ON next_row.emp_code COLLATE utf8mb4_general_ci = d.emp_code COLLATE utf8mb4_general_ci
      AND next_row.att_date = (
        SELECT MIN(n.att_date)
        FROM `gcc_attendance_master`.`employee_att_daily` n
        WHERE n.emp_code COLLATE utf8mb4_general_ci = d.emp_code COLLATE utf8mb4_general_ci
          AND n.att_date BETWEEN v_ext_start AND v_ext_end
          AND n.att_date > d.att_date
          AND DAYOFWEEK(n.att_date) <> 1
          AND UPPER(TRIM(COALESCE(n.work_code, ''))) <> 'PHL'
      )
    WHERE d.att_date BETWEEN v_start AND v_end
      AND DAYOFWEEK(d.att_date) = 1
      AND TRIM(COALESCE(d.work_code, '')) = ''
      AND o.emp_code IS NULL
  ) AS src;

  INSERT IGNORE INTO `gcc_attendance_master`.`employee_att_daily_overrides`
    (
      `emp_code`,
      `att_date`,
      `override_work_hours`,
      `override_work_code`,
      `override_change_date`,
      `override_changed_by_email`,
      `override_changed_by_name`,
      `override_is_approved`,
      `override_approved_by_email`,
      `override_approved_by_name`,
      `override_approved_date`
    )
  SELECT
    t.emp_code,
    t.att_date,
    NULL,
    t.new_code,
    v_change_dt,
    'SYSTEM',
    'SYSTEM',
    1,
    'SYSTEM',
    'SYSTEM',
    v_change_dt
  FROM `tmp_system_override_sunday` t;

  INSERT INTO `gcc_attendance_master`.`attendance_override_notes`
    (
      `emp_code`,
      `att_date`,
      `work_hours`,
      `work_code`,
      `reason_code`,
      `reason_note`,
      `changed_by_email`,
      `changed_by_name`
    )
  SELECT
    t.emp_code,
    t.att_date,
    NULL,
    t.new_code,
    'AUTO_SUN_WORK_CODE',
    CONCAT(
      'AUTO_SUN_WORK_CODE: Sunday empty; prev=',
      t.prev_norm,
      '; next=',
      t.next_norm,
      '; set=',
      t.new_code
    ),
    'SYSTEM',
    'SYSTEM'
  FROM `tmp_system_override_sunday` t
  INNER JOIN `gcc_attendance_master`.`employee_att_daily_overrides` o
    ON o.emp_code = t.emp_code
    AND o.att_date = t.att_date
  WHERE o.override_change_date = v_change_dt
    AND COALESCE(o.override_changed_by_email, '') = 'SYSTEM'
    AND (o.override_work_hours IS NULL)
    AND (o.override_work_code <=> t.new_code)
    AND NOT EXISTS (
      SELECT 1
      FROM `gcc_attendance_master`.`attendance_override_notes` n
      WHERE n.emp_code = t.emp_code
        AND n.att_date = t.att_date
        AND COALESCE(n.reason_code, '') = 'AUTO_SUN_WORK_CODE'
    );

  DROP TEMPORARY TABLE IF EXISTS `tmp_system_override_sunday`;
END$$

CREATE PROCEDURE `sp_system_override_run`()
proc: BEGIN
  DECLARE v_db_enabled VARCHAR(16) DEFAULT '1';
  DECLARE v_hours_enabled VARCHAR(16) DEFAULT '1';
  DECLARE v_sunday_enabled VARCHAR(16) DEFAULT '1';
  DECLARE v_lookback INT DEFAULT 60;
  DECLARE v_start DATE;
  DECLARE v_end DATE;
  DECLARE v_day DATE;
  DECLARE v_lock_name VARCHAR(128) DEFAULT 'gcc_attendance_master.sp_system_override_run';
  DECLARE v_lock_acquired INT DEFAULT 0;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    IF v_lock_acquired = 1 THEN
      DO RELEASE_LOCK(v_lock_name);
    END IF;
    RESIGNAL;
  END;

  SELECT GET_LOCK(v_lock_name, 0) INTO v_lock_acquired;
  IF v_lock_acquired <> 1 THEN
    LEAVE proc;
  END IF;

  SET v_db_enabled = COALESCE(
    NULLIF(TRIM((SELECT config_value FROM `gcc_attendance_master`.`api_config` WHERE config_key = 'system_override_db_enabled' LIMIT 1)), ''),
    '1'
  );
  IF v_db_enabled <> '1' THEN
    DO RELEASE_LOCK(v_lock_name);
    LEAVE proc;
  END IF;

  SET v_hours_enabled = COALESCE(
    NULLIF(TRIM((SELECT config_value FROM `gcc_attendance_master`.`api_config` WHERE config_key = 'system_override_db_hours_enabled' LIMIT 1)), ''),
    '1'
  );
  SET v_sunday_enabled = COALESCE(
    NULLIF(TRIM((SELECT config_value FROM `gcc_attendance_master`.`api_config` WHERE config_key = 'system_override_db_sunday_enabled' LIMIT 1)), ''),
    '1'
  );
  SET v_lookback = CAST(
    COALESCE(
      NULLIF(TRIM((SELECT config_value FROM `gcc_attendance_master`.`api_config` WHERE config_key = 'system_override_lookback_days' LIMIT 1)), ''),
      '60'
    ) AS UNSIGNED
  );

  IF v_lookback IS NULL OR v_lookback < 1 THEN
    SET v_lookback = 60;
  END IF;

  SET v_end = CURDATE();
  SET v_start = DATE_SUB(v_end, INTERVAL (v_lookback - 1) DAY);
  SET v_day = v_start;

  WHILE v_day <= v_end DO
    IF v_hours_enabled = '1' THEN
      CALL `sp_system_override_hours`(v_day, v_day);
    END IF;
    IF v_sunday_enabled = '1' AND DAYOFWEEK(v_day) = 1 THEN
      CALL `sp_system_override_sunday`(v_day, v_day);
    END IF;
    SET v_day = DATE_ADD(v_day, INTERVAL 1 DAY);
  END WHILE;

  DO RELEASE_LOCK(v_lock_name);
END proc$$

DELIMITER ;

/* ============================================================
   3) Event scheduler job
   ============================================================ */

DROP EVENT IF EXISTS `ev_system_override_runner`;

CREATE EVENT `ev_system_override_runner`
  ON SCHEDULE EVERY 15 MINUTE
  STARTS (CURRENT_TIMESTAMP + INTERVAL 1 MINUTE)
  DO
    CALL `sp_system_override_run`();

ALTER EVENT `ev_system_override_runner` ENABLE;

-- Runtime enable (manual, one-time):
--   SET GLOBAL event_scheduler = ON;
--
-- Persist across MySQL restart:
--   add `event_scheduler=ON` in C:\xampp\mysql\bin\my.ini
