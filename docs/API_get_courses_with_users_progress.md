# API: get_courses_with_users_progress

On-demand pull endpoint to retrieve full course data, groups, enrolled students, completion and custom profile fields from Moodle. Designed to be called from a Salesforce button on a Course record.

## Where this method lives and why

This method is **NOT** part of Moodle core. It lives inside the **`local_myddleware` plugin**, which is a custom Moodle plugin maintained by JAA (forked from the original Myddleware Ltd plugin).

**Why we put it here instead of Moodle core:**

1. **Decoupling from Moodle**: the JAA plugin is our own code. We can add, modify or remove methods without touching Moodle core or worrying about Moodle upgrades overwriting our changes. Every Moodle upgrade preserves the plugin as long as the plugin's own version number is respected.
2. **Versioning under our control**: every change we make is committed to our own GitHub repo (`SebastianNeter/moodle-local-myddleware-jaa`) with full history. Moodle core changes would be lost on every Moodle update.
3. **One place for all integrations**: this plugin already hosts the methods Myddleware uses for the scheduled bidirectional sync (Moodle ↔ Salesforce). Adding the on-demand SF pull method here keeps **all the integration code in a single, governed location**, even if the actual call from SF does not pass through the Myddleware server.
4. **Future portability**: if we ever decide to replace Moodle with another LMS, the plugin code is isolated and easy to migrate or rewrite. We're not adding logic to Moodle core that would be hard to extract.

**Important to understand**: even though this method lives in the Myddleware plugin, **it is not called through the Myddleware server**. Salesforce calls Moodle directly via Moodle's REST web services. The Myddleware server is not involved. See the "Visibility in Myddleware" section at the end of this document for the consequences of this design.

## Endpoint

| | |
|---|---|
| **URL** | `https://campus.jaamericas.org/webservice/rest/server.php` |
| **HTTP method** | `GET` (or `POST` with the same params in the body) |
| **Function name** | `local_myddleware_get_courses_with_users_progress` |
| **Auth** | Moodle web service token (passed as `wstoken` query param) |
| **Response format** | JSON (set `moodlewsrestformat=json`) |

## Query parameters

| Param | Type | Required | Description |
|---|---|---|---|
| `wstoken` | string | yes | Moodle web service token. Provided by JAA admin. |
| `wsfunction` | string | yes | Must be `local_myddleware_get_courses_with_users_progress` |
| `moodlewsrestformat` | string | yes | Set to `json` (default is XML) |
| `courseids[N]` | int | yes (≥1) | One or more Moodle course IDs. Indexed array, e.g. `courseids[0]=126&courseids[1]=125` |

## Example request

```
GET https://campus.jaamericas.org/webservice/rest/server.php
    ?wstoken=<TOKEN>
    &wsfunction=local_myddleware_get_courses_with_users_progress
    &moodlewsrestformat=json
    &courseids[0]=126
    &courseids[1]=125
```

**curl example:**

```bash
curl --globoff "https://campus.jaamericas.org/webservice/rest/server.php?wstoken=<TOKEN>&wsfunction=local_myddleware_get_courses_with_users_progress&moodlewsrestformat=json&courseids[0]=126&courseids[1]=125"
```

> **Note**: `--globoff` is needed in curl because of the `[` and `]` characters in the query string. In Salesforce Apex `Http.send()` you don't need any special escaping — just build the URL string normally.

## Response shape

The response is a **JSON array of courses**. The order matches the order of `courseids` in the request.

```json
[
  {
    "courseid": 126,
    "course_fullname": "Prueba Integraciones - B2C",
    "course_shortname": "PI-B2C",
    "course_idnumber": "132013",
    "course_visible": 1,
    "course_startdate": 1775098800,
    "groups": [
      {
        "groupid": 2249,
        "name": "AR - 2025 - Colegio 1",
        "description": "",
        "idnumber": ""
      }
    ],
    "users": [
      {
        "userid": 49356,
        "username": "florcelmi@gmail.com",
        "firstname": "prueba",
        "lastname": "1",
        "email": "florcelmi@gmail.com",
        "timecreated": 1775665736,
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

### Course level

| Field | Type | Description |
|---|---|---|
| `courseid` | int | Moodle course ID (matches the requested ID) |
| `course_fullname` | string \| null | Course full name. `null` if course not found |
| `course_shortname` | string \| null | Course short name |
| `course_idnumber` | string \| null | Course external ID (free-text field in Moodle) |
| `course_visible` | int | `1` if visible, `0` if hidden |
| `course_startdate` | int | Unix timestamp |
| `groups` | array | All groups in the course (see below) |
| `users` | array | Enrolled students only (see below) |
| `error` | string | Empty string on success. `"Course not found"` if the course ID does not exist in Moodle. Other course IDs in the same request still work normally. |

### Group level

| Field | Type | Description |
|---|---|---|
| `groupid` | int | Moodle group ID |
| `name` | string | Group name |
| `description` | string | Group description (plain text) |
| `idnumber` | string | Group external ID (free-text field in Moodle) — JAA uses this to store the SF Group ID |

### User level

Only users with the **student** role in the course are returned. Teachers, managers, and other roles are excluded.

| Field | Type | Description |
|---|---|---|
| `userid` | int | Moodle internal user ID |
| `username` | string | Moodle username (often the email, depends on signup method) |
| `firstname` | string | First name |
| `lastname` | string | Last name |
| `email` | string | Email — primary key for matching with SF Contact |
| `timecreated` | int | Unix timestamp when the user was created in Moodle |
| `enrolment` | object | See below |
| `completion` | object | See below |
| `lastaccess` | int | Unix timestamp of last access **to this specific course**. `0` if the user never accessed the course |
| `custom_fields` | object | See below |

### Enrolment object

| Field | Type | Description |
|---|---|---|
| `roleid` | int | Moodle role ID (always corresponds to a `student` archetype) |
| `rolename` | string | Role short name (e.g. `student`) |
| `status` | int | `0` = active, `1` = suspended |
| `timestart` | int | Unix timestamp when enrolment becomes active. `0` = no start restriction |
| `timeend` | int | Unix timestamp when enrolment expires. `0` = no end restriction |
| `timecreated` | int | Unix timestamp when the enrolment record was created |
| `enrolmethod` | string | `manual`, `self`, `cohort`, `meta`, etc. Useful to know how the user got enrolled |

### Completion object

| Field | Type | Description |
|---|---|---|
| `percentage` | float | 0–100. Percentage of activities completed (only counts activities with completion tracking enabled) |
| `completed_activities` | int | Number of activities the user completed |
| `total_activities` | int | Total number of activities tracked in the course |
| `overall_status` | string | `Complete`, `Incomplete`, or `Unknown` |
| `timemodified` | int | Unix timestamp of the most recent activity completion |
| `error` | string | Empty on success. `"Completion tracking not enabled"` if the course does not have completion enabled. Other error messages for unexpected exceptions |

### Custom fields object

| Field | Type | Description |
|---|---|---|
| `residencia` | string \| null | User profile field "Residencia" (country of residence). May contain multi-language tags like `{mlang en}Argentina{mlang}{mlang es}Argentina{mlang}` — SF should parse the relevant language out |
| `genero` | string \| null | User profile field "Genero" |
| `nacimiento` | string \| null | User profile field "Nacimiento". Stored as Unix timestamp string in Moodle |

> **Note about `null` values**: any custom field returns `null` if the user has not set that field in their profile.

> **Note about multi-language tags**: Moodle's "Multi-Language Content" filter stores values like `{mlang en}English text{mlang}{mlang es}Texto en español{mlang}`. The SF integration should strip the unwanted languages or parse the structure as needed.

## Error handling

The endpoint follows a **per-course error model**: if one course ID is invalid, the rest of the response still works.

- **Invalid course ID**: returns the course object with `error: "Course not found"` and empty `groups`/`users` arrays. Other courses in the same request work normally.
- **Invalid token**: returns a Moodle-level error in JSON with `exception` and `errorcode` fields. Whole request fails.
- **Method not registered for the user**: returns Moodle error `accessexception`. Admin must add the function to the user's web service.
- **Network/timeout**: handled at SF Apex level (set a reasonable timeout, e.g. 60 seconds for large courses).

## Performance considerations

- The completion calculation is done per user, per activity. For courses with many users + many activities, the response time scales linearly. Recommended max: **~500 users per call**.
- For very large courses, consider calling one course at a time instead of batching.
- The `lastaccess` and `custom_fields` queries are batched per course, so adding more courses scales well.

## Salesforce Apex example (sketch)

```apex
public static String fetchCoursesProgress(List<Integer> courseIds) {
    String token = '<MOODLE_TOKEN>';
    String baseUrl = 'https://campus.jaamericas.org/webservice/rest/server.php';
    String url = baseUrl
        + '?wstoken=' + token
        + '&wsfunction=local_myddleware_get_courses_with_users_progress'
        + '&moodlewsrestformat=json';
    Integer i = 0;
    for (Integer cid : courseIds) {
        url += '&courseids[' + i + ']=' + cid;
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

> **Important**: Add `https://campus.jaamericas.org` to **Setup → Remote Site Settings** in Salesforce, otherwise the callout will be blocked.

## Visibility in Myddleware

**This endpoint does NOT pass through Myddleware.** It is a direct call from Salesforce to Moodle. Consequences:

- Calls do **not** appear in Myddleware's UI (no tasks, no logs)
- Calls do **not** trigger any Myddleware sync rules
- The Myddleware cron is not affected
- All audit/logging must be handled in Salesforce (e.g. a custom `Moodle_Sync_Log__c` object)

This is by design: the goal of this method is to give SF a low-latency, on-demand pull without coupling to Myddleware.
