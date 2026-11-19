<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Support\ProviderQrCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The QR code of the logged-in provider's public page, as SVG or PNG ("Il tuo link",
 * docs/provider-link.md). No id and no slug in the address: always the provider of the session.
 * Only once the page is published: before, the page gives 404 to everyone, and so does its QR code.
 * The routes add the headers of the private pages (PrivateProviderPage): never stored, never indexed.
 */
class ProviderQrCodeController extends Controller
{
    public function svg(Request $request): Response|RedirectResponse
    {
        return $this->file($request, fn (Provider $provider) => ProviderQrCode::svg($provider), 'image/svg+xml', 'griot-qr.svg');
    }

    public function png(Request $request): Response|RedirectResponse
    {
        return $this->file($request, fn (Provider $provider) => ProviderQrCode::png($provider), 'image/png', 'griot-qr.png');
    }

    /**
     * @param  callable(Provider): string  $make
     */
    private function file(Request $request, callable $make, string $type, string $name): Response|RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        abort_unless($provider->is_published, 404);

        // A name without the provider's name or slug: the same for everyone.
        return response($make($provider), 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
