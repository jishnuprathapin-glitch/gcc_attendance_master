-- Migration: make employee_daily_punch_sync append-only and upsert into employee_daily_punch
-- Date: 2026-01-27
-- Notes:
-- - Sync table records every request (no uniqueness enforcement).
-- - Main table keeps one row per employee per day.

-- 1) Sync table: remove unique constraints and ensure a non-emp_date primary key.
--    Safe checks avoid failure if the PK/index already changed.
SET @pk_cols := (
  SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position)
  FROM information_schema.key_column_usage
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_daily_punch_sync'
    AND constraint_name = 'PRIMARY'
);
SET @sql := IF(@pk_cols IN ('emp_code,punch_date', 'punch_date,emp_code'),
  'ALTER TABLE employee_daily_punch_sync DROP PRIMARY KEY',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_uniq := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_daily_punch_sync'
    AND index_name = 'uniq_emp_date'
);
SET @sql := IF(@has_uniq > 0, 'ALTER TABLE employee_daily_punch_sync DROP INDEX uniq_emp_date', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ai_col := (
  SELECT column_name
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_daily_punch_sync'
    AND extra LIKE '%auto_increment%'
  LIMIT 1
);
SET @has_pk := (
  SELECT COUNT(*)
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_daily_punch_sync'
    AND constraint_type = 'PRIMARY KEY'
);
SET @has_id := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_daily_punch_sync'
    AND column_name = 'id'
);
SET @sql := IF(@has_pk = 0,
  IF(@ai_col IS NOT NULL,
    CONCAT('ALTER TABLE employee_daily_punch_sync ADD PRIMARY KEY (`', @ai_col, '`)'),
    IF(@has_id = 1,
      'ALTER TABLE employee_daily_punch_sync MODIFY COLUMN `id` BIGINT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (`id`)',
      'ALTER TABLE employee_daily_punch_sync ADD COLUMN `id` BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST'
    )
  ),
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_daily_punch_sync'
    AND index_name = 'idx_emp_date'
);
SET @sql := IF(@has_idx = 0, 'ALTER TABLE employee_daily_punch_sync ADD INDEX idx_emp_date (emp_code, punch_date)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Main table: auto-increment change_id (only if no other AUTO_INCREMENT) and
--    enforce one row per employee per day.
SET @main_ai_col := (
  SELECT column_name
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_daily_punch'
    AND extra LIKE '%auto_increment%'
  LIMIT 1
);
SET @has_change_id_col := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_daily_punch'
    AND column_name = 'change_id'
);
SET @has_change_id_idx := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_daily_punch'
    AND column_name = 'change_id'
);
SET @sql := IF(@main_ai_col IS NULL AND @has_change_id_col > 0,
  IF(@has_change_id_idx = 0,
    'ALTER TABLE employee_daily_punch ADD INDEX idx_change_id (change_id), MODIFY change_id BIGINT NOT NULL AUTO_INCREMENT',
    'ALTER TABLE employee_daily_punch MODIFY change_id BIGINT NOT NULL AUTO_INCREMENT'
  ),
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_main_uniq := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_daily_punch'
    AND index_name = 'uniq_emp_date'
);
SET @sql := IF(@has_main_uniq = 0, 'ALTER TABLE employee_daily_punch ADD UNIQUE KEY uniq_emp_date (emp_code, punch_date)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Trigger: apply sync rows into the main table.
DROP TRIGGER IF EXISTS trg_employee_daily_punch_sync_ai;
DELIMITER //
CREATE TRIGGER trg_employee_daily_punch_sync_ai
AFTER INSERT ON employee_daily_punch_sync
FOR EACH ROW
BEGIN
  DECLARE v_change_type CHAR(1);
  DECLARE v_raw_change_type VARCHAR(10);

  SET v_raw_change_type = LOWER(IFNULL(NEW.change_type, ''));
  IF NEW.is_deleted = 1 OR v_raw_change_type = 'delete' THEN
    DELETE FROM employee_daily_punch
    WHERE emp_code = NEW.emp_code AND punch_date = NEW.punch_date;
  ELSE
    IF v_raw_change_type IN ('insert', 'update', 'upsert') THEN
      SET v_change_type = UPPER(LEFT(v_raw_change_type, 1));
    ELSEIF v_raw_change_type = '' THEN
      SET v_change_type = 'U';
    ELSE
      SET v_change_type = UPPER(LEFT(v_raw_change_type, 1));
    END IF;

    INSERT INTO employee_daily_punch (
      emp_code,
      punch_date,
      first_log,
      last_log,
      first_terminal_sn,
      last_terminal_sn,
      change_type,
      changed_at
    ) VALUES (
      NEW.emp_code,
      NEW.punch_date,
      NEW.first_log,
      NEW.last_log,
      NEW.first_terminal_sn,
      NEW.last_terminal_sn,
      v_change_type,
      NEW.updated_at
    )
    ON DUPLICATE KEY UPDATE
      first_log = VALUES(first_log),
      last_log = VALUES(last_log),
      first_terminal_sn = VALUES(first_terminal_sn),
      last_terminal_sn = VALUES(last_terminal_sn),
      change_type = VALUES(change_type),
      changed_at = VALUES(changed_at);
  END IF;
END//
DELIMITER ;
