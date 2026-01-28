USE gcc_it;

-- Fix malformed Attendance Dashboard link (trailing dot) in sidebar_menu.
UPDATE sidebar_menu
SET page = '/gcc_attendance_master/admin/Attendance_Dashboard.php'
WHERE page = '/gcc_attendance_master/admin/Attendance_Dashboard.';

-- Optional verification:
-- SELECT menu_id, menu_name, page, status FROM sidebar_menu
-- WHERE page LIKE '%Attendance_Dashboard%';
