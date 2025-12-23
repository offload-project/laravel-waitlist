<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OffloadProject\Waitlist\Http\Controllers\VerificationController;

Route::get('verify/{token}', [VerificationController::class, 'verify'])
    ->name('waitlist.verify');
