# API: get_groups_with_users_progress

On-demand pull endpoint to retrieve members of one or more Moodle groups with their enrolment, completion, last access and selected custom profile fields.

**Designed to be called from a Salesforce button on a Group record.**

Group-centric counterpart of [`get_courses_with_users_progress`](API_get_courses_with_users_progress.md). Kept as a separate method on purpose — the course-centric one already has consumers and stays untouched.

## Why this endpoint exists

The original `get_courses_with_users_progress` is **course-centric**: you pass courseids and receive for each course its groups (as metadata only) and its users (as a flat list of course members, not linked to any specific group). If the caller wanted "users of this group" they had to pass the course and filter in Apex — and the response didn't let them tell which user belongs to which group.

This method inverts the model: you pass **group ids**, and you receive for each group the course it belongs to plus **only** the members of that group (that are also enrolled students of the parent course), each with their individual completion and activity data.

## Endpoint

| | |
|---|---|
| **URL** | `https://campus.jaamericas.org/webservice/rest/server.php` |
| **HTTP method** | `GET` (or `POST` with the same params in the body) |
| **Function name** | `local_myddleware_get_groups_with_users_progress` |
| **Auth** | Moodle web service token (passed as `wstoken`) |
| **Response format** | JSON (set `moodlewsrestformat=json`) |
| **Type** | `read` |

## Query parameters

| Param | Type | Required | Description |
|---|---|---|---|
| `wstoken` | string | yes | Moodle web service token |
| `wsfunction` | string | yes | Must be `local_myddleware_get_groups_with_users_progress` |
| `moodlewsrestformat` | string | yes | Set to `json` |
| `groupids[N]` | int | yes (≥1) | One or more Moodle group IDs. Indexed array, e.g. `groupids[0]=2249&groupids[1]=2258` |

## Example request

```
GET https://campus.jaamericas.org/webservice/rest/server.php
    ?wstoken=<TOKEN>
    &wsfunction=local_myddleware_get_groups_with_users_progress
    &moodlewsrestformat=json
    &groupids[0]=2249
    &groupids[1]=2258
```

**curl example:**

```bash
curl --globoff "https://campus.jaamericas.org/webservice/rest/server.php?wstoken=<TOKEN>&wsfunction=local_myddleware_get_groups_with_users_progress&moodlewsrestformat=json&groupids[0]=2249&groupids[1]=2258"
```

> **Note**: `--globoff` is needed in curl because of the `[` / `]` characters in the query string. In Salesforce Apex `Http.send()` you don't need special escaping.

## Response shape

JSON array of groups. The order matches the order of `groupids` in the request.

```json
[
  {
    "groupid": 2249,
    "name": "AR - 2025 - Colegio 1",
    "description": "",
    "idnumber": "a1ZRK000007KRUf2AO",
    "course": {
      "courseid": 126,
      "course_fullname": "Prueba Integraciones - B2C",
      "course_shortname": "PI-B2C",
      "course_idnumber": "132013",
      "course_visible": 1,
      "course_startdate": 1775098800
    },
    "members": [
      {
        "userid": 49356,
        "username": "florcelmi@gmail.com",
        "firstname": "Florencia",
        "lastname": "Celmi",
        "email": "florcelmi@gmail.com",
        "timecreated": 1775665736,
        "group_joined_at": 1775800000,
        "enrolment": {
          "roleid": 5,
          "rolename": "student",
          "status": 0,
          "timestart": 0,
          "timeend": 0,
          "timecreated": 1775666285,
          "enrolmethod": "manual"
        },
        "completion": {
          "percentage": 100,
          "completed_activities": 8,
          "total_activities": 8,
          "overall_status": "Complete",
          "timemodified": 1776173548,
          "error": ""
        },
        "lastaccess": 0,
        "custom_fields": {
          "residencia": "{mlang en}Argentina{mlang}{mlang es}Argentina{mlang}{mlang pt_br}Argentina{mlang}",
          "genero": "{mlang en}Male{mlang}{mlang es}Masculino{mlang}{mlang pt_br}Masculino{mlang}",
          "nacimiento": "1776135600"
        }
      }
    ],
    "error": ""
  }
]
```

## Field reference

### Group level

| Field | Type | Description |
|---|---|---|
| `groupid` | int | Moodle group ID (matches the requested ID) |
| `name` | string \| null | Group name. `null` if group not found |
| `description` | string \| null | Group description (plain text, HTML stripped via `format_text(..., FORMAT_PLAIN)`) |
| `idnumber` | string \| null | Group external ID. JAA convention: stores the SF Group ID |
| `course` | object \| null | The course this group belongs to. See below. `null` if group or course not found |
| `members` | array | Enrolled students that are also members of this group. See below |
| `error` | string | Empty on success. `"Group not found"` if groupid does not exist. `"Course not found for this group"` if the group exists but its parent course does not (defensive, rare). Other groupids in the same request keep working normally. |

### `course` object (metadata only — no activities, no summary HTML)

| Field | Type | Description |
|---|---|---|
| `courseid` | int | Course ID that owns this group |
| `course_fullname` | string | Course full name (may contain mlang tags) |
| `course_shortname` | string | Course short name |
| `course_idnumber` | string | Course external ID |
| `course_visible` | int | 1 visible, 0 hidden |
| `course_startdate` | int | Unix timestamp |

### Member level

Only users with the **student** archetype role on the parent course are returned. Teachers, managers and other roles are excluded, same policy as `get_courses_with_users_progress`.

| Field | Type | Description |
|---|---|---|
| `userid` | int | Moodle internal user ID |
| `username` | string | Moodle username |
| `firstname` | string | First name |
| `lastname` | string | Last name |
| `email` | string | Email — primary key for matching SF Contact |
| `timecreated` | int | Unix timestamp when the user was created in Moodle |
| `group_joined_at` | int | Unix timestamp when the user was added to **this** group (`mdl_groups_members.timeadded`) |
| `enrolment` | object | See below |
| `completion` | object | See below |
| `lastaccess` | int | Last access timestamp **to this specific course**. `0` if never |
| `custom_fields` | object | See below |

### Enrolment object

| Field | Type | Description |
|---|---|---|
| `roleid` | int | Moodle role ID (always corresponds to a `student` archetype) |
| `rolename` | string | Role short name (e.g. `student`) |
| `status` | int | `0` active, `1` suspended |
| `timestart` | int | Unix timestamp when enrolment becomes active. `0` = no start restriction |
| `timeend` | int | Unix timestamp when enrolment expires. `0` = no end restriction |
| `timecreated` | int | Unix timestamp when the enrolment record was created |
| `enrolmethod` | string | `manual`, `self`, `cohort`, `meta`, etc. |

### Completion object

> **IMPORTANT — semantics changed in v2.4.1 (2026-05-26)**: `percentage`, `completed_activities` and `total_activities` now count **ONLY the activities required for course completion** (Moodle "Course completion" → activity criteria, `course_completion_criteria.criteriatype = 4`). Activities that have completion tracking but are NOT part of the course completion criteria are **excluded** from all three. Before v2.4.1 these counted all completion-tracked activities.

| Field | Type | Description |
|---|---|---|
| `percentage` | float | 0–100 across **required activities only** (course completion criteria, type=activity). `round((completed_required / total_required) * 100, 2)` |
| `completed_activities` | int | Required activities the user has completed (state `COMPLETE` or `COMPLETE_PASS`) |
| `total_activities` | int | Number of activities required for course completion (`criteriatype=4`) |
| `overall_status` | string | `Complete`, `Incomplete`, or `Unknown`. Derived from `is_course_complete()` which evaluates ALL completion criteria (not only activity ones) — so it can be `Complete` while `percentage < 100`, or `Incomplete` while `percentage = 100` |
| `timemodified` | int | Latest completion timestamp across the required activities |
| `error` | string | See below |

**`error` values for the completion object:**

| Value | Meaning |
|---|---|
| `""` (empty) | Success — percentage reflects required-activity progress |
| `"Completion tracking not enabled"` | The course does not have completion tracking turned on |
| `"No required activities configured for this course"` | Completion IS enabled, but the course has no activity-type completion criteria (`criteriatype=4`). The course may use other criteria (grade, manual self-completion, enrolment duration) which are NOT activities. `percentage = 0` in this case |
| `"Failed to load required activities: ..."` | Defensive — DB error fetching the criteria |
| `"Exception: ..."` | Unexpected exception during completion calculation |

**Why required-only**: this percentage is meant to answer "how close is the student to course completion as JAA defines it" — not "how much optional material did they touch". Optional/extra activities with completion tracking do not move the number.

### Custom fields object

| Field | Type | Description |
|---|---|---|
| `residencia` | string \| null | User profile field "Residencia". May contain multi-language tags |
| `genero` | string \| null | User profile field "Genero". May contain multi-language tags |
| `nacimiento` | string \| null | User profile field "Nacimiento". Stored as Unix timestamp string in Moodle |

> **Note about multi-language tags**: consistent with the course-centric method, values are returned **as stored in Moodle** (not stripped). Strings like `{mlang en}Argentina{mlang}{mlang es}Argentina{mlang}{mlang pt_br}Argentina{mlang}` need to be parsed on the SF side. This is intentional — both `on-demand pull` endpoints share the same contract.
>
> (Note: the Myddleware-driven method `get_roc_group_enrolments` DOES strip to Spanish because it feeds a staging object. These are different contracts for different consumers.)

## Error handling

The endpoint follows a **per-group error model**: if one group ID is invalid, the rest of the response still works.

- **Invalid groupid**: the group object comes back with `name=null`, `course=null`, `members=[]`, and `error="Group not found"`. Other groups in the same request work normally.
- **Group exists but parent course doesn't** (rare, defensive): `error="Course not found for this group"`.
- **Invalid token**: Moodle-level error in JSON with `exception` and `errorcode`. Whole request fails.
- **Method not registered for the user**: Moodle error `accessexception`. Admin must add the function to the user's web service (already done via `db/services.php` in this commit).
- **Network/timeout**: handled at SF Apex level (60-second timeout recommended).

## Performance considerations

- Completion is computed per user per activity. For groups with many members in a course with many tracked activities, response time scales linearly. Recommended max: **~200 members per group call**. For larger groups, split.
- Custom profile fields are fetched in one batch query per group.
- `lastaccess` is LEFT JOIN so it never blocks.
- Batching multiple groups in one request is fine as long as the combined member count stays reasonable.

## Salesforce Apex example (sketch)

```apex
public static String fetchGroupsProgress(List<Integer> groupIds) {
    String token = '<MOODLE_TOKEN>';
    String baseUrl = 'https://campus.jaamericas.org/webservice/rest/server.php';
    String url = baseUrl
        + '?wstoken=' + token
        + '&wsfunction=local_myddleware_get_groups_with_users_progress'
        + '&moodlewsrestformat=json';
    Integer i = 0;
    for (Integer gid : groupIds) {
        url += '&groupids[' + i + ']=' + gid;
        i++;
    }

    HttpRequest req = new HttpRequest();
    req.setEndpoint(url);
    req.setMethod('GET');
    req.setTimeout(60000);  // 60 seconds

    Http http = new Http();
    HttpResponse res = http.send(req);
    return res.getBody();  // parse JSON afterwards
}
```

> **Important**: `https://campus.jaamericas.org` must be whitelisted in **Setup → Remote Site Settings** (already done for the course-centric method; same domain).

## Visibility in Myddleware

**This endpoint does NOT pass through Myddleware.** It is a direct call from Salesforce to Moodle. Consequences are identical to `get_courses_with_users_progress`:

- Calls do **not** appear in Myddleware's UI (no tasks, no logs)
- Calls do **not** trigger any Myddleware sync rules
- The Myddleware cron is not affected
- All audit/logging must be handled in Salesforce (e.g. a custom `Moodle_Sync_Log__c` object)

This is by design: low-latency, on-demand pull decoupled from Myddleware.

## Relationship to other plugin methods

| Method | Input | Output shape | Purpose |
|---|---|---|---|
| `get_courses_with_users_progress` | courseids | Course + [groups] + [users at course level] | SF button on Course record |
| `get_groups_with_users_progress` | **groupids** | Group + course + [members of that group only] | **SF button on Group record** |
| `get_roc_group_enrolments` | time_modified + group_country_filter | Flat rows per (user,course) with full PII | Myddleware-driven delta sync |

All three are read-only. They coexist without overlap.

## Changelog

- **2026-04-24** (plugin v2.3.3, version `2026042401`): added by JAA patch. Separate method from the course-centric one — no breaking change to existing consumers.
- **2026-05-26** (plugin v2.4.1, version `2026052202`): **BREAKING semantics change**. `completion.percentage`, `completed_activities` and `total_activities` now count ONLY activities required for course completion (`course_completion_criteria.criteriatype = 4`), not all completion-tracked activities. New error value `"No required activities configured for this course"`. Salesforce consumers that read `percentage` must be aware the number now reflects required-activity progress. Field names unchanged (no payload schema break).
