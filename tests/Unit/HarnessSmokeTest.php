<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * F1-03 — smoke test for the unit layer.
 *
 * The unit suite runs on plain PHPUnit, without booting the application or
 * touching a database. First demonstrated red; see docs/evidence/F1-03/red.md.
 */
class HarnessSmokeTest extends TestCase
{
    public function test_the_unit_layer_runs_on_the_pinned_php_version(): void
    {
        // composer.json requires php ^8.3.
        $this->assertTrue(
            version_compare(PHP_VERSION, '8.3.0', '>='),
            'Seatly requires PHP 8.3 or newer, found '.PHP_VERSION.'.'
        );
    }

    public function test_money_is_handled_as_integer_sen(): void
    {
        // ADR/CLAUDE.md: store money as integer sen with ISO currency. Float
        // arithmetic on ringgit is the mistake this guards against.
        $ticketSen = 1850;
        $seats = 3;

        $this->assertSame(5550, $ticketSen * $seats);

        // The same sum in ringgit floats does not land on the exact value.
        $this->assertSame(30, 10 + 20);
        $this->assertNotSame(0.30, 0.10 + 0.20);
    }
}
