<?php

namespace Database\Seeders;

use App\Enums\Weekday;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Example providers with invented names. Each provider user logs in with DEV_PASSWORD (local use only).
 */
class ProviderSeeder extends Seeder
{
    public const DEV_PASSWORD = 'password';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->providers() as $data) {
            $user = $this->findOrCreateUser($data['email'], $data['owner']);
            $category = Category::query()->where('slug', $data['category'])->firstOrFail();

            $provider = $user->provider()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'bio' => $data['bio'],
                    'city' => $data['city'],
                    'whatsapp' => $data['whatsapp'],
                    'instagram' => $data['instagram'],
                    'is_published' => $data['is_published'],
                ],
            );

            foreach ($data['services'] as $serviceData) {
                $service = $provider->services()->updateOrCreate(
                    ['name' => $serviceData['name']],
                    [
                        'category_id' => $category->id,
                        'description' => $serviceData['description'],
                        'price' => $serviceData['price'],
                        'duration_min' => $serviceData['duration_min'],
                        'duration_max' => $serviceData['duration_max'],
                    ],
                );

                foreach ($serviceData['options'] as $optionData) {
                    $service->options()->updateOrCreate(
                        ['name' => $optionData['name']],
                        [
                            'extra_price' => $optionData['extra_price'],
                            'extra_duration' => $optionData['extra_duration'],
                        ],
                    );
                }
            }

            $this->seedAvailabilities($provider, $data['availabilities']);
        }
    }

    /**
     * Find the provider's user by email, or create a verified one with the development password.
     */
    private function findOrCreateUser(string $email, string $name): User
    {
        return User::query()->where('email', $email)->first()
            ?? User::factory()->create([
                'name' => $name,
                'email' => $email,
                'password' => self::DEV_PASSWORD,
            ]);
    }

    /**
     * Seed weekly slots. Times are local Europe/Rome wall-clock times.
     *
     * @param  list<array{days: list<Weekday>, start: string, end: string}>  $slots
     */
    private function seedAvailabilities(Provider $provider, array $slots): void
    {
        foreach ($slots as $slot) {
            foreach ($slot['days'] as $weekday) {
                $provider->availabilities()->updateOrCreate(
                    ['weekday' => $weekday, 'start_time' => $slot['start']],
                    ['end_time' => $slot['end']],
                );
            }
        }
    }

    /**
     * The example providers, with their services, options and weekly availabilities.
     *
     * @return list<array<string, mixed>>
     */
    private function providers(): array
    {
        return [
            [
                'email' => 'trecce.sahel@example.com',
                'owner' => 'Mariam Kone',
                'slug' => 'trecce-sahel',
                'name' => 'Trecce Sahel',
                'bio' => 'Treccine africane, box braids e knotless. Lavoro su appuntamento nel mio studio.',
                'city' => 'Bologna',
                'whatsapp' => '+393330000001',
                'instagram' => 'trecce.sahel',
                'category' => 'treccine',
                'is_published' => true,
                'services' => [
                    [
                        'name' => 'Box braids',
                        'description' => 'Treccine classiche, durata in base a lunghezza e spessore.',
                        'price' => 90,
                        'duration_min' => 180,
                        'duration_max' => 360,
                        'options' => [
                            ['name' => 'Capelli extra lunghi', 'extra_price' => 20, 'extra_duration' => 60],
                            ['name' => 'Extension incluse', 'extra_price' => 25, 'extra_duration' => 0],
                        ],
                    ],
                    [
                        'name' => 'Cornrows',
                        'description' => 'Treccine aderenti alla testa, con disegno a scelta.',
                        'price' => 45,
                        'duration_min' => 60,
                        'duration_max' => 150,
                        'options' => [
                            ['name' => 'Perline', 'extra_price' => 10, 'extra_duration' => 15],
                        ],
                    ],
                    [
                        'name' => 'Knotless braids',
                        'description' => 'Treccine senza nodo alla radice, più leggere.',
                        'price' => 120,
                        'duration_min' => 240,
                        'duration_max' => 420,
                        'options' => [
                            ['name' => 'Capelli extra lunghi', 'extra_price' => 20, 'extra_duration' => 60],
                            ['name' => 'Extension incluse', 'extra_price' => 25, 'extra_duration' => 0],
                        ],
                    ],
                ],
                'availabilities' => [
                    [
                        'days' => [Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday, Weekday::Saturday],
                        'start' => '09:00:00',
                        'end' => '19:00:00',
                    ],
                ],
            ],
            [
                'email' => 'barberia.lagos@example.com',
                'owner' => 'Tunde Okafor',
                'slug' => 'barberia-lagos',
                'name' => 'Barberia Lagos',
                'bio' => 'Tagli afro, sfumature e cura della barba.',
                'city' => 'Milano',
                'whatsapp' => '+393330000002',
                'instagram' => 'barberia.lagos',
                'category' => 'barbiere',
                'is_published' => true,
                'services' => [
                    [
                        'name' => 'Taglio uomo',
                        'description' => 'Taglio con sfumatura.',
                        'price' => 18,
                        'duration_min' => 30,
                        'duration_max' => 40,
                        'options' => [
                            ['name' => 'Disegno rasato', 'extra_price' => 5, 'extra_duration' => 10],
                        ],
                    ],
                    [
                        'name' => 'Taglio e barba',
                        'description' => 'Taglio con sfumatura e regolazione della barba.',
                        'price' => 25,
                        'duration_min' => 45,
                        'duration_max' => 60,
                        'options' => [
                            ['name' => 'Disegno rasato', 'extra_price' => 5, 'extra_duration' => 10],
                            ['name' => 'Trattamento barba', 'extra_price' => 8, 'extra_duration' => 10],
                        ],
                    ],
                    [
                        'name' => 'Rasatura con panno caldo',
                        'description' => 'Rasatura tradizionale a lama.',
                        'price' => 15,
                        'duration_min' => 25,
                        'duration_max' => 35,
                        'options' => [
                            ['name' => 'Trattamento barba', 'extra_price' => 8, 'extra_duration' => 10],
                        ],
                    ],
                ],
                'availabilities' => [
                    [
                        'days' => [Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday, Weekday::Saturday],
                        'start' => '09:00:00',
                        'end' => '13:00:00',
                    ],
                    [
                        'days' => [Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday, Weekday::Saturday],
                        'start' => '15:00:00',
                        'end' => '19:30:00',
                    ],
                ],
            ],
            [
                'email' => 'unghie.kente@example.com',
                'owner' => 'Efua Mensah',
                'slug' => 'unghie-kente',
                'name' => 'Unghie Kente',
                'bio' => 'Nail art ispirata ai tessuti dell\'Africa occidentale.',
                'city' => 'Torino',
                'whatsapp' => '+393330000003',
                'instagram' => 'unghie.kente',
                'category' => 'nails',
                'is_published' => true,
                'services' => [
                    [
                        'name' => 'Semipermanente mani',
                        'description' => 'Smalto semipermanente in tinta unita.',
                        'price' => 30,
                        'duration_min' => 45,
                        'duration_max' => 60,
                        'options' => [
                            ['name' => 'Nail art', 'extra_price' => 10, 'extra_duration' => 20],
                            ['name' => 'Rimozione vecchio smalto', 'extra_price' => 5, 'extra_duration' => 15],
                        ],
                    ],
                    [
                        'name' => 'Ricostruzione in gel',
                        'description' => 'Allungamento e ricostruzione con gel.',
                        'price' => 55,
                        'duration_min' => 90,
                        'duration_max' => 120,
                        'options' => [
                            ['name' => 'Nail art', 'extra_price' => 10, 'extra_duration' => 20],
                        ],
                    ],
                ],
                'availabilities' => [
                    [
                        'days' => [Weekday::Monday, Weekday::Wednesday, Weekday::Friday],
                        'start' => '10:00:00',
                        'end' => '18:00:00',
                    ],
                    [
                        'days' => [Weekday::Saturday],
                        'start' => '09:00:00',
                        'end' => '13:00:00',
                    ],
                ],
            ],
            [
                'email' => 'atelier.wax@example.com',
                'owner' => 'Aissatou Ba',
                'slug' => 'atelier-wax',
                'name' => 'Atelier Wax',
                'bio' => 'Abiti su misura in tessuto wax. Vetrina in preparazione.',
                'city' => 'Roma',
                'whatsapp' => null,
                'instagram' => 'atelier.wax',
                'category' => 'couture',
                'is_published' => false,
                'services' => [
                    [
                        'name' => 'Prova abito su misura',
                        'description' => 'Presa delle misure e scelta del modello.',
                        'price' => 40,
                        'duration_min' => 60,
                        'duration_max' => 90,
                        'options' => [
                            ['name' => 'Tessuto wax fornito dall\'atelier', 'extra_price' => 60, 'extra_duration' => 0],
                        ],
                    ],
                    [
                        'name' => 'Orli e riparazioni',
                        'description' => 'Piccole modifiche a capi esistenti.',
                        'price' => 15,
                        'duration_min' => 30,
                        'duration_max' => 45,
                        'options' => [
                            ['name' => 'Consegna urgente', 'extra_price' => 25, 'extra_duration' => 0],
                        ],
                    ],
                ],
                'availabilities' => [
                    [
                        'days' => [Weekday::Thursday, Weekday::Friday, Weekday::Saturday],
                        'start' => '10:00:00',
                        'end' => '17:00:00',
                    ],
                ],
            ],
        ];
    }
}
