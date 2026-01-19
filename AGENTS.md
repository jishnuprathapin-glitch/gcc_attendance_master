# AGENTS.md

## Local paths
- PHP: D:\Jishnu - Workspace\Software Installation\php
- MySQL: D:\Jishnu - Workspace\Software Installation\mysql
- Source code: D:\Jishnu - Workspace\Software Installation\htdocs\gcc_attendance_master

## Local URL
- http://ginco.fortiddns.com:3652/gcc_attendance_master/admin

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
    { "badgeNumber": "string|null", "name": "string|null" }
  ],
  "total": "integer"
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
- Logged in badges (table + CSV): GET http://192.168.32.33:3003/v2/attendance/badges/with-names?startDate=YYYY-MM-DDT00:00:00+00:00&endDate=YYYY-MM-DDT24:00:00+00:00&deviceSn=DEVICE_SN_1,DEVICE_SN_2
- Logged in badge count (KPI): GET http://192.168.32.33:3003/v2/attendance/badges/count?startDate=YYYY-MM-DDT00:00:00+00:00&endDate=YYYY-MM-DDT24:00:00+00:00&deviceSn=DEVICE_SN_1,DEVICE_SN_2
- Daily totals (API call still runs if device filter set): GET http://192.168.32.33:3003/v2/attendance/daily/by-devices?deviceSn=DEVICE_SN_1,DEVICE_SN_2&startDate=YYYY-MM-DD&endDate=YYYY-MM-DD
- Device punches by device (active device count): GET http://192.168.32.33:3003/v2/attendance/counts?groupBy=deviceSn&startDate=YYYY-MM-DDT00:00:00+00:00&endDate=YYYY-MM-DDT24:00:00+00:00
- Online/total devices: GET http://192.168.32.33:3003/v2/devices/status/counts?startDate=YYYY-MM-DD&endDate=YYYY-MM-DD&deviceSn=DEVICE_SN_1,DEVICE_SN_2
- UTime onboarded users (exports): GET http://192.168.32.33:3003/v2/onboarded/users?deviceSn=DEVICE_SN_1,DEVICE_SN_2

HRMS API base: http://192.168.34.1:3000
- HRMS active count: GET http://192.168.34.1:3000/api/employees/active/count
- HRMS active list (fallback + exports): GET http://192.168.34.1:3000/api/employees/active
- HRMS employee activity (snapshot): GET http://192.168.34.1:3000/api/employees/EMP_CODE/activity?fromdate=YYYY-MM-DD&todate=YYYY-MM-DD
- HRMS bulk details (logged in badges): POST http://192.168.34.1:3000/api/employees/details with JSON body ["EMP_CODE_1","EMP_CODE_2"]

Employee attendance API base: http://192.168.32.33:3000
- Employee attendance (HRMS snapshot attendance days): GET http://192.168.32.33:3000/attendance?badgeNumber=EMP_CODE&startDate=YYYY-MM-DDT00:00:00+00:00&endDate=YYYY-MM-DDT23:59:59+00:00
