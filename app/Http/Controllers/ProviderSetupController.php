<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "complete your profile" page, for a logged-in user who has no provider yet.
 */
class ProviderSetupController extends Controller
{
    /**
     * Handle the incoming request. A user who already has a provider goes back to the dashboard,
     * which sends a user without one here: the two routes exclude each other, no loop.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->user()?->provider !== null) {
            return to_route('dashboard');
        }

        return Inertia::render('provider/setup');
    }
}
