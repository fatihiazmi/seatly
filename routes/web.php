<?php

use App\Http\Controllers\Showtimes\SeatMapController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// C2-04 — public seat map. Browsing requires no account; authentication begins
// at the hold request (ADR-014).
Route::get('showtimes/{showtime}/seat-map', SeatMapController::class)
    // Constrained so a malformed identifier 404s at routing rather than reaching
    // PostgreSQL, where comparing it to a uuid column raises a cast error.
    ->whereUuid('showtime')
    ->name('showtimes.seat-map');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
