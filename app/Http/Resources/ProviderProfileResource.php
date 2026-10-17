<?php

namespace App\Http\Resources;

use App\Enums\Weekday;
use App\Models\Provider;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A provider as shown on its public page. It expects services (with category and options)
 * and availabilities to be already loaded.
 *
 * @mixin Provider
 */
class ProviderProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'city' => $this->city,
            'bio' => $this->bio,
            'avatar_url' => $this->avatarUrl(),
            'contacts' => [
                'whatsapp' => $this->whatsapp !== null && trim($this->whatsapp) !== ''
                    ? ['number' => trim($this->whatsapp), 'url' => $this->whatsappUrl()]
                    : null,
                'instagram' => $this->instagramUsername() !== null
                    ? ['username' => $this->instagramUsername(), 'url' => $this->instagramUrl()]
                    : null,
            ],
            'service_groups' => $this->serviceGroups($request),
            'opening_hours' => $this->openingHours($request),
        ];
    }

    // The nested resources are resolved to plain arrays here: the controller passes the resolved
    // provider to Inertia, which would otherwise wrap every nested resource in a "data" key.

    /**
     * Active services grouped by category, categories in alphabetical order.
     *
     * @return list<array{category: array<string, mixed>, services: array<mixed>}>
     */
    private function serviceGroups(Request $request): array
    {
        return array_values($this->services
            ->filter(fn (Service $service) => $service->is_active)
            ->groupBy('category_id')
            ->sortBy(fn ($services) => $services->first()->category->name)
            ->map(fn ($services) => [
                'category' => (new CategoryResource($services->first()->category))->resolve($request),
                'services' => ServiceResource::collection($services->values())->resolve($request),
            ])
            ->all());
    }

    /**
     * The seven days from Monday to Sunday, each with its time slots; a closed day has none.
     *
     * @return list<array{weekday: int, day: string, slots: array<mixed>}>
     */
    private function openingHours(Request $request): array
    {
        return array_map(fn (Weekday $weekday) => [
            'weekday' => $weekday->value,
            // Key for the translated day name, for example "monday".
            'day' => strtolower($weekday->name),
            'slots' => AvailabilityResource::collection(
                $this->availabilities
                    ->where('weekday', $weekday)
                    ->sortBy('start_time')
                    ->values(),
            )->resolve($request),
        ], Weekday::cases());
    }
}
