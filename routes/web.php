<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// The Vue single-page app handles every other path; the API lives under /api and
// Stripe webhooks under /stripe.
Route::view('/{path?}', 'app')
    ->where('path', '^(?!api/|api$|stripe/|up$).*$')
    ->name('spa');
