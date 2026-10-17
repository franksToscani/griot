<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProviderResource;
use App\Models\Provider;
use Inertia\Inertia;
use Inertia\Response;

class ProviderController extends Controller
{
    /**
     * Show the public list of published providers.
     */
    public function index(): Response
    {
        $providers = Provider::query()
            ->published()
            // Active services and their categories in two extra queries, not one per provider.
            ->with([
                'services' => fn ($query) => $query->where('is_active', true),
                'services.category',
            ])
            ->orderBy('name')
            ->paginate(12);

        return Inertia::render('providers/index', [
            'providers' => ProviderResource::collection($providers),
        ]);
    }
}
