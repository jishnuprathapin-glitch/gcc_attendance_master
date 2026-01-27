-- Migration: Employee sync tables
-- Date: 2026-01-27
-- Notes:
-- - Creates tables for EmployeeDetails and EmployeeDailyPunch sync payloads.

USE `gcc_attendance_master`;

CREATE TABLE IF NOT EXISTS `employee_details` (
  `change_id` bigint NOT NULL,
  `emp_code` varchar(20) NOT NULL,
  `employee_name` varchar(200) NULL,
  `company_name` varchar(200) NULL,
  `department_name` varchar(200) NULL,
  `designation_name` varchar(200) NULL,
  `doj` datetime NULL,
  `is_active` tinyint(1) NOT NULL,
  `is_deleted` tinyint(1) NOT NULL,
  `change_type` char(1) NULL,
  `changed_at` datetime NULL,
  `received_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`change_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `employee_daily_punch` (
  `change_id` bigint NOT NULL,
  `emp_code` varchar(20) NOT NULL,
  `punch_date` date NOT NULL,
  `first_log` datetime NULL,
  `last_log` datetime NULL,
  `first_terminal_sn` varchar(50) NULL,
  `last_terminal_sn` varchar(50) NULL,
  `change_type` char(1) NULL,
  `changed_at` datetime NULL,
  `received_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`change_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
