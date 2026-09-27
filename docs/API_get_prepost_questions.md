# API: get_prepost_questions

Read-only endpoint for the PrePost platform question-master mapping. Returns the non-deleted questions and choices of the requested questionnaires.

## Where this method lives and why

Same rationale as [`get_prepost_courses`](API_get_prepost_courses.md). Lives in `local_myddleware`.

## Endpoint

| | |
|---|---|
| **URL** | `https://campus.jaamericas.org/webservice/rest/server.php` |
| **Function name** | `local_myddleware_get_prepost_questions` |
| **Auth** | Moodle web service token |
| **Response format** | JSON |

## Query parameters

| Param | Type | Required | Description |
|---|---|---|---|
| `wstoken`, `wsfunction`, `moodlewsrestformat` | | yes | As usual |
| `questionnaireids[N]` | int | yes (≥1) | **Course module IDs (cmids)** — the same `cmid` returned by `get_prepost_questionnaires`, not the `mod_questionnaire` instance ID |

## Example request

```
GET .../server.php?wstoken=<TOKEN>&wsfunction=local_myddleware_get_prepost_questions
    &moodlewsrestformat=json&questionnaireids[0]=4501
```

## Response shape

```json
{
  "questions": [
    {
      "questionnaireid": 4501,
      "id": 87,
      "position": 1,
      "typeid": 1,
      "typename": "Yes/No",
      "name": "",
      "content": "¿Ahorrás mensualmente?",
      "required": true,
      "choices": [
        { "id": 201, "content": "Sí", "value": "1", "position": 1 },
        { "id": 202, "content": "No", "value": "0", "position": 2 }
      ]
    }
  ],
  "warnings": []
}
```

## Field reference

| Field | Type | Description |
|---|---|---|
| `questionnaireid` | int | The requested cmid this question belongs to |
| `id` | int | Moodle question ID — use for question-master mapping (id-first, text second) |
| `position` | int | Question order within the questionnaire (from `questionnaire_question.position`) |
| `typeid` / `typename` | int / string | Moodle question type, e.g. `1` / `"Yes/No"` |
| `name` | string | Internal question label. Usually empty — **`content` is the field that carries the actual question text** |
| `content` | string (HTML) | The question text. Key field for mapping and for detecting "question text changed between pre and post" |
| `required` | bool | Whether an answer is required |
| `choices` | array | Empty for open/text questions |
| `choices[].position` | int | 1-based ordinal among that question's choices (Moodle does not store an explicit choice order column; this is derived by ascending choice ID) |

## Error handling

- `moduleunavailable`: `mod_questionnaire` is not installed on this site. `questions` is empty; the whole call short-circuits before touching any cmid.
- `cmnotfound` warning if the cmid does not exist or is not a `questionnaire` course module; other ids in the same call still work.
- `surveynotfound` warning if the course module exists but its underlying survey row is missing (data integrity issue).
- Deleted questions (`questionnaire_question.deleted IS NOT NULL`, an int timestamp) are silently excluded — not an error.
- Page-break and section-text rows (`type_id` `99` and `100`) are layout rows, not real questions, and are always excluded — not an error.
