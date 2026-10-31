# AI usage

## In the product

| Feature | Where | Model / API | What the AI does | What it may NOT do |
|---|---|---|---|---|
| Booking text to form | `includes/booking_parser.php`, prompt `prompts/parse_booking.txt` | `LLM_MODEL` via `LLM_PROVIDER` (OpenAI-compatible or Anthropic) | Extracts attendance, duration, windows, room types, needs, wished room, mandatory groups; lists assumptions; asks one follow-up | Decide availability, priority, approval. Its output is checked field by field and any invalid field falls back to the built-in parser. The user edits and submits the form |
| Unavailability text to change | `includes/change_parser.php`, `prompts/parse_change.txt` | same | Extracts days and periods a faculty member cannot teach | Act for anyone else (PHP checks the faculty ID), apply the change (Admin approval and validator required) |
| Intent routing | `includes/chat.php`, `prompts/intent_router.txt` | same | Classifies a chat message when keyword rules find nothing | Run anything other than the read-only tools listed in `chat.php` |
| RAG answers | `includes/rag.php`, `prompts/rag_answer.txt` | same, plus `EMBED_MODEL` for embeddings | Phrases an answer from retrieved, numbered sources and cites them | Answer without sources, state live facts (those come from SQL) |

Safeguards:
- Request text is passed as data between markers, never as instructions.
- Permissions are checked in PHP before any tool runs.
- Every call is logged in `ai_interactions` (Admin → Activity).
- With no key, or if the provider fails, everything still works through the deterministic parser, keyword routing and FULLTEXT search.

**Never decided by AI:** feasibility, booking priority, timetable validity, approvals, or any change to the timetable. These are made by deterministic code (`engine/`) and checked with the official validator.

## Used while building

| Tool | Purpose | Where the output went |
|---|---|---|
| Claude (Anthropic), in the Claude app | Architecture, code generation for every module, tests, documentation, design direction | Whole repository; reviewed, run and tested by the team |

The team reviewed and tested all generated code against the official validator (`engine/tests`, `database/tests`, `tests/api_smoke.py`).
