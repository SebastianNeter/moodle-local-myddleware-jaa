# API: get_roc_group_enrolments

Scheduled pull endpoint. Returns one **flat** row per `(userid, courseid)` for users who **self-enrolled** into a **group flagged with a country custom field = 1** (`arg`, `roc`, `mex`, `ury`, `col`, `per`) since a given timestamp.

Designed to feed a dedicated Myddleware rule (twice daily cron) that inserts each enrolment **exactly once** into a Salesforce custom staging object (TBD name). SF has its own downstream logic to validate and dispatch each row.

## Why flat shape

Each column in the response maps **1:1** to a field on the target SF custom object. This follows the exact same convention as the other `local_myddleware_get_*_by_date` / `*_by_country` methods in the plugin — it's what Myddleware's field-mapping UI and Myddleware's `read()` / `formatRecord()` engine expect.

## Business rules captured by this endpoint

1. **Trigger event**: a user joins a Moodle group (`mdl_groups_members.timeadded`), not course enrolment and not completion. The goal is "notify SF when a student lands in a class".
2. **Which groups count**: only groups whose custom field `:group_country_filter` (boolean) is set to 1. The filter is passed as a parameter at rule-configuration time — same pattern as `get_course_completion_percentage_by_country`.
3. **Which users count**: only users whose enrolment method into that course is `self`. Manual/admin/bulk/cohort enrolments are excluded by design.
4. **Health filter**: user must be `deleted=0` AND `suspended=0`.
5. **One-shot semantics**: Myddleware handles dedup via `external_id = "<userid>_<courseid>"`. The method may return the same row on two consecutive runs if the timestamp window overlaps — Myddleware will skip the second as a duplicate.
6. **No "leave" handling**: if a user unenrols or the group drops the flag from 1 to 0, the SF record stays as it was. By design.

## Endpoint

| | |
|---|---|
| **URL** | `https://campus.jaamericas.org/webservice/rest/server.php` |
| **HTTP method** | `GET` (or `POST` with the same params) |
| **Function name** | `local_myddleware_get_roc_group_enrolments` |
| **Auth** | Moodle web service token (passed as `wstoken`) |
| **Response format** | JSON (set `moodlewsrestformat=json`) |
| **Type** | `read` |
| **Consumer** | Myddleware (scheduled pull, twice daily) |

## Query parameters

| Param | Type | Required | Description |
|---|---|---|---|
| `wstoken` | string | yes | Moodle web service token |
| `wsfunction` | string | yes | Must be `local_myddleware_get_roc_group_enrolments` |
| `moodlewsrestformat` | string | yes | Set to `json` |
| `time_modified` | int | yes (default 0) | Unix timestamp. Returns rows where `groups_members.timeadded >= :time_modified`. Myddleware keeps this in `rule_param.datereference` and bumps it on each successful run. |
| `group_country_filter` | string | **yes** | One of `arg`, `roc`, `mex`, `ury`, `col`, `per`. Filters groups whose custom field with this shortname equals 1. |

## Example request

```
GET https://campus.jaamericas.org/webservice/rest/server.php
    ?wstoken=<TOKEN>
    &wsfunction=local_myddleware_get_roc_group_enrolments
    &moodlewsrestformat=json
    &time_modified=1776000000
    &group_country_filter=roc
```

**curl example:**

```bash
curl -s "https://campus.jaamericas.org/webservice/rest/server.php?wstoken=<TOKEN>&wsfunction=local_myddleware_get_roc_group_enrolments&moodlewsrestformat=json&time_modified=1776000000&group_country_filter=roc" | python3 -m json.tool
```

## Response shape (flat)

Flat JSON array. Ordered by `groups_members.timeadded` ASC, then `groups_members.id` ASC.

```json
[
  {
    "id": 7842,
    "external_id": "52472_126",
    "timemodified": 1776450000,

    "user_id": 52472,
    "user_username": "juanperez@example.com",
    "user_idnumber": "35123456",
    "user_firstname": "Juan",
    "user_lastname": "Perez",
    "user_email": "juanperez@example.com",
    "user_phone1": "+54911...",
    "user_phone2": "",
    "user_address": "Calle Falsa 123",
    "user_city": "Buenos Aires",
    "user_country": "AR",
    "user_timezone": "America/Argentina/Buenos_Aires",
    "user_lang": "es",
    "user_institution": "",
    "user_department": "",
    "user_description": "",
    "user_firstaccess": 1775000000,
    "user_lastaccess": 1776400000,
    "user_timecreated": 1774000000,
    "user_auth": "email",
    "user_confirmed": 1,

    "course_id": 126,
    "course_fullname": "Prueba Integraciones - B2C",
    "course_shortname": "PI-B2C",
    "course_idnumber": "132013",
    "course_startdate": 1775098800,
    "course_visible": 1,

    "group_id": 2249,
    "group_name": "AR - 2025 - Colegio 1",
    "group_idnumber": "a1ZRK000007KRUf2AO",
    "group_description": "",
    "group_joined_at": 1776450000,

    "enrol_method": "self",
    "enrol_timecreated": 1775666285,
    "enrol_timestart": 0,
    "enrol_timeend": 0,

    "user_cf_nacimiento": "1776135600",
    "user_cf_arg": "0",
    "user_cf_genero": "Masculino",
    "user_cf_roc": "1",
    "user_cf_residencia": "Argentina",
    "user_cf_mex": "0",
    "user_cf_ury": "0",
    "user_cf_col": "0",
    "user_cf_per": "0",
    "user_cf_sf_contact_id": "003XXXXXXXXXXXX"
  }
]
```

## Field reference

### Row identity + Myddleware metadata

| Field | Type | Notes |
|---|---|---|
| `id` | int | `mdl_groups_members.id` — Moodle internal PK. Used by Myddleware as row identity (the value it puts in `document.source_id`). |
| `external_id` | string | `"<userid>_<courseid>"`. Configure as **duplicate_check** on the Myddleware rule. |
| `timemodified` | int | `groups_members.timeadded`. Myddleware advances its `datereference` to the max of this column across the batch. Name has **no underscore** to match the convention of other JAA `*_by_country` / `*_by_date` methods. |

### User (PII complete)

All PII fields. Excluded: password, secret, sesskey, picture/avatar, notification/display settings.

| Field | Type |
|---|---|
| `user_id` | int |
| `user_username` | string |
| `user_idnumber` | string (often DNI at JAA) |
| `user_firstname`, `user_lastname`, `user_email` | string |
| `user_phone1`, `user_phone2`, `user_address`, `user_city`, `user_country`, `user_timezone`, `user_lang`, `user_institution`, `user_department` | string — empty string if null |
| `user_description` | string — bio, stripped to Spanish |
| `user_firstaccess`, `user_lastaccess`, `user_timecreated` | int timestamps (0 = never) |
| `user_auth` | `email` = self-registered; `manual` = admin/API/bulk; `oauth2`/`saml2` = SSO |
| `user_confirmed` | 0/1 |

Note: `user_suspended` is NOT returned because this endpoint filters to `suspended=0`. Always would be 0.

### Course (metadata only)

| Field | Type |
|---|---|
| `course_id` | int |
| `course_fullname` | string, stripped to Spanish |
| `course_shortname` | string |
| `course_idnumber` | string |
| `course_startdate` | int timestamp |
| `course_visible` | 0/1 |

### Group (metadata only)

| Field | Type |
|---|---|
| `group_id` | int |
| `group_name` | string, stripped to Spanish |
| `group_idnumber` | string — at JAA stores the SF Group ID |
| `group_description` | string, stripped to Spanish |
| `group_joined_at` | int timestamp — when the user was added to this group (`groups_members.timeadded`, same value as top-level `timemodified` but preserved here for clarity) |

### Enrolment context (always `self` / `active` here)

Kept for traceability on the SF side.

| Field | Type |
|---|---|
| `enrol_method` | string — always `"self"` given the filters |
| `enrol_timecreated` | int — when the user enrolled in the course |
| `enrol_timestart`, `enrol_timeend` | int — 0 = no restriction |

### User custom profile fields (10 hardcoded JAA shortnames)

Each returned as `user_cf_<shortname>`. Missing values default to **empty string** (never null).

| Field | Moodle datatype | Notes |
|---|---|---|
| `user_cf_nacimiento` | datetime | Unix timestamp stored as string |
| `user_cf_arg` | checkbox | `"1"` or `"0"` or `""` |
| `user_cf_genero` | menu | Stripped to Spanish (handles `{mlang es}Masculino{mlang}...`) |
| `user_cf_roc` | checkbox | |
| `user_cf_residencia` | menu | Stripped to Spanish |
| `user_cf_mex` | checkbox | |
| `user_cf_ury` | checkbox | |
| `user_cf_col` | checkbox | |
| `user_cf_per` | checkbox | |
| `user_cf_sf_contact_id` | text | SF Contact Id free-text |

**If new user profile fields are added in Moodle**, they are NOT returned by this endpoint until the hardcoded list in `externallib.php::get_roc_group_enrolments()` is extended + metadata.php + SF object — three-way coordinated change.

## Multi-language stripping — "máxima rigurosidad"

Any text field that may contain Moodle Multi-Language tags (`{mlang es}...{mlang}{mlang en}...{mlang}...`) is processed through a strict stripper:

1. If text **has no `{mlang}` tags** → returned trimmed, as-is.
2. If text has `{mlang es}...{mlang}` (including `es_AR`, `es_MX`, `es-mx`, etc.) → returns **only** the Spanish content. Multiple Spanish blocks are concatenated with a space.
3. If text has `{mlang}` tags but **no Spanish block** → returns **empty string**. Deliberate: we ship rigorously in Spanish or nothing.

Stripping is applied to: `user_description`, `course_fullname`, `group_name`, `group_description`, and every `user_cf_*` value.

Other fields (names, emails, usernames, idnumbers, phones, addresses) are never mlang-tagged in practice so they pass as-is.

## Error handling

- **Invalid token**: Moodle-level error (`exception`, `errorcode`). Whole request fails. Myddleware logs + retries on next run.
- **`time_modified` missing or not int**: defaults to 0 (returns all matching enrolments from the beginning of time). Explicit by `VALUE_DEFAULT`.
- **`group_country_filter` missing**: Moodle returns `invalid_parameter_exception`. Required.
- **`group_country_filter` value does not match any custom field**: query returns zero rows. No error (empty array is a valid response).
- **Method not authorized for the user**: `accessexception`. Admin must add the function to the user's web service (already done for `Myddleware service` in `db/services.php`).
- **No rows match**: returns `[]` (empty array). This is **not** an error.

## Performance considerations

- Main query joins 9 tables but all joins are on PK/FK with standard indexes.
- Custom profile fields are batch-fetched once per request (one query, filtered by the 10 known shortnames).
- No N+1 queries. No `completion_info` calls.
- For realistic windows (hours/days), response time is sub-second. For first-ever run (`time_modified=0`), volume scales linearly with historical self-enrolments in ROC-flagged groups.

## Myddleware rule configuration (reference)

| Rule field | Value |
|---|---|
| Name | `[ROC] 4.1 Moodle Group Self-Enrolments → SF TBD Object` |
| Source connector | Moodle (ROC instance) |
| Source module | `Get group self-enrolments by country` (appears in dropdown after connector patch) |
| Rule param `group_country_filter` | `roc` (or other country code) — mandatory dropdown shown by `getFieldsParamUpd()` |
| Target module | `TBD_Object__c` (SF custom staging object) |
| Mode | `Create` |
| Duplicate check field | `external_id` → SF `External_ID__c` (text, unique) |
| Date reference field | `timemodified` |
| Schedule | `0 10,19 * * *` (twice daily, mirror ROC 3.1) |
| Direction | Moodle → SF |

Effect: each enrolment is inserted into the SF staging object once. Subsequent runs skip existing records as duplicates. SF's own logic (triggers, flows, scheduled Apex) then validates and dispatches these staging rows.

## Visibility in Myddleware

**This endpoint DOES pass through Myddleware** (unlike `get_courses_with_users_progress`). Consequences:

- Calls appear in the Myddleware UI (tasks, logs, errors)
- Retries are handled by Myddleware
- The cron + datereference are managed by Myddleware
- Audit log is automatic
- Failures are visible on the Agent dashboard (if configured) as part of the ROC instance

## Privacy note

This endpoint carries PII (DNI, phone, address, email). The Moodle web service token is sent as a URL query parameter — if Moodle's nginx access log captures full URLs, tokens may appear in logs. Verify the nginx config strips/masks `wstoken`, or that log retention is short.

Responses themselves travel over HTTPS between Moodle and Myddleware (same VPC). They should not leave that perimeter in plain form — Myddleware stores the document payload encrypted in its `document` table.

## Changelog

- **2026-04-20** (v2.3.2, plugin version `2026042001`): added by JAA patch. Flat shape, `group_country_filter` parameter, 10 user custom profile fields hardcoded, strip-to-Spanish everywhere.
