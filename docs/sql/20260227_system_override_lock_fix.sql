-- Hotfix: reduce lock pressure for DB system override runner
-- Date: 2026-02-27
--
-- Purpose:
-- 1) Avoid very large lock sets by processing one day at a time.
-- 2) Prevent overlapping runs via advisory lock.
-- 3) Add punch-date index used by override queries.
--
-- Usage:
--   mysql -u root gcc_attendance_master < docs/sql/20260227_system_override_lock_fix.sql

USE `gcc_attendance_master`;

/* ============================================================
   1) Supporting index
   ============================================================ */
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
   2) Recreate runner procedure with batching + lock
   ============================================================ */
DROP PROCEDURE IF EXISTS `sp_system_override_run`;

DELIMITER $$
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

/* Optional: lower runtime pressure by reducing lookback window */
-- UPDATE `gcc_attendance_master`.`api_config`
-- SET config_value = '7'
-- WHERE config_key = 'system_override_lookback_days';

