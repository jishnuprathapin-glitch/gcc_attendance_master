# AGENTS.md

## Local paths
- PHP: D:\Jishnu - Workspace\Software Installation\php
- MySQL: D:\Jishnu - Workspace\Software Installation\mysql
- Source code: D:\Jishnu - Workspace\Software Installation\htdocs\gcc_attendance_master

## HRSmart DB (from HRSmart/include/db_connect.php)
- Host: localhost
- User: root
- Password: (empty)
- Database: gcc_it
- MySQL CLI: D:\Jishnu - Workspace\Software Installation\mysql\bin\mysql.exe

## Local URL
- http://ginco.fortiddns.com:3652/gcc_attendance_master/admin
- Localhost base: http://localhost
- Localhost admin: http://localhost/gcc_attendance_master/admin

## Local XAMPP projects (htdocs)
Base URL: http://localhost/
- dashboard -> http://localhost/dashboard
- gcc_attendance_master -> http://localhost/gcc_attendance_master
- HRSmart -> http://localhost/HRSmart
- img -> http://localhost/img
- public -> http://localhost/public
- rest_api_spt_ticket -> http://localhost/rest_api_spt_ticket
- sample project -> http://localhost/sample%20project
- smart_pmv_app -> http://localhost/smart_pmv_app
- webalizer -> http://localhost/webalizer
- xampp -> http://localhost/xampp

## Attendance API
- http://192.168.32.33:3003/v2/attendance/daily/checkin-checkout/by-devices-by-badge?deviceSn=7334232260011,3170250300001&startDate=2026-01-13&endDate=2026-01-14

## New endpoint
- GET /v2/attendance/daily/checkin-checkout/by-devices-by-badge?deviceSn=7334232260011,3170250300001&startDate=2026-01-13&endDate=2026-01-14

## Response shape
{
    "deviceSn": ["string"],
    "fromDate": "ISO-8601 string",
    "toDate": "ISO-8601 string",
    "rows": [
      {
        "badgeNumber": "string",
        "entries": [
          {
            "date": "ISO-8601 string",
            "signin": "ISO-8601 string|null",
            "signout": "ISO-8601 string|null",
            "serialNumber": "string|null"
          }
        ]
      }
    ]
}

## Attendance badges endpoint
- GET /v2/attendance/badges?startDate=2026-01-13&endDate=2026-01-14&deviceSn=7334232260011,3170250300001

## Attendance badges response
{
  "deviceSn": ["string"],
  "fromDate": "ISO-8601 string",
  "toDate": "ISO-8601 string",
  "badgeNumbers": ["string"],
  "total": "integer"
}

## Attendance badges with names endpoint
- GET /v2/attendance/badges/with-names?startDate=2026-01-13&endDate=2026-01-14&deviceSn=7334232260011

## Attendance badges with names response
{
  "deviceSn": ["string"],
  "fromDate": "ISO-8601 string",
  "toDate": "ISO-8601 string",
  "rows": [
    {
      "badgeNumber": "string|null",
      "name": "string|null",
      "firstLoginTime": "ISO-8601 string|null",
      "lastLoginTime": "ISO-8601 string|null",
      "firstLoginDeviceSn": "string|null",
      "lastLoginDeviceSn": "string|null",
      "firstLoginProjectId": "integer|null",
      "firstLoginProjectName": "string|null",
      "lastLoginProjectId": "integer|null",
      "lastLoginProjectName": "string|null"
    }
  ],
  "total": "integer",
  "page": "integer",
  "pageSize": "integer"
}

## Attendance badges notes
- Files updated: server.js, docs/attendance-UTIME-api.md
- Service restarted
- For grouping by day, ask to update

## UTime onboarding endpoints
- GET /v2/onboarded/summary?deviceSn=7334232260011,3170250300001
- GET /v2/onboarded/users?deviceSn=7334232260011,3170250300001

## HRMS notes
- HRMS employee code equals the badge number used in UTime APIs.

## Attendance Dashboard data URLs (Attendance_Dashboard.php)
Attendance API base: http://192.168.32.33:3003/v2
- Logged in badges (table): GET http://192.168.32.33:3003/v2/attendance/badges/with-names?startDate=YYYY-MM-DD&endDate=YYYY-MM-DD&deviceSn=DEVICE_SN_1,DEVICE_SN_2&page=1&pageSize=10
- Logged in badge count (KPI): GET http://192.168.32.33:3003/v2/attendance/badges/count?startDate=YYYY-MM-DD&endDate=YYYY-MM-DD&deviceSn=DEVICE_SN_1,DEVICE_SN_2
- Daily totals (API call still runs if device filter set): GET http://192.168.32.33:3003/v2/attendance/daily/by-devices?deviceSn=DEVICE_SN_1,DEVICE_SN_2&startDate=YYYY-MM-DD&endDate=YYYY-MM-DD
- Device punches by device (active device count): GET http://192.168.32.33:3003/v2/attendance/counts?groupBy=deviceSn&startDate=YYYY-MM-DD&endDate=YYYY-MM-DD
- Online/total devices: GET http://192.168.32.33:3003/v2/devices/status/counts?startDate=YYYY-MM-DD&endDate=YYYY-MM-DD&deviceSn=DEVICE_SN_1,DEVICE_SN_2
- UTime onboarded users (exports): GET http://192.168.32.33:3003/v2/onboarded/users?deviceSn=DEVICE_SN_1,DEVICE_SN_2
Note: UTime date ranges are sent with `endDate` = selected end date + 1 day (end date exclusive).

## Device Project Mapping (Attendance_DeviceMapping.php)
- Page URL: http://ginco.fortiddns.com:3652/gcc_attendance_master/admin/Attendance_DeviceMapping.php
- Mapping update (AJAX): POST http://ginco.fortiddns.com:3652/gcc_attendance_master/admin/Attendance_DeviceMapping.php with form fields `ajax=1`, `action=update-mapping`, `deviceSn=DEVICE_SN`, `projectId=PROJECT_ID|unassigned`, `deviceName=DEVICE_NAME`, `csrf=TOKEN`

HRMS API base: http://192.168.34.1:3000
- HRMS active count: GET http://192.168.34.1:3000/api/employees/active/count
- HRMS active list (fallback + exports): GET http://192.168.34.1:3000/api/employees/active
- HRMS employee activity (snapshot): GET http://192.168.34.1:3000/api/employees/EMP_CODE/activity?fromdate=YYYY-MM-DD&todate=YYYY-MM-DD
- HRMS bulk details (logged in badges): POST http://192.168.34.1:3000/api/employees/details with JSON body ["EMP_CODE_1","EMP_CODE_2"]

Employee attendance API base: http://192.168.32.33:3000
- Employee attendance (HRMS snapshot attendance days): GET http://192.168.32.33:3000/attendance?badgeNumber=EMP_CODE&startDate=YYYY-MM-DDT00:00:00+00:00&endDate=YYYY-MM-DDT23:59:59+00:00

## UI Screenshot Review (Selenium) : code 347

- Purpose: Open web page requested by user, capture screenshots, and review layout/legibility before UX changes.
- HRSmart path: D:\Jishnu - Workspace\Software Installation\htdocs\HRSmart
- HRSmart login URL: http://localhost/HRSmart/index.php
- Selenium scripts (this repo): `tools/selenium_check.py`, `tools/selenium_screens.py`

First-time setup:
- Install selenium: `python -m pip install selenium`
- Ensure Apache/XAMPP running (http://localhost works).
- Create/verify test login in `gcc_it.users`:
  - email: `test@test.com`
  - password: `test`
  - status=1, force_password_change=0
  - division/view_division/department_id copied from `users.id=1`
- Grant full access for that user:
  - `user_special_access` -> `page_all`
  - `user_menu_access` -> all rows in `sidebar_menu`
  - `user_header_access` -> all rows in `sidebar_header`

Execution (no defaults: always pass page):
- Visible browser:
  - `powershell`
  - `$env:SELENIUM_HEADLESS='0'`
  - `python tools/selenium_screens.py --base http://localhost --page /gcc_attendance_master/admin/Attendance_DeviceMapping.php --wait ".device-board"`
- Headless:
  - `powershell`
  - `$env:SELENIUM_HEADLESS='1'`
  - `python tools/selenium_screens.py --base http://localhost --page /gcc_attendance_master/admin/Attendance_DeviceMapping.php --wait "body"`

Script behavior:
- Logs in to HRSmart (defaults to `test@test.com` / `test`; override with `--email` / `--password`).
- Opens requested page, waits for selector, captures screenshots.
- Output location: `test-results/selenium/<timestamp>/` with `viewport.png`, `full.png`, and optional `element_*.png` if `--selector` is used.

Review steps:
- Check layout/legibility, buttons, drag/drop, and any critical interactions.
- If export exists, click export, download file, open it, and validate contents.
