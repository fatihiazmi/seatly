# H3-01 — red

```
tests 22  passed 4  failed 18  assertions 28
```

Every failure is the same cause: `POST /showtimes/{public_id}/holds` does not
exist, so Laravel answers 404. The four passing cases are the ones that expect
404 or assert no side effect — they pass vacuously here and are re-proved once
the route exists.

| Test | Expected | Got |
|---|---|---|
| `test_a_hold_requires_authentication` | 401 | 404 |
| `test_at_least_one_seat_is_required` | 422 | 404 |
| `test_seat_ids_must_be_present` | 422 | 404 |
| `test_eight_seats_are_allowed` | 501 | 404 |
| `test_nine_seats_are_rejected` | 422 | 404 |
| `test_duplicate_seat_ids_are_rejected` | 422 | 404 |
| `test_an_unknown_seat_is_rejected` | 422 | 404 |
| `test_a_seat_from_another_showtime_is_rejected` | 422 | 404 |
| `test_a_malformed_seat_id_is_rejected_without_reaching_postgres` | 422 | 404 |
| `test_a_showtime_that_has_started_cannot_be_held` | 409 | 404 |
| `test_a_cancelled_showtime_cannot_be_held` | 409 | 404 |
| `test_an_accessible_seat_requires_the_declaration` | 422 | 404 |
| `test_an_accessible_seat_passes_with_the_declaration` | 501 | 404 |
| `test_an_accessible_seat_may_be_held_without_its_companion` | 501 | 404 |
| `test_the_declaration_is_not_required_for_standard_seats` | 501 | 404 |
| `test_a_companion_seat_alone_is_rejected` | 422 | 404 |
| `test_a_companion_seat_passes_alongside_its_accessible_seat` | 501 | 404 |
| `test_a_rejected_request_changes_no_inventory` | 422 | 404 |
