# HRMS CAMP + HRMEMP Push Consumers

## Endpoints
- `POST /gcc_attendance_master/api/hrms-relocn/sync.php`
- `POST /gcc_attendance_master/api/hrms-hrmemp/sync.php`

## Common Request
- Header: `Content-Type: application/json`
- Header: `X-Api-Key: <key>` (optional unless configured)
- Body fields:
  - `source` string
  - `sentAt` ISO-8601 string
  - `changes` array

## Source Validation
- `hrms-relocn` accepts `source = HRMS_RELOCN_PUSH`
- `hrms-hrmemp` accepts `source = HRMS_HRMEMP_PUSH`

## API Key Resolution
The endpoint checks, in order:
1. Local endpoint `config.php` key (`api_key`) if non-empty.
2. `gcc_attendance_master.api_config` by key:
  - `hrms_camp_sync_api_key` for `hrms-relocn`
  - `hrms_hrmemp_sync_api_key` for `hrms-hrmemp`
3. Endpoint environment variable fallback.

If resolved key is empty, request is accepted without API key validation.
If resolved key is non-empty, `X-Api-Key` must match exactly.

## CAMP (RELOCN) Mapping
Payload fields map to `hrms_camp_sync`:
- `LCCompCd` -> `camp_comp_cd`
- `LCCD` -> `camp_code`
- `LCID` -> `camp_id`
- `LCDESC` -> `camp_name`
- `LCEMIRATE` -> `camp_emirate`
- `changeType` -> `change_type`
- `isDeleted` -> `is_deleted`
- `changedAt` -> `changed_at`
- `changeId` -> `last_change_id`

Upsert key: `(camp_comp_cd, camp_code)`.

## HRMEMP Mapping
Payload fields map to `hrms_hrmemp_sync`:
- `EMP_COMPCD` -> `emp_compcd`
- `EMP_CODE` -> `emp_code`
- `EMP_CAMP_LOC` -> `emp_camp_loc`
- `changeType` -> `change_type`
- `isDeleted` -> `is_deleted`
- `changedAt` -> `changed_at`
- `changeId` -> `last_change_id`

Upsert key: `(emp_compcd, emp_code)`.

## Delete Behavior
- If `isDeleted=true` or `changeType='D'`, row is soft-deleted (`is_deleted=1`).
- Physical delete is not performed.

## Idempotency
- Each endpoint has an inbox table keyed by `change_id`.
- Duplicate `changeId` is skipped.
- New `changeId` for the same business key is processed as normal upsert.

## Success Response
```json
{
  "ok": true,
  "received": 1
}
```

