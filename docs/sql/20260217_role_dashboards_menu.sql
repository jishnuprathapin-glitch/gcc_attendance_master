-- Add menu entries and access grants for role dashboards.
-- Target DB: gcc_it

SET @tk_dashboard_page = '/gcc_attendance_master/timekeeper/timekeeper_dashboard.php';
SET @cb_dashboard_page = '/gcc_attendance_master/campboss/campboss_dashboard.php';

INSERT INTO sidebar_menu (menu_name, page, head_id, status)
SELECT 'Timekeeper Dashboard', @tk_dashboard_page, 25, 1
WHERE NOT EXISTS (
    SELECT 1 FROM sidebar_menu WHERE page = @tk_dashboard_page
);

INSERT INTO sidebar_menu (menu_name, page, head_id, status)
SELECT 'Camp Boss Dashboard', @cb_dashboard_page, 25, 1
WHERE NOT EXISTS (
    SELECT 1 FROM sidebar_menu WHERE page = @cb_dashboard_page
);

SET @tk_dash_menu_id = (
    SELECT menu_id
    FROM sidebar_menu
    WHERE page = @tk_dashboard_page
    ORDER BY menu_id DESC
    LIMIT 1
);

SET @cb_dash_menu_id = (
    SELECT menu_id
    FROM sidebar_menu
    WHERE page = @cb_dashboard_page
    ORDER BY menu_id DESC
    LIMIT 1
);

-- Auto-grant Timekeeper dashboard access to users who already have Timekeeper pages.
INSERT INTO user_menu_access (user_id, menu_id)
SELECT DISTINCT uma.user_id, @tk_dash_menu_id
FROM user_menu_access uma
JOIN sidebar_menu sm ON sm.menu_id = uma.menu_id
WHERE @tk_dash_menu_id IS NOT NULL
  AND sm.page IN (
      '/gcc_attendance_master/timekeeper/timekeeper_attendance_view.php',
      '/gcc_attendance_master/timekeeper/timekeeper_attendance_view_no_punch.php'
  )
  AND NOT EXISTS (
      SELECT 1
      FROM user_menu_access x
      WHERE x.user_id = uma.user_id
        AND x.menu_id = @tk_dash_menu_id
  );

-- Auto-grant Camp Boss dashboard access to users who already have Camp Boss no-punch page access.
INSERT INTO user_menu_access (user_id, menu_id)
SELECT DISTINCT uma.user_id, @cb_dash_menu_id
FROM user_menu_access uma
JOIN sidebar_menu sm ON sm.menu_id = uma.menu_id
WHERE @cb_dash_menu_id IS NOT NULL
  AND sm.page = '/gcc_attendance_master/campboss/campboss_attendance_view_no_punch.php'
  AND NOT EXISTS (
      SELECT 1
      FROM user_menu_access x
      WHERE x.user_id = uma.user_id
        AND x.menu_id = @cb_dash_menu_id
  );

-- Admin and Manager users should monitor both dashboards.
INSERT INTO user_menu_access (user_id, menu_id)
SELECT u.id, @tk_dash_menu_id
FROM users u
WHERE @tk_dash_menu_id IS NOT NULL
  AND LOWER(TRIM(COALESCE(u.role, ''))) IN ('admin', 'manager')
  AND NOT EXISTS (
      SELECT 1
      FROM user_menu_access x
      WHERE x.user_id = u.id
        AND x.menu_id = @tk_dash_menu_id
  );

INSERT INTO user_menu_access (user_id, menu_id)
SELECT u.id, @cb_dash_menu_id
FROM users u
WHERE @cb_dash_menu_id IS NOT NULL
  AND LOWER(TRIM(COALESCE(u.role, ''))) IN ('admin', 'manager')
  AND NOT EXISTS (
      SELECT 1
      FROM user_menu_access x
      WHERE x.user_id = u.id
        AND x.menu_id = @cb_dash_menu_id
  );

