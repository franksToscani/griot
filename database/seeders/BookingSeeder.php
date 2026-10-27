<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Example bookings in the week after the seeder runs. They fall inside the providers'
 * availabilities and never overlap for the same provider.
 */
class BookingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // dayOfWeekIso uses the same numbering as Weekday (Monday = 1).
        $today = CarbonImmutable::now(config()->string('booking.timezone'))->startOfDay();
        $nextMonday = $today->subDays($today->dayOfWeekIso - 1)->addWeek();

        foreach ($this->bookings() as $data) {
            $provider = Provider::query()->where('slug', $data['provider'])->firstOrFail();
            $service = $provider->services()->where('name', $data['service'])->firstOrFail();
            $options = $service->options()->whereIn('name', $data['options'])->get();

            // Local Rome time, converted to UTC for storage.
            $startsAt = $nextMonday
                ->addDays($data['weekday']->value - 1)
                ->setTimeFromTimeString($data['time'])
                ->utc();
            $endsAt = $startsAt->addMinutes($service->duration_max + $options->sum('extra_duration'));

            // The customer phone identifies the example booking, so a new run moves it instead of duplicating it.
            $booking = $provider->bookings()->firstOrNew(['customer_phone' => $data['customer_phone']]);
            $booking->fill([
                'service_id' => $service->id,
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'notes' => $data['notes'],
            ]);
            // Not fillable, so set directly, like the token below.
            $booking->status = $data['status'];
            // Not fillable, so set directly; only on the first run, so the private link stays the same.
            $booking->public_token ??= Str::random(48);
            $booking->save();

            $booking->options()->sync($options);
        }
    }

    /**
     * The example bookings.
     *
     * @return list<array{provider: string, service: string, options: list<string>, weekday: Weekday, time: string, status: BookingStatus, customer_name: string, customer_phone: string, customer_email: string|null, notes: string|null}>
     */
    private function bookings(): array
    {
        return [
            [
                'provider' => 'trecce-sahel',
                'service' => 'Box braids',
                'options' => ['Capelli extra lunghi'],
                'weekday' => Weekday::Tuesday,
                'time' => '09:00',
                'status' => BookingStatus::Confirmed,
                'customer_name' => 'Fatou Ndiaye',
                'customer_phone' => '+393400000001',
                'customer_email' => 'fatou.ndiaye@example.com',
                'notes' => 'Porto io le extension.',
            ],
            [
                'provider' => 'trecce-sahel',
                'service' => 'Cornrows',
                'options' => ['Perline'],
                'weekday' => Weekday::Wednesday,
                'time' => '10:00',
                'status' => BookingStatus::Pending,
                'customer_name' => 'Grace Owusu',
                'customer_phone' => '+393400000002',
                'customer_email' => null,
                'notes' => null,
            ],
            [
                'provider' => 'barberia-lagos',
                'service' => 'Taglio e barba',
                'options' => ['Disegno rasato', 'Trattamento barba'],
                'weekday' => Weekday::Saturday,
                'time' => '10:00',
                'status' => BookingStatus::Confirmed,
                'customer_name' => 'Kofi Asante',
                'customer_phone' => '+393400000003',
                'customer_email' => 'kofi.asante@example.com',
                'notes' => null,
            ],
            [
                'provider' => 'barberia-lagos',
                'service' => 'Taglio uomo',
                'options' => ['Disegno rasato'],
                'weekday' => Weekday::Saturday,
                'time' => '11:30',
                'status' => BookingStatus::Cancelled,
                'customer_name' => 'Samuel Bekele',
                'customer_phone' => '+393400000004',
                'customer_email' => null,
                'notes' => 'Annullata dal cliente.',
            ],
            [
                'provider' => 'unghie-kente',
                'service' => 'Semipermanente mani',
                'options' => ['Nail art', 'Rimozione vecchio smalto'],
                'weekday' => Weekday::Friday,
                'time' => '15:00',
                'status' => BookingStatus::Pending,
                'customer_name' => 'Chiara Rossi',
                'customer_phone' => '+393400000005',
                'customer_email' => 'chiara.rossi@example.com',
                'notes' => null,
            ],
        ];
    }
}
