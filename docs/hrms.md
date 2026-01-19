# HRMS Activity API

Base URL: http://192.168.34.1:3000

## Endpoints
- GET /health
- GET /api/employees/:empCode/activity

## curl examples
- Health check  
  curl.exe -i "http://192.168.34.1:3000/health"
- Activity by employee code (today default window)  
  curl.exe -i "http://192.168.34.1:3000/api/employees/27421/activity"
- Activity by employee code + specific day (single day)  
  curl.exe -i "http://192.168.34.1:3000/api/employees/27421/activity?fromdate=2025-12-01&todate=2025-12-01"
- Activity by employee code + date range  
  curl.exe -i "http://192.168.34.1:3000/api/employees/27421/activity?fromdate=2025-12-01&todate=2025-12-31"
- Activity by employee code + fromdate only  
  curl.exe -i "http://192.168.34.1:3000/api/employees/27421/activity?fromdate=2025-12-01"
- Activity by employee code + todate only  
  curl.exe -i "http://192.168.34.1:3000/api/employees/27421/activity?todate=2025-12-01"

## Path parameters
- empCode (string) required; employee code from HRMEMP.EMP_CODE.

## Query parameters
- fromdate (string) ? optional; format `YYYY-MM-DD`.
- todate (string) ? optional; format `YYYY-MM-DD`.
- Date window behavior:
  - both fromdate and todate: use as-is
  - only fromdate: end = today
  - only todate: start = todate (single day)
  - neither: start = today, end = today (single day)
  - if fromdate > todate -> 400

## Response schema
```
{
  "employee": {
    "EMP_ID": "integer",
    "EMP_COMPCD": "string",
    "EMP_CODE": "string",
    "EMP_NAME": "string",
    "EMP_STATUS": "string",
    "EMP_DOJ": "ISO-8601 string",
    "EMP_DOB": "ISO-8601 string",
    "EMP_DEPT_CD": "string",
    "DEPT_NAME": "string|null",
    "EMP_DESG_CD": "string",
    "DESG_NAME": "string|null"
  },
  "range": {
    "from": "YYYY-MM-DD",
    "to": "YYYY-MM-DD"
  },
  "attendance": [
    {
      "TD_ID": "integer",
      "TD_DATE": "ISO-8601 string",
      "TD_START_TIME": "string|null",
      "TD_END_TIME": "string|null",
      "TD_TOT_HRS": "number|null",
      "TD_NOR_HRS": "number|null",
      "TD_NOT_HRS": "number|null",
      "TD_HOT_HRS": "number|null",
      "TD_OTYN": "boolean|null",
      "TD_DAYTYPE": "string|null",
      "TD_MODIFYDATE": "ISO-8601 string|null"
    }
  ],
  "leave": [
    {
      "LV_ID": "integer",
      "LV_DOC_NO": "string|null",
      "LV_TYPE": "string|null",
      "LV_LTT": "string|null",
      "LV_TYPE_DESC": "string|null",
      "LV_DT_FROM": "ISO-8601 string",
      "LV_DT_TO": "ISO-8601 string|null",
      "LV_DAYS_ACT": "number|null",
      "LV_DAYS_UNPAID": "number|null",
      "LV_FLAG": "string|null",
      "LV_CREATED_DATE": "ISO-8601 string|null",
      "LV_MODIFIED_DATE": "ISO-8601 string|null",
      "LV_PAY_STATUS": "paid|unpaid"
    }
  ],
  "publicHolidays": [
    {
      "CL_DATE": "ISO-8601 string",
      "CL_PHOLIDAY": "boolean",
      "CL_HOLIDAY": "boolean",
      "CL_REMARK": "string|null",
      "CLH_REMARKS": "string|null"
    }
  ]
}
```

## Sample response
```
{
  "employee": {
    "EMP_ID": 11032,
    "EMP_COMPCD": "001",
    "EMP_CODE": "27421",
    "EMP_NAME": "Jishnu Menath Prathap Prathap Menath Madhavan",
    "EMP_STATUS": "A",
    "EMP_DOJ": "2025-10-30T00:00:00.000Z",
    "EMP_DOB": "1992-06-16T00:00:00.000Z",
    "EMP_DEPT_CD": "01",
    "DEPT_NAME": "Staff",
    "EMP_DESG_CD": "D656",
    "DESG_NAME": "Project Engineer"
  },
  "range": { "from": "2025-12-01", "to": "2025-12-31" },
  "attendance": [
    {
      "TD_ID": 123456,
      "TD_DATE": "2025-12-10T00:00:00.000Z",
      "TD_START_TIME": "08:00",
      "TD_END_TIME": "17:00",
      "TD_TOT_HRS": 8.00,
      "TD_NOR_HRS": 8.00,
      "TD_NOT_HRS": 0.00,
      "TD_HOT_HRS": 0.00,
      "TD_OTYN": false,
      "TD_DAYTYPE": "N",
      "TD_MODIFYDATE": "2025-12-10T00:00:00.000Z"
    }
  ],
  "leave": [
    {
      "LV_ID": 46330,
      "LV_DOC_NO": "LA10030654",
      "LV_TYPE": "L",
      "LV_LTT": "ANL",
      "LV_TYPE_DESC": "Annual Leave (Pay With Salary)",
      "LV_DT_FROM": "2025-12-04T00:00:00.000Z",
      "LV_DT_TO": "2025-12-04T00:00:00.000Z",
      "LV_DAYS_ACT": 1.0,
      "LV_DAYS_UNPAID": null,
      "LV_FLAG": "#",
      "LV_CREATED_DATE": "2025-12-04T08:12:00.000Z",
      "LV_MODIFIED_DATE": null,
      "LV_PAY_STATUS": "paid"
    }
  ],
  "publicHolidays": [
    {
      "CL_DATE": "2025-12-01T00:00:00.000Z",
      "CL_PHOLIDAY": true,
      "CL_HOLIDAY": false,
      "CL_REMARK": "Mon - 2025",
      "CLH_REMARKS": null
    }
  ]
}
```

## Health response
```
{ "status": "ok" }
```

## Error responses
- 400 when empCode missing  
  {"error":"empCode is required."}
- 400 when date format invalid  
  {"error":"fromdate must be YYYY-MM-DD."} / {"error":"todate must be YYYY-MM-DD."}
- 400 when date window invalid  
  {"error":"fromdate must be on or before todate."}
- 404 when employee not found  
  {"error":"Employee not found."}
- 500 on server error  
  {"error":"Internal server error."}

## Notes
- Attendance is filtered by TD_DATE within the inclusive day window.
- Leave rows include any record overlapping the range.
- LV_PAY_STATUS is computed strictly by HRMTT leave type codes (paid: MAT/PAT/SIF/SIH/SIU/CML; unpaid: ANL/ALA/VLV/VCE/EMR), otherwise falls back to LV_DAYS_UNPAID/TT_DED_LEAVE.
- publicHolidays are sourced from HRMCALENDARD where CL_PHOLIDAY = 1 for the employee company.
