<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * F1-03 — smoke test for the feature layer.
 *
 * The feature suite boots the application and serves an HTTP request. It was
 * first demonstrated red; see docs/evidence/F1-03/red.md.
 */
class HarnessSmokeTest extends TestCase
{
    public function test_the_application_boots_and_serves_a_request(): void
    {
        $this->get('/')->assertSuccessful();
    }

    public function test_the_feature_layer_is_configured_for_postgresql(): void
    {
        // Seatly has no SQLite fallback at any layer. RULE-004, NFR-001, ADR-002/004.
        $this->assertSame('pgsql', config('database.default'));
    }
}
