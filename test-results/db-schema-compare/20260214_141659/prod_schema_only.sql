-- Extracted schema only from: gcc_attendance_master.sql
-- NOTE: Data INSERT/REPLACE statements were removed.

-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 14, 2026 at 10:37 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gcc_attendance_master`
--

-- --------------------------------------------------------

--
-- Table structure for table `api_config`
--

CREATE TABLE `api_config` (
  `config_key` varchar(100) NOT NULL,
  `config_value` varchar(255) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `attendance_no_punch_reasons`
--

CREATE TABLE `attendance_no_punch_reasons` (
  `reason_code` varchar(20) NOT NULL,
  `reason_text` varchar(100) NOT NULL,
  `override_work_hours` decimal(9,2) DEFAULT NULL,
  `override_work_code` varchar(10) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_no_punch_reviews`
--

CREATE TABLE `attendance_no_punch_reviews` (
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
  `escalated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_override_notes`
--

CREATE TABLE `attendance_override_notes` (
  `id` int(11) NOT NULL,
  `emp_code` varchar(10) NOT NULL,
  `att_date` date NOT NULL,
  `work_hours` decimal(9,2) DEFAULT NULL,
  `work_code` varchar(10) DEFAULT NULL,
  `reason_code` varchar(50) DEFAULT NULL,
  `reason_note` varchar(255) DEFAULT NULL,
  `changed_by_email` varchar(255) DEFAULT NULL,
  `changed_by_name` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `device_project_map`
--

CREATE TABLE `device_project_map` (
  `id` int(11) NOT NULL,
  `device_sn` varchar(64) NOT NULL,
  `device_name` varchar(255) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


--
-- Triggers `device_project_map`
--
DELIMITER $$
CREATE TRIGGER `trg_device_project_map_ai` AFTER INSERT ON `device_project_map` FOR EACH ROW BEGIN
  INSERT INTO gcc_attendance_master.device_project_map_history (
    device_sn,
    device_name,
    project_id,
    changed_by,
    change_reason,
    valid_from,
    valid_to,
    created_at
  ) VALUES (
    NEW.device_sn,
    NEW.device_name,
    NEW.project_id,
    @device_project_changed_by,
    @device_project_change_reason,
    COALESCE(NEW.created_at, CURRENT_TIMESTAMP),
    NULL,
    COALESCE(NEW.created_at, CURRENT_TIMESTAMP)
  );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_device_project_map_au` AFTER UPDATE ON `device_project_map` FOR EACH ROW BEGIN
  IF (NOT (OLD.project_id <=> NEW.project_id))
     OR (NOT (OLD.device_name <=> NEW.device_name)) THEN
    UPDATE gcc_attendance_master.device_project_map_history
    SET valid_to = COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)
    WHERE device_sn = NEW.device_sn
      AND valid_to IS NULL;

    INSERT INTO gcc_attendance_master.device_project_map_history (
      device_sn,
      device_name,
      project_id,
      changed_by,
      change_reason,
      valid_from,
      valid_to,
      created_at
    ) VALUES (
      NEW.device_sn,
      NEW.device_name,
      NEW.project_id,
      @device_project_changed_by,
      @device_project_change_reason,
      COALESCE(NEW.updated_at, CURRENT_TIMESTAMP),
      NULL,
      COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)
    );
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `device_project_map_history`
--

CREATE TABLE `device_project_map_history` (
  `id` int(11) NOT NULL,
  `device_sn` varchar(64) NOT NULL,
  `device_name` varchar(255) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `changed_by` varchar(128) DEFAULT NULL,
  `change_reason` varchar(255) DEFAULT NULL,
  `valid_from` timestamp NOT NULL DEFAULT current_timestamp(),
  `valid_to` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `employee_att_daily`
--

CREATE TABLE `employee_att_daily` (
  `change_id` bigint(20) NOT NULL,
  `emp_code` varchar(10) NOT NULL,
  `job` varchar(10) NOT NULL,
  `department_name` varchar(100) DEFAULT NULL,
  `company_shortname` varchar(20) DEFAULT NULL,
  `designation_name` varchar(100) DEFAULT NULL,
  `Projectcode_utime` varchar(10) DEFAULT NULL,
  `work_hours_utime` decimal(9,2) DEFAULT NULL,
  `att_date` date NOT NULL,
  `work_hours` decimal(9,2) DEFAULT NULL,
  `work_code` varchar(10) DEFAULT NULL,
  `pending_leave` tinyint(1) DEFAULT NULL,
  `pending_leave_code` varchar(10) DEFAULT NULL,
  `pending_leave_doc_no` varchar(20) DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT NULL,
  `change_type` varchar(10) DEFAULT NULL,
  `changed_at` datetime DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_delete` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `employee_att_daily_inbox`
--

CREATE TABLE `employee_att_daily_inbox` (
  `change_id` bigint(20) NOT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('applied','skipped','error') NOT NULL,
  `error_message` varchar(1024) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `employee_att_daily_overrides`
--

CREATE TABLE `employee_att_daily_overrides` (
  `emp_code` varchar(10) NOT NULL,
  `att_date` date NOT NULL,
  `override_work_hours` decimal(9,2) DEFAULT NULL,
  `override_work_code` varchar(10) DEFAULT NULL,
  `override_change_date` datetime DEFAULT NULL,
  `override_changed_by_email` varchar(255) DEFAULT NULL,
  `override_changed_by_name` varchar(100) DEFAULT NULL,
  `override_approved_by_email` varchar(255) DEFAULT NULL,
  `override_approved_by_name` varchar(100) DEFAULT NULL,
  `override_is_approved` tinyint(1) DEFAULT NULL,
  `override_approved_date` datetime DEFAULT NULL,
  `override_reason_code` varchar(20) DEFAULT NULL,
  `override_reason_note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `employee_daily_punch`
--

CREATE TABLE `employee_daily_punch` (
  `change_id` bigint(20) NOT NULL,
  `emp_code` varchar(20) NOT NULL,
  `punch_date` date NOT NULL,
  `first_log` datetime DEFAULT NULL,
  `last_log` datetime DEFAULT NULL,
  `first_terminal_sn` varchar(50) DEFAULT NULL,
  `last_terminal_sn` varchar(50) DEFAULT NULL,
  `change_type` char(1) DEFAULT NULL,
  `changed_at` datetime DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `employee_daily_punch_sync`
--

CREATE TABLE `employee_daily_punch_sync` (
  `id` bigint(20) NOT NULL,
  `emp_code` varchar(20) NOT NULL,
  `punch_date` date NOT NULL,
  `first_log` datetime DEFAULT NULL,
  `last_log` datetime DEFAULT NULL,
  `first_terminal_sn` varchar(50) DEFAULT NULL,
  `last_terminal_sn` varchar(50) DEFAULT NULL,
  `change_type` varchar(10) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


--
-- Triggers `employee_daily_punch_sync`
--
DELIMITER $$
CREATE TRIGGER `trg_employee_daily_punch_sync_ai` AFTER INSERT ON `employee_daily_punch_sync` FOR EACH ROW BEGIN
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
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `hrmsvw_sync`
--

CREATE TABLE `hrmsvw_sync` (
  `emp_code` varchar(20) NOT NULL,
  `br_cd` varchar(50) DEFAULT NULL,
  `br_desc` varchar(200) DEFAULT NULL,
  `lc_cd` varchar(50) DEFAULT NULL,
  `lc_desc` varchar(200) DEFAULT NULL,
  `div_cd` varchar(50) DEFAULT NULL,
  `div_desc` varchar(200) DEFAULT NULL,
  `cc_code` varchar(50) DEFAULT NULL,
  `cc_name` varchar(200) DEFAULT NULL,
  `sph_cd` varchar(50) DEFAULT NULL,
  `sph_name` varchar(200) DEFAULT NULL,
  `ty_cd` varchar(50) DEFAULT NULL,
  `ty_desc` varchar(200) DEFAULT NULL,
  `st_code` varchar(50) DEFAULT NULL,
  `st_desc` varchar(200) DEFAULT NULL,
  `dept_cd` varchar(50) DEFAULT NULL,
  `dept_name` varchar(200) DEFAULT NULL,
  `desg_cd` varchar(50) DEFAULT NULL,
  `desg_name` varchar(200) DEFAULT NULL,
  `tc_cd` varchar(50) DEFAULT NULL,
  `tc_desc` varchar(200) DEFAULT NULL,
  `grd_cd` varchar(50) DEFAULT NULL,
  `grd_desc` varchar(200) DEFAULT NULL,
  `cm_cd` varchar(50) DEFAULT NULL,
  `cm_desc` varchar(200) DEFAULT NULL,
  `code` varchar(50) DEFAULT NULL,
  `name` varchar(200) DEFAULT NULL,
  `cu_ccd` varchar(50) DEFAULT NULL,
  `cu_cname` varchar(200) DEFAULT NULL,
  `emp_name` varchar(200) DEFAULT NULL,
  `spg_id` varchar(50) DEFAULT NULL,
  `sph_group` varchar(200) DEFAULT NULL,
  `jbno` varchar(50) DEFAULT NULL,
  `jbdesc` varchar(200) DEFAULT NULL,
  `st_pay` tinyint(1) DEFAULT NULL,
  `emp_sex` varchar(20) DEFAULT NULL,
  `emp_nationality` varchar(100) DEFAULT NULL,
  `emp_dor` datetime DEFAULT NULL,
  `emp_doj` datetime DEFAULT NULL,
  `change_type` varchar(10) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `hrms_camp_inbox`
--

CREATE TABLE `hrms_camp_inbox` (
  `change_id` bigint(20) NOT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('applied','skipped','error') NOT NULL,
  `error_message` varchar(1024) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `hrms_camp_sync`
--

CREATE TABLE `hrms_camp_sync` (
  `camp_comp_cd` varchar(3) NOT NULL,
  `camp_code` varchar(3) NOT NULL,
  `camp_id` int(11) DEFAULT NULL,
  `camp_name` varchar(50) DEFAULT NULL,
  `camp_emirate` varchar(3) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `change_type` char(1) NOT NULL,
  `changed_at` datetime NOT NULL,
  `last_change_id` bigint(20) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `hrms_cost_centers`
--

CREATE TABLE `hrms_cost_centers` (
  `cc_code` varchar(50) NOT NULL,
  `cc_name` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `hrms_departments`
--

CREATE TABLE `hrms_departments` (
  `dept_cd` varchar(50) NOT NULL,
  `dept_name` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `hrms_designations`
--

CREATE TABLE `hrms_designations` (
  `desg_cd` varchar(50) NOT NULL,
  `desg_name` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `hrms_employee_types`
--

CREATE TABLE `hrms_employee_types` (
  `ty_cd` varchar(50) NOT NULL,
  `ty_desc` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `hrms_hrmemp_camp_mapping`
--

CREATE TABLE `hrms_hrmemp_camp_mapping` (
  `emp_compcd` varchar(3) NOT NULL,
  `emp_code` varchar(10) NOT NULL,
  `emp_camp_loc` varchar(10) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `change_type` char(1) NOT NULL,
  `changed_at` datetime NOT NULL,
  `last_change_id` bigint(20) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `hrms_hrmemp_inbox`
--

CREATE TABLE `hrms_hrmemp_inbox` (
  `change_id` bigint(20) NOT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('applied','skipped','error') NOT NULL,
  `error_message` varchar(1024) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `hrms_projects`
--

CREATE TABLE `hrms_projects` (
  `project_code` varchar(50) NOT NULL,
  `project_name` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `timekeeper_project_access_requests`
--

CREATE TABLE `timekeeper_project_access_requests` (
  `id` int(11) NOT NULL,
  `user_id` varchar(50) NOT NULL,
  `project_code` varchar(20) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reviewed_by` varchar(50) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `timekeeper_project_map`
--

CREATE TABLE `timekeeper_project_map` (
  `user_id` varchar(50) NOT NULL,
  `project_code` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `work_type_master`
--

CREATE TABLE `work_type_master` (
  `wt_cd` varchar(10) NOT NULL,
  `wt_desc` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


--
-- Indexes for dumped tables
--

--
-- Indexes for table `api_config`
--
ALTER TABLE `api_config`
  ADD PRIMARY KEY (`config_key`);

--
-- Indexes for table `attendance_no_punch_reasons`
--
ALTER TABLE `attendance_no_punch_reasons`
  ADD PRIMARY KEY (`reason_code`);

--
-- Indexes for table `attendance_no_punch_reviews`
--
ALTER TABLE `attendance_no_punch_reviews`
  ADD PRIMARY KEY (`emp_code`,`att_date`),
  ADD KEY `idx_campboss_reason` (`campboss_reason_code`),
  ADD KEY `idx_escalated` (`is_escalated`);

--
-- Indexes for table `attendance_override_notes`
--
ALTER TABLE `attendance_override_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_emp_date` (`emp_code`,`att_date`),
  ADD KEY `idx_reason_code` (`reason_code`);

--
-- Indexes for table `device_project_map`
--
ALTER TABLE `device_project_map`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_device_sn` (`device_sn`),
  ADD KEY `idx_project_id` (`project_id`),
  ADD KEY `idx_device_name` (`device_name`);

--
-- Indexes for table `device_project_map_history`
--
ALTER TABLE `device_project_map_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_history_device_sn` (`device_sn`),
  ADD KEY `idx_history_project_id` (`project_id`),
  ADD KEY `idx_history_valid_to` (`valid_to`);

--
-- Indexes for table `employee_att_daily`
--
ALTER TABLE `employee_att_daily`
  ADD PRIMARY KEY (`change_id`);

--
-- Indexes for table `employee_att_daily_inbox`
--
ALTER TABLE `employee_att_daily_inbox`
  ADD PRIMARY KEY (`change_id`);

--
-- Indexes for table `employee_att_daily_overrides`
--
ALTER TABLE `employee_att_daily_overrides`
  ADD PRIMARY KEY (`emp_code`,`att_date`);

--
-- Indexes for table `employee_daily_punch`
--
ALTER TABLE `employee_daily_punch`
  ADD PRIMARY KEY (`change_id`),
  ADD UNIQUE KEY `uniq_emp_date` (`emp_code`,`punch_date`);

--
-- Indexes for table `employee_daily_punch_sync`
--
ALTER TABLE `employee_daily_punch_sync`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_emp_date` (`emp_code`,`punch_date`);

--
-- Indexes for table `hrmsvw_sync`
--
ALTER TABLE `hrmsvw_sync`
  ADD PRIMARY KEY (`emp_code`);

--
-- Indexes for table `hrms_camp_inbox`
--
ALTER TABLE `hrms_camp_inbox`
  ADD PRIMARY KEY (`change_id`);

--
-- Indexes for table `hrms_camp_sync`
--
ALTER TABLE `hrms_camp_sync`
  ADD PRIMARY KEY (`camp_comp_cd`,`camp_code`),
  ADD KEY `idx_camp_changed_at` (`changed_at`),
  ADD KEY `idx_camp_last_change_id` (`last_change_id`);

--
-- Indexes for table `hrms_cost_centers`
--
ALTER TABLE `hrms_cost_centers`
  ADD PRIMARY KEY (`cc_code`);

--
-- Indexes for table `hrms_departments`
--
ALTER TABLE `hrms_departments`
  ADD PRIMARY KEY (`dept_cd`);

--
-- Indexes for table `hrms_designations`
--
ALTER TABLE `hrms_designations`
  ADD PRIMARY KEY (`desg_cd`);

--
-- Indexes for table `hrms_employee_types`
--
ALTER TABLE `hrms_employee_types`
  ADD PRIMARY KEY (`ty_cd`);

--
-- Indexes for table `hrms_hrmemp_camp_mapping`
--
ALTER TABLE `hrms_hrmemp_camp_mapping`
  ADD PRIMARY KEY (`emp_compcd`,`emp_code`),
  ADD KEY `idx_hrmemp_camp_loc` (`emp_camp_loc`),
  ADD KEY `idx_hrmemp_changed_at` (`changed_at`),
  ADD KEY `idx_hrmemp_last_change_id` (`last_change_id`);

--
-- Indexes for table `hrms_hrmemp_inbox`
--
ALTER TABLE `hrms_hrmemp_inbox`
  ADD PRIMARY KEY (`change_id`);

--
-- Indexes for table `hrms_projects`
--
ALTER TABLE `hrms_projects`
  ADD PRIMARY KEY (`project_code`);

--
-- Indexes for table `timekeeper_project_access_requests`
--
ALTER TABLE `timekeeper_project_access_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_project` (`user_id`,`project_code`);

--
-- Indexes for table `timekeeper_project_map`
--
ALTER TABLE `timekeeper_project_map`
  ADD PRIMARY KEY (`user_id`,`project_code`);

--
-- Indexes for table `work_type_master`
--
ALTER TABLE `work_type_master`
  ADD PRIMARY KEY (`wt_cd`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance_override_notes`
--
ALTER TABLE `attendance_override_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=186315;

--
-- AUTO_INCREMENT for table `device_project_map`
--
ALTER TABLE `device_project_map`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=72;

--
-- AUTO_INCREMENT for table `device_project_map_history`
--
ALTER TABLE `device_project_map_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=123;

--
-- AUTO_INCREMENT for table `employee_daily_punch`
--
ALTER TABLE `employee_daily_punch`
  MODIFY `change_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=422142;

--
-- AUTO_INCREMENT for table `employee_daily_punch_sync`
--
ALTER TABLE `employee_daily_punch_sync`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=429076;

--
-- AUTO_INCREMENT for table `timekeeper_project_access_requests`
--
ALTER TABLE `timekeeper_project_access_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `device_project_map`
--
ALTER TABLE `device_project_map`
  ADD CONSTRAINT `fk_device_project_map_project` FOREIGN KEY (`project_id`) REFERENCES `gcc_it`.`projects` (`id`);

--
-- Constraints for table `device_project_map_history`
--
ALTER TABLE `device_project_map_history`
  ADD CONSTRAINT `fk_device_project_map_history_project` FOREIGN KEY (`project_id`) REFERENCES `gcc_it`.`projects` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
