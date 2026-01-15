# Attendance API v2 Catalog

Base URL (v2): http://192.168.32.33:3003/v2

Scope:
- Read-only API. The underlying SQL Server database must be treated as read-only.
- Sources are based on the current documentation and the live Utime schema.

## Common response shapes
AttendanceRow
```json
{
  "id": "integer",
  "employeeId": "string|null",
  "badgeNumber": "string",
  "employeeFirstName": "string|null",
  "employeeLastName": "string|null",
  "employeeName": "string|null",
  "departmentId": "integer|null",
  "departmentName": "string|null",
  "designation": "string|null",
  "punchTime": "ISO-8601 string",
  "punchState": "string",
  "verifyType": "integer",
  "workCode": "string|null",
  "terminalSn": "string|null",
  "terminalAlias": "string|null",
  "terminalId": "integer|null",
  "areaAlias": "string|null",
  "longitude": "number|null",
  "latitude": "number|null",
  "gpsLocation": "string|null",
  "mobile": "string|null",
  "source": "integer|null",
  "purpose": "integer|null",
  "isAttendance": "integer|null",
  "reserved": "string|null",
  "uploadTime": "ISO-8601 string|null",
  "syncStatus": "integer|null",
  "syncTime": "ISO-8601 string|null",
  "maskFlag": "integer|null",
  "temperature": "number|null",
  "employeePhoto": "string|null",
  "photoBase64": "string|null"
}
```

AttendanceWindowResponse
```json
{
  "employeeId": "string|null",
  "badgeNumber": "string|null",
  "deviceSn": "string|null",
  "fromDate": "ISO-8601 string",
  "toDate": "ISO-8601 string",
  "rows": ["AttendanceRow"]
}
```

EmployeeSummary
```json
{
  "employeeId": "string",
  "badgeNumber": "string|null",
  "firstName": "string|null",
  "lastName": "string|null",
  "departmentId": "integer|null",
  "departmentName": "string|null",
  "designation": "string|null",
  "gender": "string|null",
  "hireDate": "ISO-8601 string|null",
  "isActive": "boolean",
  "delTag": "integer|null",
  "photo": "string|null"
}
```

EmployeeProfile
```json
{
  "employeeId": "string",
  "badgeNumber": "string|null",
  "firstName": "string|null",
  "lastName": "string|null",
  "departmentId": "integer|null",
  "departmentName": "string|null",
  "designation": "string|null",
  "locationId": "integer|null",
  "locationName": "string|null",
  "positionId": "integer|null",
  "positionName": "string|null",
  "gender": "string|null",
  "hireDate": "ISO-8601 string|null",
  "isActive": "boolean",
  "delTag": "integer|null",
  "photo": "string|null"
}
```

Department
```json
{
  "departmentId": "integer",
  "departmentCode": "string|null",
  "departmentName": "string|null",
  "parentDepartmentId": "integer|null"
}
```

Device
```json
{
  "terminalId": "integer",
  "sn": "string",
  "alias": "string|null",
  "terminalName": "string|null",
  "ipAddress": "string|null",
  "lastActivity": "ISO-8601 string|null",
  "firmware": "string|null",
  "userCount": "integer|null",
  "transactionCount": "integer|null",
  "status": "integer|null"
}
```

Location
```json
{
  "locationId": "integer",
  "locationCode": "string|null",
  "locationName": "string|null",
  "parentLocationId": "integer|null"
}
```

Holiday
```json
{
  "holidayId": "integer",
  "alias": "string|null",
  "startDate": "ISO-8601 string",
  "durationDays": "integer|null",
  "workType": "integer|null",
  "departmentId": "integer|null",
  "locationId": "integer|null"
}
```

## Implemented APIs
1) GET /health
   - Purpose: service status
   - Source tables: none
   - Request:
     ```text
     GET /v2/health
     ```
   - Response:
     ```json
     { "status": "ok" }
     ```
   - Example:
     ```bash
     curl "http://192.168.32.33:3003/v2/health"
     ```

2) GET /attendance
   - Purpose: attendance transactions for an employee or badge
   - Required: `employeeId` or `badgeNumber`
   - Optional filters: `startDate`, `endDate`
   - Source tables: `dbo.iclock_transaction`, `dbo.personnel_employee`, `dbo.personnel_department`, `dbo.iclock_terminal`
   - Request:
     ```text
     GET /v2/attendance?badgeNumber=8453&startDate=2026-01-13&endDate=2026-01-14
     ```
   - Response:
     ```json
     {
       "employeeId": "string|null",
       "badgeNumber": "string|null",
       "deviceSn": "string|null",
       "fromDate": "ISO-8601 string",
       "toDate": "ISO-8601 string",
       "rows": ["AttendanceRow"]
     }
     ```
   - Example:
     ```bash
     curl "http://192.168.32.33:3003/v2/attendance?badgeNumber=8453&startDate=2026-01-13&endDate=2026-01-14"
     ```

3) GET /employees
   - Purpose: list employees
   - Filters: `employeeId`, `badgeNumber`, `departmentId`, `status`, `gender`, `isActive`, `q`
   - Source tables: `dbo.personnel_employee`, `dbo.personnel_department`
   - Request:
     ```text
     GET /v2/employees?departmentId=1
     ```
   - Response:
     ```json
     ["EmployeeSummary"]
     ```
   - Example:
     ```bash
     curl "http://192.168.32.33:3003/v2/employees?departmentId=1"
     ```

4) GET /employees/{employeeId}
   - Purpose: employee profile by internal id
   - Source tables: `dbo.personnel_employee`, `dbo.personnel_department`, `dbo.personnel_location`, `dbo.personnel_position`
   - Request:
     ```text
     GET /v2/employees/0005062899727
     ```
   - Response:
     ```json
     "EmployeeProfile"
     ```
   - Example:
     ```bash
     curl "http://192.168.32.33:3003/v2/employees/0005062899727"
     ```

5) GET /employees/by-badge/{badgeNumber}
   - Purpose: employee profile by badge number
   - Source tables: `dbo.personnel_employee`, `dbo.personnel_department`
   - Request:
     ```text
     GET /v2/employees/by-badge/23550
     ```
   - Response:
     ```json
     {
       "employeeId": "string",
       "badgeNumber": "string|null",
       "firstName": "string|null",
       "lastName": "string|null",
       "departmentId": "integer|null",
       "departmentName": "string|null",
       "designation": "string|null",
       "photo": "string|null"
     }
     ```
   - Example:
     ```bash
     curl "http://192.168.32.33:3003/v2/employees/by-badge/23550"
     ```

6) GET /employees/{employeeId}/photo
   - Purpose: employee profile photo reference
   - Source tables: `dbo.personnel_employee`
   - Request:
     ```text
     GET /v2/employees/0005062899727/photo
     ```
   - Response:
     ```json
     { "employeeId": "string", "photo": "string|null" }
     ```
   - Example:
     ```bash
     curl "http://192.168.32.33:3003/v2/employees/0005062899727/photo"
     ```

7) GET /departments
   - Purpose: department list and hierarchy
   - Filters: `q`, `parentDeptId`
   - Source tables: `dbo.personnel_department`
   - Request:
     ```text
     GET /v2/departments?q=Active
     ```
   - Response:
     ```json
     ["Department"]
     ```
   - Example:
     ```bash
     curl "http://192.168.32.33:3003/v2/departments?q=Active"
     ```

8) GET /devices
   - Purpose: device list
   - Filters: `sn`, `alias`, `terminalName`, `status`, `q`
   - Source tables: `dbo.iclock_terminal`
   - Request:
     ```text
     GET /v2/devices?sn=3170250300001
     ```
   - Response:
     ```json
     ["Device"]
     ```
   - Example:
     ```bash
     curl "http://192.168.32.33:3003/v2/devices?sn=3170250300001"
     ```

9) GET /devices/{sn}
   - Purpose: device details by serial number
   - Source tables: `dbo.iclock_terminal`
   - Request:
     ```text
     GET /v2/devices/3170250300001
     ```
   - Response:
     ```json
     "Device"
     ```
   - Example:
     ```bash
     curl "http://192.168.32.33:3003/v2/devices/3170250300001"
     ```

10) GET /devices/{sn}/attendance
    - Purpose: transactions by device serial number
    - Filters: `startDate`, `endDate`, `punchState`, `verifyType`, `badgeNumber`, `employeeId`
    - Source tables: `dbo.iclock_transaction`, `dbo.personnel_employee`
    - Request:
      ```text
      GET /v2/devices/7334232260011/attendance?startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      {
        "employeeId": "string|null",
        "badgeNumber": "string|null",
        "deviceSn": "string|null",
        "fromDate": "ISO-8601 string",
        "toDate": "ISO-8601 string",
        "rows": ["AttendanceRow"]
      }
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/devices/7334232260011/attendance?startDate=2026-01-13&endDate=2026-01-14"
      ```

11) GET /attendance/{id}
    - Purpose: single transaction by id
    - Source tables: `dbo.iclock_transaction`
    - Request:
      ```text
      GET /v2/attendance/3957190
      ```
    - Response:
      ```json
      "AttendanceRow"
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/attendance/3957190"
      ```

12) GET /attendance/by-employee
    - Purpose: filter-first attendance for an employee
    - Filters: `employeeId|badgeNumber`, `startDate`, `endDate`, `punchState`, `verifyType`
    - Source tables: `dbo.iclock_transaction`, `dbo.personnel_employee`
    - Request:
      ```text
      GET /v2/attendance/by-employee?employeeId=0005062899727&startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      {
        "employeeId": "string|null",
        "badgeNumber": "string|null",
        "deviceSn": "string|null",
        "fromDate": "ISO-8601 string",
        "toDate": "ISO-8601 string",
        "rows": ["AttendanceRow"]
      }
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/attendance/by-employee?employeeId=0005062899727&startDate=2026-01-13&endDate=2026-01-14"
      ```

13) GET /attendance/by-device
    - Purpose: filter-first attendance for a device
    - Filters: `deviceSn`, `startDate`, `endDate`, `punchState`, `verifyType`
    - Source tables: `dbo.iclock_transaction`, `dbo.iclock_terminal`
    - Request:
      ```text
      GET /v2/attendance/by-device?deviceSn=7334232260011&startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      {
        "employeeId": "string|null",
        "badgeNumber": "string|null",
        "deviceSn": "string|null",
        "fromDate": "ISO-8601 string",
        "toDate": "ISO-8601 string",
        "rows": ["AttendanceRow"]
      }
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/attendance/by-device?deviceSn=7334232260011&startDate=2026-01-13&endDate=2026-01-14"
      ```

14) GET /attendance/latest
    - Purpose: latest punch per employee or device
    - Filters: `groupBy=employee|device`, `departmentId`, `limit`
    - Source tables: `dbo.iclock_transaction`, `dbo.personnel_employee`, `dbo.personnel_department`
    - Request:
      ```text
      GET /v2/attendance/latest?groupBy=employee&limit=50&startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      ["AttendanceRow"]
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/attendance/latest?groupBy=employee&limit=50&startDate=2026-01-13&endDate=2026-01-14"
      ```

15) GET /attendance/first-last
    - Purpose: first and last punch per employee in date range
    - Filters: `departmentId`, `startDate`, `endDate`
    - Source tables: `dbo.iclock_transaction`, `dbo.personnel_employee`
    - Request:
      ```text
      GET /v2/attendance/first-last?startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      [
        {
          "employeeId": "string|null",
          "badgeNumber": "string|null",
          "employeeName": "string|null",
          "departmentName": "string|null",
          "firstPunch": "ISO-8601 string|null",
          "lastPunch": "ISO-8601 string|null"
        }
      ]
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/attendance/first-last?startDate=2026-01-13&endDate=2026-01-14"
      ```

16) GET /attendance/counts
    - Purpose: grouped counts
    - Filters: `startDate`, `endDate`, `groupBy=department|deviceSn|punchState|verifyType`
    - Source tables: `dbo.iclock_transaction`, `dbo.personnel_department`, `dbo.iclock_terminal`
    - Request:
      ```text
      GET /v2/attendance/counts?groupBy=deviceSn&startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      [
        {
          "groupBy": "string",
          "value": "string|null",
          "total": "integer"
        }
      ]
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/attendance/counts?groupBy=deviceSn&startDate=2026-01-13&endDate=2026-01-14"
      ```

17) GET /attendance/daily
    - Purpose: per-day rollups
    - Filters: `startDate`, `endDate`, `groupBy=employee|department|deviceSn`
    - Source tables: `dbo.iclock_transaction`, `dbo.personnel_employee`, `dbo.personnel_department`
    - Request:
      ```text
      GET /v2/attendance/daily?groupBy=department&startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      [
        {
          "date": "ISO-8601 string",
          "groupBy": "string",
          "value": "string|null",
          "total": "integer"
        }
      ]
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/attendance/daily?groupBy=department&startDate=2026-01-13&endDate=2026-01-14"
      ```

18) GET /locations
    - Purpose: location list for employees
    - Source tables: `dbo.personnel_location`
    - Request:
      ```text
      GET /v2/locations
      ```
    - Response:
      ```json
      ["Location"]
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/locations"
      ```

19) GET /holidays
    - Purpose: holiday calendar
    - Filters: `departmentId`, `locationId`, `startDate`, `endDate`
    - Source tables: `dbo.att_holiday`, `dbo.personnel_department`, `dbo.personnel_location`
    - Request:
      ```text
      GET /v2/holidays?startDate=2026-01-01&endDate=2026-12-31
      ```
    - Response:
      ```json
      ["Holiday"]
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/holidays?startDate=2026-01-01&endDate=2026-12-31"
      ```

20) GET /devices/{sn}/employees
    - Purpose: employee list with logged-in status for a device within a date range
    - Filters: `startDate`, `endDate`, `departmentId`, `includeAllEmployees`
    - Source tables: `dbo.iclock_transaction`, `dbo.iclock_terminal`, `dbo.personnel_employee`, `dbo.personnel_department`
    - Request:
      ```text
      GET /v2/devices/7334232260011/employees?startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      {
        "deviceSn": "string",
        "fromDate": "ISO-8601 string",
        "toDate": "ISO-8601 string",
        "totalEmployees": "integer",
        "loggedInCount": "integer",
        "notLoggedInCount": "integer",
        "rows": [
          {
            "employeeId": "string|null",
            "badgeNumber": "string|null",
            "firstName": "string|null",
            "lastName": "string|null",
            "departmentId": "integer|null",
            "departmentName": "string|null",
            "loggedIn": "boolean"
          }
        ]
      }
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/devices/7334232260011/employees?startDate=2026-01-13&endDate=2026-01-14"
      ```

21) GET /employees/{employeeId}/attendance
    - Purpose: attendance details for a single employee
    - Filters: `startDate`, `endDate`, `punchState`, `verifyType`, `workCode`
    - Source tables: `dbo.iclock_transaction`, `dbo.personnel_employee`, `dbo.personnel_department`, `dbo.iclock_terminal`
    - Request:
      ```text
      GET /v2/employees/0005062899727/attendance?startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      {
        "employeeId": "string|null",
        "badgeNumber": "string|null",
        "deviceSn": "string|null",
        "fromDate": "ISO-8601 string",
        "toDate": "ISO-8601 string",
        "rows": ["AttendanceRow"]
      }
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/employees/0005062899727/attendance?startDate=2026-01-13&endDate=2026-01-14"
      ```

22) GET /attendance/daily/by-devices
    - Purpose: daily sign-in counts for a list of devices
    - Filters: `deviceSn` (comma-separated), `startDate`, `endDate`
    - Source tables: `dbo.iclock_transaction`
    - Request:
      ```text
      GET /v2/attendance/daily/by-devices?deviceSn=7334232260011&startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      {
        "deviceSn": ["string"],
        "fromDate": "ISO-8601 string",
        "toDate": "ISO-8601 string",
        "rows": [
          { "date": "ISO-8601 string", "total": "integer" }
        ]
      }
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/attendance/daily/by-devices?deviceSn=7334232260011&startDate=2026-01-13&endDate=2026-01-14"
      ```

23) GET /attendance/daily/badges
    - Purpose: daily list of badge numbers with at least one punch
    - Filters: `startDate`, `endDate`
    - Source tables: `dbo.iclock_transaction`
    - Request:
      ```text
      GET /v2/attendance/daily/badges?startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      {
        "fromDate": "ISO-8601 string",
        "toDate": "ISO-8601 string",
        "rows": [
          {
            "date": "ISO-8601 string",
            "total": "integer",
            "badgeNumbers": ["string"]
          }
        ]
      }
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/attendance/daily/badges?startDate=2026-01-13&endDate=2026-01-14"
      ```

24) GET /attendance/daily/badges/by-devices
    - Purpose: daily list of badge numbers with at least one punch for devices
    - Filters: `deviceSn` (comma-separated), `startDate`, `endDate`
    - Source tables: `dbo.iclock_transaction`
    - Request:
      ```text
      GET /v2/attendance/daily/badges/by-devices?deviceSn=7334232260011&startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      {
        "deviceSn": ["string"],
        "fromDate": "ISO-8601 string",
        "toDate": "ISO-8601 string",
        "rows": [
          {
            "deviceSn": "string",
            "days": [
              {
                "date": "ISO-8601 string",
                "total": "integer",
                "badgeNumbers": ["string"]
              }
            ]
          }
        ]
      }
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/attendance/daily/badges/by-devices?deviceSn=7334232260011&startDate=2026-01-13&endDate=2026-01-14"
      ```

25) GET /attendance/daily/checkin-checkout/by-badges
    - Purpose: first/last punch per badge per day
    - Filters: `badgeNumber` (comma-separated), `startDate`, `endDate`
    - Source tables: `dbo.iclock_transaction`
    - Request:
      ```text
      GET /v2/attendance/daily/checkin-checkout/by-badges?badgeNumber=8453&startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      {
        "badgeNumber": ["string"],
        "fromDate": "ISO-8601 string",
        "toDate": "ISO-8601 string",
        "rows": [
          {
            "badgeNumber": "string",
            "days": [
              {
                "date": "ISO-8601 string",
                "firstPunch": "ISO-8601 string|null",
                "lastPunch": "ISO-8601 string|null"
              }
            ]
          }
        ]
      }
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/attendance/daily/checkin-checkout/by-badges?badgeNumber=8453&startDate=2026-01-13&endDate=2026-01-14"
      ```

26) GET /attendance/daily/checkin-checkout/by-devices
    - Purpose: first/last punch per badge per day for devices
    - Filters: `deviceSn` (comma-separated), `startDate`, `endDate`
    - Source tables: `dbo.iclock_transaction`
    - Request:
      ```text
      GET /v2/attendance/daily/checkin-checkout/by-devices?deviceSn=7334232260011&startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      {
        "deviceSn": ["string"],
        "fromDate": "ISO-8601 string",
        "toDate": "ISO-8601 string",
        "rows": [
          {
            "deviceSn": "string",
            "badges": [
              {
                "badgeNumber": "string",
                "days": [
                  {
                    "date": "ISO-8601 string",
                    "firstPunch": "ISO-8601 string|null",
                    "lastPunch": "ISO-8601 string|null"
                  }
                ]
              }
            ]
          }
        ]
      }
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/attendance/daily/checkin-checkout/by-devices?deviceSn=7334232260011&startDate=2026-01-13&endDate=2026-01-14"
      ```

27) GET /attendance/by-badge/{badgeNumber}
    - Purpose: all attendance records for a badge number
    - Filters: `startDate`, `endDate`, `punchState`, `verifyType`, `workCode`
    - Source tables: `dbo.iclock_transaction`, `dbo.personnel_employee`, `dbo.personnel_department`, `dbo.iclock_terminal`
    - Request:
      ```text
      GET /v2/attendance/by-badge/8453?startDate=2026-01-13&endDate=2026-01-14
      ```
    - Response:
      ```json
      {
        "employeeId": "string|null",
        "badgeNumber": "string|null",
        "deviceSn": "string|null",
        "fromDate": "ISO-8601 string",
        "toDate": "ISO-8601 string",
        "rows": ["AttendanceRow"]
      }
      ```
    - Example:
      ```bash
      curl "http://192.168.32.33:3003/v2/attendance/by-badge/8453?startDate=2026-01-13&endDate=2026-01-14"
      ```

28) GET /attendance/stream
    - Purpose: stream live attendance punches
    - Filters: `badgeNumber`, `deviceSn`, `departmentId`, `sinceId`, `limit`, `intervalMs`
    - Source tables: `dbo.iclock_transaction`, `dbo.personnel_employee`, `dbo.personnel_department`, `dbo.iclock_terminal`
    - Request:
      ```text
      GET /v2/attendance/stream?sinceId=3957190&deviceSn=7334232260011&intervalMs=2000
      ```
    - Response:
      ```text
      event: attendance
      id: <lastRowId>
      data: [AttendanceRow, ...]
      ```
    - Example:
      ```bash
      curl -N "http://192.168.32.33:3003/v2/attendance/stream?sinceId=3957190&deviceSn=7334232260011&intervalMs=2000"
      ```

## Field-to-table map (key fields)
- `badgeNumber` -> `dbo.personnel_employee.emp_code`
- `employeeId` -> `dbo.personnel_employee.id`
- `departmentId` -> `dbo.personnel_department.id`
- `departmentName` -> `dbo.personnel_department.dept_name`
- `punchTime` -> `dbo.iclock_transaction.punch_time`
- `punchState` -> `dbo.iclock_transaction.punch_state`
- `verifyType` -> `dbo.iclock_transaction.verify_type`
- `workCode` -> `dbo.iclock_transaction.work_code`
- `terminalSn` -> `dbo.iclock_transaction.terminal_sn` or `dbo.iclock_terminal.sn`
- `terminalAlias` -> `dbo.iclock_transaction.terminal_alias` or `dbo.iclock_terminal.alias`
- `terminalId` -> `dbo.iclock_transaction.terminal_id`
- `areaAlias` -> `dbo.iclock_transaction.area_alias`
- `latitude` -> `dbo.iclock_transaction.latitude`
- `longitude` -> `dbo.iclock_transaction.longitude`
- `gpsLocation` -> `dbo.iclock_transaction.gps_location`
- `employeePhoto` -> `dbo.personnel_employee.photo`
