# H3-01 — green

Real server (`php artisan serve --port=8123`), real PostgreSQL, real session
cookie from Fortify login. Not the test client.

| Request | HTTP | Response |
|---|---:|---|
| valid, one seat                | 501 | Seat acquisition is not implemented yet (H3-02). |
| valid, eight seats             | 501 | Seat acquisition is not implemented yet (H3-02). |
| nine seats                     | 422 | seat_ids: You can hold up to 8 seats in one booking. |
| no seats                       | 422 | seat_ids: Select at least one seat. |
| same seat twice                | 422 | seat_ids.0: This seat was selected more than once. |
| malformed seat id              | 422 | seat_ids.0: This is not a seat we recognise. |
| unknown seat id                | 422 | seat_ids.0: This seat is not part of this showtime. |
| accessible, undeclared         | 422 | accessibility_acknowledged: Confirm that someone in this booking requires acce |
| accessible, declared           | 501 | Seat acquisition is not implemented yet (H3-02). |
| companion alone                | 422 | seat_ids: Seat F2 can only be held together with its linked accessible seat. |
| companion + accessible         | 501 | Seat acquisition is not implemented yet (H3-02). |
| showtime already started       | 409 | This showtime is no longer on sale. |
| draft showtime                 | 404 |  |
| unknown showtime               | 404 |  |

## Suite

```
php artisan test --filter=AcquireSeatHoldValidationTest
  23 tests, 79 assertions

composer ci:check
  pint    passed
  phpstan passed, 0 errors
  phpunit 196 tests, 451 assertions   (was 173 / 372 at C2-04)
```

## Two things the suite did not show

**Unauthenticated POST returns 419, not 401.** `postJson()` disables
`VerifyCsrfToken`, so the test client reaches the `auth` middleware and gets 401.
A real client without a session token is stopped one middleware earlier by CSRF.
Both are correct refusals and neither creates anything; the test asserts the
authentication boundary, which is what FR-001 is about. Recorded rather than
papered over, because the two clients genuinely disagree.

**The default validation messages named the payload key.** "The seat_ids.0 field
must be a valid UUID" is not the accessible message AC-004 asks for. Found by
reading the curl output, not by a failing test — the test only asserted the
status code. `messages()` and one assertion on the message text were added
after, with `messages()` first disabled to demonstrate red:

```
- 'You can hold up to 8 seats in one booking.'
+ 'The seat ids field must not have more than 8 items.'
```
