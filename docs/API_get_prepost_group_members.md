# API: get_prepost_group_members

Read-only endpoint for the PrePost platform sync. Returns the members of the requested groups with the timestamp each one joined.

## Where this method lives and why

Same rationale as [`get_prepost_courses`](API_get_prepost_courses.md). Lives in `local_myddleware`.

## Endpoint

| | |
|---|---|
| **URL** | `https://campus.jaamericas.org/webservice/rest/server.php` |
| **Function name** | `local_myddleware_get_prepost_group_members` |
| **Auth** | Moodle web service token |
| **Response format** | JSON |

## Query parameters

| Param | Type | Required | Description |
|---|---|---|---|
| `wstoken`, `wsfunction`, `moodlewsrestformat` | | yes | As usual |
| `groupids[N]` | int | yes (≥1) | Moodle group IDs, same as the `groups[].id` values returned by `get_prepost_courses`. Max **2000** ids per call; duplicate ids are de-duplicated server-side |

## Why this endpoint exists

`get_prepost_courses` returns each course's groups, and `get_prepost_responses_by_date` returns each response's `groupids`, but **neither carries a `timeadded` per membership**. The app needs it for the group-attribution tie-break (spec `ANLYS-5`): when a person has responses in more than one `arg` group, they count in the group they joined **first**. This endpoint is the only source of that timestamp.

## Example request

```
GET .../server.php?wstoken=<TOKEN>&wsfunction=local_myddleware_get_prepost_group_members
    &moodlewsrestformat=json&groupids[0]=2249
```

## Response shape

```json
{
  "groups": [
    {
      "groupid": 2249,
      "members": [
        { "userid": 55, "timeadded": 1775665736 },
        { "userid": 56, "timeadded": 1775665800 }
      ]
    }
  ],
  "warnings": []
}
```

## Field reference

| Field | Type | Description |
|---|---|---|
| `groupid` | int | Moodle group ID |
| `members[].userid` | int | Moodle user ID — **no other identity field is returned by this endpoint** |
| `members[].timeadded` | int | Unix timestamp the user joined the group (`groups_members.timeadded`) — used for the earliest-membership tie-break |

## Error handling

Per-item `warnings` (standard shape), never fails the whole call:

- `groupnotfound` warning if a group id does not exist; other ids in the same call still work. An unknown group is simply omitted from `groups` (not returned as an empty-members entry).

Group existence and group membership are each resolved in one batched query for the whole request, never one query per group id.
