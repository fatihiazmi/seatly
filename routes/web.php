<?php

use App\Http\Controllers\Holds\AcquireSeatHoldController;
use App\Http\Controllers\Showtimes\SeatMapController;
use App\Http\Middleware\EnforceIdempotency;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// C2-04 — public seat map. Browsing requires no account; authentication begins
// at the hold request (ADR-014).
Route::get('showtimes/{showtime}/seat-map', SeatMapController::class)
    // Constrained so a malformed identifier 404s at routing rather than reaching
    // PostgreSQL, where comparing it to a uuid column raises a cast error.
    ->whereUuid('showtime')
    ->name('showtimes.seat-map');

// H3-01 — the hold request. Authentication begins here and nowhere earlier
// (ADR-014, PP-01). Same UUID constraint as the map, for the same reason.
Route::post('showtimes/{showtime}/holds', AcquireSeatHoldController::class)
    ->whereUuid('showtime')
    // `auth` first: the idempotency key is scoped to the principal, so there is
    // nothing to scope it to until the customer is known.
    ->middleware(['auth', EnforceIdempotency::class.':holds.acquire'])
    ->name('showtimes.holds.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
