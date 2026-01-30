-- Grant full HRSmart access to Jomin (page_all + all menu/header items)
-- Update the email if needed before running in production.

USE gcc_it;

SET @user_id = (
  SELECT id
  FROM gcc_it.users
  WHERE email = 'jomin@gccginco.ae'
  LIMIT 1
);

INSERT IGNORE INTO gcc_it.user_special_access (user_id, feature_key)
SELECT @user_id, 'page_all'
WHERE @user_id IS NOT NULL;

INSERT IGNORE INTO gcc_it.user_menu_access (user_id, menu_id)
SELECT @user_id, menu_id
FROM gcc_it.sidebar_menu
WHERE @user_id IS NOT NULL;

INSERT IGNORE INTO gcc_it.user_header_access (user_id, head_id)
SELECT @user_id, head_id
FROM gcc_it.sidebar_header
WHERE @user_id IS NOT NULL;
