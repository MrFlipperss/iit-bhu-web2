# Starter pack priority order: documented resolution

The acceptance tracker identifies a discrepancy between the Problem Statement's priority presentation and the starter dataset / official validator. The Problem Statement copy used for that comparison is not included in this repository, so its alternate wording cannot be quoted or compared line by line here. This note records the executable source of truth and the rationale used by this implementation.

## Canonical scoring order

`data/starter/cit_public.json` defines this complete priority order:

1. `exam`
2. `regular_session`
3. `institute_event`
4. `guest_lecture`
5. `department_event`
6. `club_event`

The first two entries are scheduled academic obligations. The four event categories form the Spectra booking priority order shown in the app. The dataset also defines role caps: Admin → `institute_event`, Faculty → `guest_lecture`, department coordinator → `department_event`, and club → `club_event`.

## Why the implementation follows the dataset and validator

The official `validator/validator.py` computes each request's effective category using the dataset's `priority_order` and `role_category_cap`, then checks allocation order and priority inversions against those values. `engine/bookings.py` also reads the order from the dataset. Rewriting the imported dataset to match a different interpretation of prose would make the solver and validator disagree with the supplied scoring input, so the untouched starter data remains the default for judging.

The Admin priority control is an explicit runtime override for the four bookable event categories. It stores a new dataset version and recalculates active booking decisions; it does not change the starter file or reorder exams and regular sessions. When evaluating against the official starter dataset, restore/import the starter data so the expected canonical order is active.

## References

- `data/starter/cit_public.json` — `priority_order` and `role_category_cap`
- `validator/validator.py` — `effective_category`, request ordering and priority-inversion validation
- `engine/bookings.py` — booking allocation ordering
- `knowledge/policies.md` — user-facing policy summary
