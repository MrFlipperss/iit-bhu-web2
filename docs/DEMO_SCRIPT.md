# Five-minute demo

Start from a clean app: Admin → Reset demo data. Log in as `dean@cit.demo`.

| Time | Who | Show | Say |
|---|---|---|---|
| 0:00–0:30 | M4 | Login page, demo accounts, Admin page (dataset, settings, audit) | Four roles; the starter dataset is imported as-is; every action is logged |
| 0:30–1:15 | M1 | Overview → **Generate**: 0 hard violations, soft penalty, about 30 s. Timetable for CSE-2, then F07 (hatched unavailability). Click a session → **Why here?** | One solver for the whole week; checked by the official validator; explanations come from the data |
| 1:15–2:05 | M4 + M2 | New request: "seminar hall kal shaam 6 baje, 2 ghante, 100 log, mic chahiye" → form with the "kal → TUE" assumption → submit → instant decision. Then "I want SH-2 … wheelchair-accessible …" → warning and the SH-1 suggestion | AI fills the form; deterministic rules decide; nothing is booked by the AI |
| 2:05–2:45 | M2 | Spectra bookings: BR-07 (SH-1 taken by BR-06, an earlier request, so AUD), BR-12 (club capped from guest lecture), BR-04 (cancelled), BR-09 (no venue for 1,200), BR-17 (alternative). As `spectra@`, confirming BR-01 is blocked as self-approval | Priority order, role caps, cancellations, reasons for every decision |
| 2:45–4:00 | M3 | Reschedule → Load the sample → Propose: moved sessions with ghosts and reasons (mostly room-only), 0 hard violations → Approve. Then upload an impossible change and show the named conflict | Minimum movement, Admin approval, never a fake solution |
| 4:00–4:40 | M4 | Overview after the change: utilisation, pending items. Assistant: "Is LH-1 free on Tuesday at 10 am?", "What is the booking priority order?" (sources shown) | Live answers from the database, rules from documents with citations |
| 4:40–5:00 | M1 | Admin → Exports → run `validator.py` on both files in a terminal | Hard violations 0, priority inversions 0 |
