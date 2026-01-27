-- Migration: create utime_employee_details table
-- Date: 2026-01-27

CREATE TABLE IF NOT EXISTS utime_employee_details (
  emp_code varchar(20) NOT NULL,
  change_id bigint NOT NULL,
  employee_name varchar(200) NULL,
  company_name varchar(200) NULL,
  department_name varchar(200) NULL,
  designation_name varchar(200) NULL,
  doj datetime NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  is_deleted tinyint(1) NOT NULL DEFAULT 0,
  change_type char(1) NOT NULL,
  changed_at datetime NULL,
  received_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
