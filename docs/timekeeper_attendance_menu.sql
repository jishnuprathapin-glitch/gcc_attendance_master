-- Add menu item for the Timekeepers Attendance View page.
-- Optional: grant the menu item to Jomin by email.

USE gcc_it;

INSERT INTO gcc_it.sidebar_menu (menu_name, page, head_id, status)
SELECT 'Timekeepers Attendance View', '/gcc_attendance_master/timekeeper/timekeeper_attendance_view.php', 25, 1
WHERE NOT EXISTS (
  SELECT 1
  FROM gcc_it.sidebar_menu
  WHERE page = '/gcc_attendance_master/timekeeper/timekeeper_attendance_view.php'
);

INSERT IGNORE INTO gcc_it.user_menu_access (user_id, menu_id)
SELECT u.id, m.menu_id
FROM gcc_it.users u
JOIN gcc_it.sidebar_menu m
  ON m.page = '/gcc_attendance_master/timekeeper/timekeeper_attendance_view.php'
WHERE u.email = 'jomin@gccginco.ae';
