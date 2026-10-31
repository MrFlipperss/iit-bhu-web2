# CIT scheduling and venue policies

## Who can do what
- **Admin (Dean's office, Spectra core team):** generates the timetable and exam plan, reviews Spectra booking decisions, uploads change files (faculty leave, room closures, added or removed sessions) and approves or rejects the proposed repairs. An Admin can never approve or reject their own request; another Admin must review it.
- **Department coordinator:** submits department events, reviews booking requests assigned to their department, and may request removal of a department session. Coordinators cannot review requests from another department or an app request they personally submitted; schedule changes take effect only after an Admin approves the proposal.
- **Faculty:** submits guest lectures, reports times they cannot teach, and may request an extra or moved session based on one of their scheduled sessions. A repair proposal requires Admin approval before any timetable change.
- **Club representative:** submits club events and sees their own requests.

## Booking priority
The starter dataset's full order is exams, regular classes, institute events, guest lectures, department events, club events. The four event categories are the editable Spectra request order; exams and regular classes remain ahead of them. When two requests have the same category, the one submitted earlier wins. The discrepancy between the Problem Statement's presentation and executable starter-pack order is recorded in [STARTER_PACK_VERIFIED_DELTA.md](../docs/STARTER_PACK_VERIFIED_DELTA.md).

Each role has a highest category it may claim. Admins can claim institute events, faculty can claim guest lectures, coordinators can claim department events and clubs can claim club events. A club that asks for an "institute event" is still treated as a club event.

## How a request is decided
1. A cancelled request is never approved.
2. A request is approved only if the whole event fits inside one of its requested windows, in a room of the right type that seats everyone and has every need (projector, PA system, stage, computers, AC, power benches, step-free access).
3. The room must be free of classes and of higher-priority bookings, and the room must not be closed for repairs.
4. If the request says attendance is mandatory for some student groups, those groups must have no class at that time.
5. Only an approved institute event may displace a regular class. The class is moved to another valid slot for Spectra week only; it is never dropped.
6. If nothing fits inside the window, the system offers the nearest free alternative outside it, or rejects the request with the reason.
7. The requested room is a wish. If it is taken, unsuitable or does not exist, another room that fits is used and the reason is shown.

## Timetable rules
- Classes run Monday to Friday, periods P1 to P9. Periods P10 to P13 (18:00 to 22:00) are for events only.
- No session runs across lunch (13:00 to 14:00). Labs are 3 hours and start only at P2 (09:00) or P6 (14:00).
- No faculty member, student group or room is ever double-booked. A combined lecture counts for every group in it.
- Open electives in the same basket run at the same time in different rooms.
- A faculty member teaches at most 4 hours a day and never when they are unavailable.
- Rooms must be the right type, big enough and step-free when a session needs it.
- Soft goals: avoid times faculty asked to avoid, avoid gaps in a group's day, no more than 6 hours a day for a group, no course lectured twice in a day, avoid rooms under 40% full, avoid the 17:00 period.

## Exams
Midsem exams run Monday to Saturday in two sessions: AM (09:30 to 11:30) and PM (14:00 to 16:00). During exams a room seats half its normal capacity, so a large exam uses several rooms. A student group never has two exams in the same session, and the plan avoids two exams on the same day for a group.

## Changes during the semester
Changes such as faculty leave, room closures and new sessions are permanent. The system proposes a repaired timetable that moves as few sessions as possible, shows every moved session with its reason and applies it only after an Admin approves it. If no valid repair exists, it names the rules and the people or rooms in conflict.

## The AI assistant
The assistant reads requests and questions in English or Hinglish, fills in booking forms and explains decisions. It never approves bookings, decides priority or changes the timetable. Every action goes through the same checks as the manual forms, and manual booking always works even when the AI is unavailable.
