-- Add menu item for the Camp Boss No Punch Review page.

USE gcc_it;

INSERT INTO gcc_it.sidebar_menu (menu_name, page, head_id, status)
SELECT 'Camp Boss No Punch', '/gcc_attendance_master/campboss/campboss_attendance_view_no_punch.php', 25, 1
WHERE NOT EXISTS (
  SELECT 1
  FROM gcc_it.sidebar_menu
  WHERE page = '/gcc_attendance_master/campboss/campboss_attendance_view_no_punch.php'
);
