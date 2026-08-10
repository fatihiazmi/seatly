# H3-02 — red

```
php artisan test --testsuite=Integration --filter=AcquireSeatHoldTest
  23 tests, 0 passed, 23 errors, 0 assertions
```

Two causes, both "the thing under test does not exist yet":

| Cause | Cases |
|---|---:|
| `Target class [App\Actions\Holds\AcquireSeatHold] does not exist.` | 17 |
| `Class "App\Exceptions\SeatsUnavailable" does not exist` | 6 |

The six exception cases fail earlier than the rest because `expectException()`
resolves the class name before the action is ever called. Neither `holds` nor
`hold_items` exists at this point either — those failures are simply not reached.
