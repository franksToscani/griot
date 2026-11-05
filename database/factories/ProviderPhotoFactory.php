<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Models\ProviderPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProviderPhoto>
 */
class ProviderPhotoFactory extends Factory
{
    /**
     * Define the model's default state: a landscape photo with every size made.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'name' => Str::random(40),
            'width' => 1600,
            'height' => 1200,
            'alt_text' => null,
            'position' => 1,
            'consent_at' => now(),
        ];
    }
}
