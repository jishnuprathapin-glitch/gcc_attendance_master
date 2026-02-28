# System Override via DB Events

## Purpose
This replaces legacy PHP cron-based system overrides with MariaDB procedures + event scheduling.

Migration file:
- `docs/sql/20260226_system_override_events.sql`

## What Gets Created
1. API config flags in `gcc_attendance_master.api_config`
- `system_override_php_enabled` (default `0`)
- `system_override_db_enabled` (default `1`)
- `system_override_db_hours_enabled` (default `1`)
- `system_override_db_sunday_enabled` (default `1`)
- `system_override_lookback_days` (default `60`)

2. Procedures
- `sp_system_override_hours(p_start_date, p_end_date)`
- `sp_system_override_sunday(p_start_date, p_end_date)`
- `sp_system_override_run()`

3. Event
- `ev_system_override_runner` (every 15 minutes)

## Rule Mapping (Parity with Legacy PHP)
1. `AUTO_8H_STAFF`
- `ty_cd='01'`
- at least one punch exists
- attendance work code is empty or `SUB`
- no existing override row
- inserts approved override `8.00`

2. `AUTO_10H_NON_STAFF`
- `ty_cd='02'`
- at least one punch exists
- attendance work code is empty or `SUB`
- no existing override row
- inserts approved override `10.00`

3. `OT_ELG_EMPLOYEE_9_12`
- `ty_cd IN ('02','03')`
- both punches exist
- duration `>= 9h` and `< 12h`
- attendance work code is empty or `SUB`
- no existing override row
- inserts approved override `10.00`

4. `AUTO_SUN_WORK_CODE`
- Sunday row with empty work code
- no existing override row
- picks nearest previous/next non-Sunday, non-`PHL` codes
- if prev and next codes match and non-empty, carries that code
- otherwise sets `HOL`

## Deployment Steps
1. Run migration SQL:
```powershell
Get-Content docs\sql\20260226_system_override_events.sql -Raw | mysql -u root gcc_attendance_master
```

2. Enable MySQL event scheduler runtime:
```sql
SET GLOBAL event_scheduler = ON;
```

3. Persist scheduler in MySQL config:
- file: `C:\xampp\mysql\bin\my.ini`
- add:
```ini
event_scheduler=ON
```
- restart MySQL

4. Disable legacy PHP scheduler entry (Task Scheduler or other host scheduler).

## Runtime Controls
1. Turn DB overrides OFF (keeps event/procedures installed):
```sql
UPDATE gcc_attendance_master.api_config
SET config_value = '0'
WHERE config_key = 'system_override_db_enabled';
```

2. Turn DB overrides ON:
```sql
UPDATE gcc_attendance_master.api_config
SET config_value = '1'
WHERE config_key = 'system_override_db_enabled';
```

3. Disable only hours rules:
```sql
UPDATE gcc_attendance_master.api_config
SET config_value = '0'
WHERE config_key = 'system_override_db_hours_enabled';
```

4. Disable only Sunday rule:
```sql
UPDATE gcc_attendance_master.api_config
SET config_value = '0'
WHERE config_key = 'system_override_db_sunday_enabled';
```

5. Change lookback days:
```sql
UPDATE gcc_attendance_master.api_config
SET config_value = '60'
WHERE config_key = 'system_override_lookback_days';
```

6. Hard event disable:
```sql
ALTER EVENT gcc_attendance_master.ev_system_override_runner DISABLE;
```

7. Re-enable event:
```sql
ALTER EVENT gcc_attendance_master.ev_system_override_runner ENABLE;
```

## Legacy PHP Guard
`admin/cron/system_override_staff.php` now exits unless:
- `api_config.system_override_php_enabled = '1'`

Default is `0` from migration, so accidental legacy runs are blocked.

## Verification Queries
```sql
SHOW VARIABLES LIKE 'event_scheduler';
SHOW EVENTS FROM gcc_attendance_master;
SELECT config_key, config_value
FROM gcc_attendance_master.api_config
WHERE config_key LIKE 'system_override%';
```

## Troubleshooting: `ERROR 1206 (HY000)`
If `CALL sp_system_override_run();` fails with:
- `The total number of locks exceeds the lock table size`

Use this recovery sequence:

1. Temporarily disable the runner event:
```sql
ALTER EVENT gcc_attendance_master.ev_system_override_runner DISABLE;
```

2. Apply lock-safe hotfix SQL:
```powershell
mysql -u root gcc_attendance_master < docs/sql/20260227_system_override_lock_fix.sql
```

3. Reduce lookback (recommended):
```sql
UPDATE gcc_attendance_master.api_config
SET config_value = '7'
WHERE config_key = 'system_override_lookback_days';
```

4. Test manually:
```sql
CALL gcc_attendance_master.sp_system_override_run();
```

5. Re-enable event after successful manual run:
```sql
ALTER EVENT gcc_attendance_master.ev_system_override_runner ENABLE;
```
