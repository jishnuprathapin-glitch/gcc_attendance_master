-- Migration: GCC Attendance Master setup
-- Date: 2026-01-15
-- Notes:
-- - Creates the attendance database and user with scoped privileges.
-- - Adds HRSmart sidebar entries for attendance admin pages.

-- === Database + user ===
CREATE DATABASE IF NOT EXISTS `gcc_attendance_master`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'gcc_attendance_user'@'localhost'
  IDENTIFIED BY 'blSxUfVRPM5pvF9NEkL6WtHC';

GRANT ALL PRIVILEGES ON `gcc_attendance_master`.* TO 'gcc_attendance_user'@'localhost';
GRANT SELECT ON `gcc_it`.* TO 'gcc_attendance_user'@'localhost';
FLUSH PRIVILEGES;

-- === HRSmart sidebar integration ===
-- 1) Create a visible header if it does not exist.
INSERT INTO sidebar_header (head_name, page, status, fa_icon, odr)
SELECT 'Attendance', '/gcc_attendance_master/admin/Attendance_Dashboard.php', 1, 'fas fa-clock', 90
WHERE NOT EXISTS (
  SELECT 1 FROM sidebar_header WHERE head_name = 'Attendance'
);

SET @attendance_head_id = (
  SELECT head_id FROM sidebar_header WHERE head_name = 'Attendance' LIMIT 1
);

-- 2) Visible menu entries (absolute path).
INSERT INTO sidebar_menu (menu_name, page, head_id, status)
SELECT 'Attendance Dashboard', '/gcc_attendance_master/admin/Attendance_Dashboard.php', @attendance_head_id, 1
WHERE NOT EXISTS (
  SELECT 1 FROM sidebar_menu
  WHERE page = '/gcc_attendance_master/admin/Attendance_Dashboard.php' AND head_id = @attendance_head_id
);

INSERT INTO sidebar_menu (menu_name, page, head_id, status)
SELECT 'Live Punches', '/gcc_attendance_master/admin/Attendance_Live.php', @attendance_head_id, 1
WHERE NOT EXISTS (
  SELECT 1 FROM sidebar_menu
  WHERE page = '/gcc_attendance_master/admin/Attendance_Live.php' AND head_id = @attendance_head_id
);

INSERT INTO sidebar_menu (menu_name, page, head_id, status)
SELECT 'Employees', '/gcc_attendance_master/admin/Attendance_Employees.php', @attendance_head_id, 1
WHERE NOT EXISTS (
  SELECT 1 FROM sidebar_menu
  WHERE page = '/gcc_attendance_master/admin/Attendance_Employees.php' AND head_id = @attendance_head_id
);

-- 3) Hidden internal entries (basename only) for page_guard mapping.
INSERT INTO sidebar_menu (menu_name, page, head_id, status)
SELECT 'Attendance Dashboard', 'Attendance_Dashboard.php', @attendance_head_id, 0
WHERE NOT EXISTS (
  SELECT 1 FROM sidebar_menu
  WHERE page = 'Attendance_Dashboard.php' AND head_id = @attendance_head_id
);

INSERT INTO sidebar_menu (menu_name, page, head_id, status)
SELECT 'Live Punches', 'Attendance_Live.php', @attendance_head_id, 0
WHERE NOT EXISTS (
  SELECT 1 FROM sidebar_menu
  WHERE page = 'Attendance_Live.php' AND head_id = @attendance_head_id
);

INSERT INTO sidebar_menu (menu_name, page, head_id, status)
SELECT 'Employees', 'Attendance_Employees.php', @attendance_head_id, 0
WHERE NOT EXISTS (
  SELECT 1 FROM sidebar_menu
  WHERE page = 'Attendance_Employees.php' AND head_id = @attendance_head_id
);

-- 4) Grant access to users (replace <user_id> with HRSmart users.id).
--    Run the SELECT to retrieve the menu_ids first.
SELECT menu_id, menu_name, page
FROM sidebar_menu
WHERE page IN (
  '/gcc_attendance_master/admin/Attendance_Dashboard.php',
  '/gcc_attendance_master/admin/Attendance_Live.php',
  '/gcc_attendance_master/admin/Attendance_Employees.php',
  'Attendance_Dashboard.php',
  'Attendance_Live.php',
  'Attendance_Employees.php'
);

-- Example access grants (replace with real values).
-- INSERT IGNORE INTO user_menu_access (user_id, menu_id)
-- VALUES
--   (<user_id>, <menu_id_visible_dashboard>),
--   (<user_id>, <menu_id_hidden_dashboard>),
--   (<user_id>, <menu_id_visible_live>),
--   (<user_id>, <menu_id_hidden_live>),
--   (<user_id>, <menu_id_visible_employees>),
--   (<user_id>, <menu_id_hidden_employees>);
--
-- Optional: ensure header access.
-- INSERT IGNORE INTO user_header_access (user_id, head_id) VALUES (<user_id>, @attendance_head_id);
