# API: get_prepost_responses_by_date

Read-only endpoint for the PrePost platform sync. Returns STUDENT-role responses of the requested questionnaires, incrementally by submission date, with every answer merged in.

## Where this method lives and why

Same rationale as [`get_prepost_courses`](API_get_prepost_courses.md). Lives in `local_myddleware`.

## Endpoint

| | |
|---|---|
| **URL** | `https://campus.jaamericas.org/webservice/rest/server.php` |
| **Function name** | `local_myddleware_get_prepost_responses_by_date` |
| **Auth** | Moodle web service token |
| **Response format** | JSON |

## Query parameters

| Param | Type | Required | Description |
|---|---|---|---|
| `wstoken`, `wsfunction`, `moodlewsrestformat` | | yes | As usual |
| `questionnaireids[N]` | int | yes (≥1) | Questionnaire cmids, same as `get_prepost_questions` |
| `time_modified` | int | no | Returns responses whose `submitted` timestamp is strictly greater than this cursor (default `0`). `submitted` only has **1-second granularity**, so clients must advance the cursor to `(max "submitted" value seen in the response payload) - 1`, and **dedupe results on `responseid`** across calls — otherwise a response committed in the same second as the last row read, but after it, is skipped forever |
| `limit` | int | no | Max **raw** rows read per page, before the student-role filter (default `500`, max `2000`, clamped server-side) |
| `offset` | int | no | Paging offset over the **raw** (pre-role-filter) result set (default `0`) — see "Pagination model" below |
| `include_incomplete` | bool | no | Include responses where `complete = false` (default `false` — only complete responses are returned) |

## Student-only filter

Only responses from a user holding a **STUDENT-archetype** role assignment in the response's course are returned. Concretely: the user must have a role assignment at the course context whose `role.archetype` is `student`, **and** must hold no role assignment whose archetype is `editingteacher`, `teacher`, `manager`, or `coursecreator` — either at that same course context, **or at any ancestor context** (the course's category, any category level up, or the system context). This is a direct read of Moodle's `role.archetype` column (no `get_archetype_roles()` call, no per-user query) — see spec `SYNC-2` and the cross-cutting "Student-only" rule. The `courserole` field always echoes `"student"`, since no other role is ever returned; it exists so the sync can re-assert the rule defensively without a second WS call.

## Pagination model

Pagination happens over the **raw** result set — before the student-role filter is applied — because role membership cannot be checked inside the `questionnaire_response` query itself. Concretely:

- `next_offset` always advances by the number of raw rows actually read this call (up to `limit`), **never** by the number of student rows in `responses`.
- `read` exposes that raw-rows-read count directly (`read == next_offset - offset`), so callers do not need to compute it themselves.
- `total` counts every row matching `time_modified`/`complete` **before** the student-role filter — it is a page-sizing hint, not the number of items the caller will receive. It is recomputed on **every** page call (not only the first), so it always reflects the current server-side state; callers that only need it once may ignore it after the first page.
- `returned` is the actual count of items in `responses` (student rows only) — it can be less than `limit`, or even `0`, on a page that still has more data after it.

**Clients must keep paging** with `offset = next_offset` while the previous call's `read` equals `limit`; once a call's `read` is less than `limit`, pagination is complete, regardless of `returned`.

**Known limitation**: because paging is offset-based, a response that is deleted between two paging calls, after having already been counted in an earlier page's offset window, can cause a not-yet-read row to be skipped. This is considered acceptable for an append-mostly, rarely-deleted table like `questionnaire_response`.

## Example request

```
GET .../server.php?wstoken=<TOKEN>&wsfunction=local_myddleware_get_prepost_responses_by_date
    &moodlewsrestformat=json&questionnaireids[0]=4501&time_modified=1727000000&limit=500
```

## Response shape

```json
{
  "total": 42,
  "returned": 1,
  "next_offset": 1,
  "read": 1,
  "responses": [
    {
      "responseid": 9001,
      "cmid": 4501,
      "questionnaireid": 88,
      "userid": 55,
      "username": "jperez",
      "email": "jperez@example.com",
      "anonymous": false,
      "submitted": 1776173548,
      "complete": true,
      "courserole": "student",
      "groupids": [2249],
      "answers": [
        { "question_id": 87, "choice_id": null, "value": "y", "text": null, "rank": null },
        { "question_id": 90, "choice_id": 201, "value": null, "text": null, "rank": null },
        { "question_id": 91, "choice_id": null, "value": null, "text": "Todos los meses", "rank": null }
      ]
    }
  ],
  "warnings": []
}
```

## Field reference

| Field | Type | Description |
|---|---|---|
| `total` / `returned` / `next_offset` / `read` | int | See "Pagination model" |
| `responseid` | int | Moodle response ID |
| `cmid` | int | The requested questionnaire cmid this response belongs to |
| `questionnaireid` | int | `mod_questionnaire` instance ID |
| `userid` | int | Moodle user ID |
| `username` / `email` | string \| null | Identity fields used by the app **only** to pair a person's pre and post responses. **The app stores these identity fields only in a non-exposed Postgres schema (`moodle_identity`) accessible to its service role, uses them solely to pair pre and post responses of the same person, and never exposes them through its API or reports; reports use an internal person id.** — see the anonymity cross-cutting rule in spec.md. **Null when `anonymous` is `true`** |
| `anonymous` | bool | `true` when the questionnaire's `respondenttype` is `"anonymous"`. `mod_questionnaire` stores the real `userid` unconditionally regardless of `respondenttype` and only masks identity in its own UI, so this WS masks `username`/`email` itself instead of leaking an identity Moodle promised to hide. A response with `anonymous: true` **cannot** be identity-paired with another response by the app |
| `submitted` | int | Unix timestamp |
| `complete` | bool | Whether the response was submitted as complete |
| `courserole` | string | Always `"student"` |
| `groupids` | int[] | **All** groups (not just `arg` ones) the user belongs to in this response's course — the caller already knows each group's `arg` value from `get_prepost_courses` |
| `answers[].question_id` | int | Moodle question ID |
| `answers[].choice_id` | int \| null | Selected choice ID — Radio Buttons / Dropdown Box (single), Check Boxes (multiple, one answer entry per selected choice), and Rate/scale question types only |
| `answers[].value` | string \| null | `"y"` or `"n"` — **Yes/No question type only**. Note: `questionnaire_response_bool.choice_id` is not a real choice ID in Moodle's own schema; it is the answer flag itself, which is why it is surfaced here as `value`, not `choice_id` |
| `answers[].text` | string \| null | Free-text answer (Text Box / Essay Box / Numeric / Slider) or the raw date string (Date question type) |
| `answers[].rank` | int \| null | Rank value — Rate (scale 1..5) question type only |

**Not read in this version**: File question types (`questionnaire_response_file`) and the "write your own option" free text attached to a choice (`questionnaire_response_other`). Neither carries information the v1 analysis pipeline needs; both can be added later without changing this contract, since they would simply add more `answers` entries.

## Error handling

Per-item `warnings` (standard shape), never fails the whole call:

- `moduleunavailable`: `mod_questionnaire` is not installed on this site. `responses` is empty; the whole call short-circuits before touching any cmid.
- `cmnotfound` warning if a cmid does not exist or is not a `questionnaire` course module; other ids in the same call still work.

Note: there is no `instancenotfound` warning. `get_coursemodule_from_id()` already joins the course module row to its `questionnaire` instance row to resolve the cmid, so a resolved course module guarantees the instance row exists — a separate existence check would be dead code.

A response whose user account has since been deleted (`user.deleted = 1`) is still returned (it is a real historical response), but with `username`/`email` empty rather than stale deleted-account data.
