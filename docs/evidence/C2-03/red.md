# C2-03 red evidence

Captured 2026-08-10, before the structural guard triggers and
`Hall::layoutIsFrozen()` existed.

Command: `php artisan test --testsuite=Integration --filter=HallLayoutFreeze`

```text
tests: 20   passed: 9   failed: 10
```

The ten failures are exactly the protections this slice adds — every one reports
that the expected refusal did not happen:

```text
test_a_seat_cannot_be_added_while_frozen
test_a_seat_cannot_be_deleted_while_frozen
test_a_seat_cannot_be_deactivated_while_frozen
test_a_seat_cannot_be_moved_to_another_hall_while_frozen
test_a_seat_row_label_cannot_change_while_frozen
test_a_seat_number_cannot_change_while_frozen
test_seat_sort_order_cannot_change_while_frozen
test_seat_type_cannot_change_while_frozen
test_a_hall_cannot_be_deactivated_while_frozen
  Failed asserting that exception of type
  "Illuminate\Database\QueryException" is thrown.

test_layout_version_increments_on_every_structural_change
test_a_hall_reports_whether_its_layout_is_frozen
```

The nine already passing are the cases the change must **not** break: a hall with
no showtimes, a draft showtime, a started showtime, a cancelled showtime, a
sibling hall, and the three descriptive edits that stay permitted. They were
written first precisely so that over-tightening the rule would show up as a
regression rather than as success.
