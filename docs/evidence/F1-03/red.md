# F1-03 red evidence

Captured 2026-08-10T05:13:55Z · PHP 8.5.3 · Laravel [32m13.24.0[39m
PostgreSQL: 16.14 (Debian 16.14-1.pgdg13+1)

Each layer fails for its expected reason before any setup is added to make it pass.

## Unit layer
```
{"tool":"phpunit","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":2,"failed":1,"failures":[{"test":"Tests\\Unit\\HarnessSmokeTest::test_the_unit_layer_runs_and_reports_failure","file":"/home/nik/Desktop/projects/Project-AI-MemoryCore/projects/coding/seatly/tests/Unit/HarnessSmokeTest.php","line":15,"message":"Deliberate F1-03 red. Replace once observed.\nFailed asserting that two strings are identical.\n--- Expected\n+++ Actual\n@@ @@\n-'red'\n+'green'"}]}
```

## Feature layer
```
{"tool":"phpunit","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":103,"failed":1,"failures":[{"test":"Tests\\Feature\\HarnessSmokeTest::test_the_application_boots_in_the_feature_layer","file":"/home/nik/Desktop/projects/Project-AI-MemoryCore/projects/coding/seatly/tests/Feature/HarnessSmokeTest.php","line":15,"message":"Deliberate F1-03 red. Replace once observed.\nFailed asserting that two strings are identical.\n--- Expected\n+++ Actual\n@@ @@\n-'sqlite'\n+'pgsql'"}]}
```

## Integration layer
```
{"tool":"phpunit","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":108,"failed":1,"failures":[{"test":"Tests\\Integration\\PostgresEnvironmentTest::test_the_integration_layer_reaches_real_postgresql","file":"/home/nik/Desktop/projects/Project-AI-MemoryCore/projects/coding/seatly/tests/Integration/PostgresEnvironmentTest.php","line":16,"message":"Deliberate F1-03 red. Replace once observed.\nFailed asserting that two strings are identical.\n--- Expected\n+++ Actual\n@@ @@\n-'seatly_dev'\n+'seatly_test'"}]}
```

