<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Provider>
 */
class ProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'user_id' => User::factory(),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('#####'),
            'name' => $name,
            'bio' => fake()->paragraph(),
            'city' => fake()->randomElement(['Bologna', 'Milano', 'Roma', 'Torino', 'Napoli']),
            'whatsapp' => fake()->e164PhoneNumber(),
            'instagram' => fake()->userName(),
            'avatar_path' => null,
            'is_published' => false,
        ];
    }

    /**
     * Indicate that the provider is visible to customers.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
        ]);
    }
}
