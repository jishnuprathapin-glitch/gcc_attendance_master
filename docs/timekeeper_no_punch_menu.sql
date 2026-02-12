-- Add menu item for the Timekeeper No Punch Daily page.

USE gcc_it;

INSERT INTO gcc_it.sidebar_menu (menu_name, page, head_id, status)
SELECT 'No Punch Daily', '/gcc_attendance_master/timekeeper/timekeeper_attendance_view_no_punch.php', 25, 1
WHERE NOT EXISTS (
  SELECT 1
  FROM gcc_it.sidebar_menu
  WHERE page = '/gcc_attendance_master/timekeeper/timekeeper_attendance_view_no_punch.php'
);
