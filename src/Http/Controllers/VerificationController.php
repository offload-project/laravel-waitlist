<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OffloadProject\Waitlist\Facades\Waitlist;

final class VerificationController extends Controller
{
    public function verify(Request $request, string $token): RedirectResponse
    {
        $entry = Waitlist::verify($token);

        if ($entry === null) {
            return redirect('/')
                ->with('waitlist_verification', 'failed')
                ->with('waitlist_message', 'Invalid or expired verification link.');
        }

        return redirect('/')
            ->with('waitlist_verification', 'success')
            ->with('waitlist_message', 'Your email has been verified successfully.');
    }
}
