# API: get_prepost_questionnaires

Read-only endpoint for the PrePost platform sync. Returns the `mod_questionnaire` instances of the requested courses, with a deterministic ordering key so the caller can derive pre/post without depending on questionnaire internals.

## Where this method lives and why

Same rationale as [`get_prepost_courses`](API_get_prepost_courses.md). Lives in `local_myddleware`.

## Endpoint

| | |
|---|---|
| **URL** | `https://campus.jaamericas.org/webservice/rest/server.php` |
| **Function name** | `local_myddleware_get_prepost_questionnaires` |
| **Auth** | Moodle web service token |
| **Response format** | JSON |

## Query parameters

| Param | Type | Required | Description |
|---|---|---|---|
| `wstoken`, `wsfunction`, `moodlewsrestformat` | | yes | As usual |
| `courseids[N]` | int | yes (≥1) | Course IDs, indexed array |
| `time_modified` | int | no | Only return questionnaires modified strictly after this timestamp (`questionnaire.timemodified`, present on every `mod_questionnaire` release) |

## Example request

```
GET .../server.php?wstoken=<TOKEN>&wsfunction=local_myddleware_get_prepost_questionnaires
    &moodlewsrestformat=json&courseids[0]=126&time_modified=0
```

## Response shape

```json
{
  "questionnaires": [
    { "cmid": 4501, "instanceid": 12, "courseid": 126, "name": "Pre", "section": 1,
      "position": 1000, "opendate": 0, "closedate": 0, "visible": 1, "timemodified": 1776173548 },
    { "cmid": 4530, "instanceid": 13, "courseid": 126, "name": "Post", "section": 3,
      "position": 3002, "opendate": 0, "closedate": 0, "visible": 1, "timemodified": 1776200000 }
  ],
  "warnings": []
}
```

## Field reference

| Field | Type | Description |
|---|---|---|
| `cmid` | int | Course module ID — pass this to `get_prepost_questions` |
| `instanceid` | int | `mod_questionnaire` instance ID |
| `courseid` | int | Moodle course ID |
| `name` | string | Activity name |
| `section` | int | Course section number |
| `position` | int | `section * 1000 + index-within-section`. Deterministic: the questionnaire with the **lowest** position across a course is "pre", the **highest** is "post" |
| `opendate` / `closedate` | int | Unix timestamps, `0` = no restriction |
| `visible` | int | `1` visible, `0` hidden |
| `timemodified` | int | Unix timestamp |

## Error handling

- `moduleunavailable`: `mod_questionnaire` is not installed on this site. `questionnaires` is empty; the whole call short-circuits before touching any course.
- `coursenotfound` warning per invalid course ID; other course IDs in the same call still work.
- `instancenotfound` warning if a course module points at a missing `questionnaire` row (data integrity issue); skipped, not fatal.
