# API: get_prepost_courses

Read-only endpoint for the PrePost platform sync. Returns courses inside the "Argentina" category tree with their groups and the group "arg" custom field, so the sync can pick which groups count as an "aula".

## Where this method lives and why

Same rationale as [`get_courses_with_users_progress`](API_get_courses_with_users_progress.md): lives in the `local_myddleware` plugin fork (`SebastianNeter/moodle-local-myddleware-jaa`), isolated from Moodle core and versioned in our own repo.

## Endpoint

| | |
|---|---|
| **URL** | `https://campus.jaamericas.org/webservice/rest/server.php` |
| **HTTP method** | `GET` (or `POST`) |
| **Function name** | `local_myddleware_get_prepost_courses` |
| **Auth** | Moodle web service token, registered in the existing `Myddleware service` |
| **Response format** | JSON (`moodlewsrestformat=json`) |

## Query parameters

| Param | Type | Required | Description |
|---|---|---|---|
| `wstoken` | string | yes | Moodle web service token |
| `wsfunction` | string | yes | `local_myddleware_get_prepost_courses` |
| `moodlewsrestformat` | string | yes | `json` |
| `time_modified` | int | no | Returns courses whose own `timemodified`, OR any of whose groups' `timemodified`, is strictly greater than this Unix timestamp cursor (default `0` = no filter). Clients must advance the cursor to the **max** of the course's `timemodified` and all its groups' `timemodified` values found in the response payload before the next call, not just the course's own `timemodified` — otherwise group-only changes (renamed group, "arg" toggle via the group form) will be missed on the next sync |

## Category resolution

The "Argentina" category is resolved in this order:

1. The `local_myddleware/prepost_category_id` admin setting (Site administration → Plugins → Local plugins → Web service for Myddleware), if set to a positive integer.
2. Otherwise, the first course category (lowest `id`) whose name equals "Argentina" (case-insensitive).

A course matches when its category **is** the resolved category **or is nested under it** (Moodle category path prefix match). If neither is found, the call returns `courses: []` with a `categorynotfound` warning.

## Example request

```
GET https://campus.jaamericas.org/webservice/rest/server.php
    ?wstoken=<TOKEN>
    &wsfunction=local_myddleware_get_prepost_courses
    &moodlewsrestformat=json
    &time_modified=1727000000
```

## Response shape

```json
{
  "courses": [
    {
      "id": 126,
      "shortname": "PI-B2C",
      "fullname": "Domino mis cuentas - Colegio 1",
      "categoryid": 12,
      "categorypath": "/3/12",
      "startdate": 1775098800,
      "enddate": 0,
      "visible": 1,
      "timemodified": 1776173548,
      "groups": [
        { "id": 2249, "name": "AR - 2025 - Colegio 1", "idnumber": "", "timemodified": 1775665736, "arg": true },
        { "id": 2250, "name": "Coordinadores", "idnumber": "", "timemodified": 1775665736, "arg": false }
      ]
    }
  ],
  "warnings": []
}
```

## Field reference

| Field | Type | Description |
|---|---|---|
| `id` | int | Moodle course ID |
| `shortname` / `fullname` | string | Course names |
| `categoryid` | int | Immediate category ID of the course |
| `categorypath` | string | Moodle category path (ids), e.g. `/3/12` |
| `startdate` / `enddate` | int | Unix timestamps, `0` = unset |
| `visible` | int | `1` visible, `0` hidden |
| `timemodified` | int | Unix timestamp |
| `groups` | array | **All** groups in the course, not pre-filtered |
| `groups[].arg` | bool \| null | The group's `arg` custom field. `false` when the field is configured but not set on that particular group (its own default value) — this is **not** an error. `null` only when the `arg` field is not configured on this site at all, or when this Moodle instance is older than 4.3 (no group custom fields API) — see warnings |

## Error handling

Per-item `warnings` (standard `item`, `itemid`, `warningcode`, `message` shape), never fails the whole call:

- `categorynotfound`: neither the setting nor a category named "Argentina" resolved. `courses` is empty.
- `customfieldsunsupported`: this Moodle version is older than 4.3; every `arg` is `null`. Emitted once per call, not once per group.
- `argfieldnotconfigured`: this Moodle version supports group custom fields, but no field with shortname `arg` is configured on the site; every `arg` is `null`. Emitted once per call, not once per group.

The caller is expected to filter groups on `arg === true` client-side; this endpoint intentionally returns all groups so a misconfigured or missing custom field never silently drops data.
