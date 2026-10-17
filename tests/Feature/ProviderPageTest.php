<?php

use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The props of the provider page.
 *
 * @return array<string, mixed>
 */
function providerPageProps(Provider $provider): array
{
    $props = [];

    test()->get(route('providers.show', $provider->slug))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $page->component('providers/show');
            $props = $page->toArray()['props']['provider'];
        });

    return $props;
}

test('a published provider page shows its data', function () {
    $provider = Provider::factory()->published()->create([
        'slug' => 'trecce-sahel',
        'name' => 'Trecce Sahel',
        'city' => 'Bologna',
        'bio' => "Prima riga\nSeconda riga",
        'avatar_path' => null,
    ]);

    $this->get('/providers/trecce-sahel')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('providers/show')
            ->where('provider.slug', 'trecce-sahel')
            ->where('provider.name', 'Trecce Sahel')
            ->where('provider.city', 'Bologna')
            ->where('provider.bio', "Prima riga\nSeconda riga")
            ->where('provider.avatar_url', null));

    expect(route('providers.show', $provider->slug))->toEndWith('/providers/trecce-sahel');
});

test('guests can open a provider page', function () {
    $provider = Provider::factory()->published()->create();

    $this->assertGuest();
    $this->get(route('providers.show', $provider->slug))->assertOk();
});

test('an unpublished provider gives a 404', function () {
    $provider = Provider::factory()->create(['is_published' => false]);

    $this->get(route('providers.show', $provider->slug))->assertNotFound();
});

test('a missing slug gives a 404', function () {
    $this->get(route('providers.show', 'non-esiste'))->assertNotFound();
});

test('inactive services are not shown', function () {
    $provider = Provider::factory()->published()->create();
    $category = Category::factory()->create();
    Service::factory()->for($provider)->for($category)->create(['name' => 'Attivo']);
    Service::factory()->inactive()->for($provider)->for($category)->create(['name' => 'Disattivato']);

    $groups = providerPageProps($provider)['service_groups'];

    expect($groups)->toHaveCount(1);
    expect(array_column($groups[0]['services'], 'name'))->toBe(['Attivo']);
});

test('a provider without active services has no service groups', function () {
    $provider = Provider::factory()->published()->create();
    Service::factory()->inactive()->for($provider)->create();

    expect(providerPageProps($provider)['service_groups'])->toBe([]);
});

test('services are grouped by category, in alphabetical order', function () {
    $provider = Provider::factory()->published()->create();
    $nails = Category::factory()->create(['name' => 'Nails']);
    $braids = Category::factory()->create(['name' => 'Treccine']);
    Service::factory()->for($provider)->for($braids)->create(['name' => 'Box braids']);
    Service::factory()->for($provider)->for($nails)->create(['name' => 'Semipermanente']);
    Service::factory()->for($provider)->for($braids)->create(['name' => 'Cornrows']);

    $groups = providerPageProps($provider)['service_groups'];

    expect(array_map(fn ($group) => $group['category']['name'], $groups))->toBe(['Nails', 'Treccine']);
    expect(array_column($groups[1]['services'], 'name'))->toBe(['Box braids', 'Cornrows']);
});

test('options are listed under their own service', function () {
    $provider = Provider::factory()->published()->create();
    $category = Category::factory()->create();
    $braids = Service::factory()->for($provider)->for($category)->create(['name' => 'Box braids']);
    $cornrows = Service::factory()->for($provider)->for($category)->create(['name' => 'Cornrows']);
    ServiceOption::factory()->for($braids)->create(['name' => 'Capelli lunghi', 'extra_price' => 20, 'extra_duration' => 60]);
    ServiceOption::factory()->for($cornrows)->create(['name' => 'Perline', 'extra_price' => 10, 'extra_duration' => 0]);

    $services = providerPageProps($provider)['service_groups'][0]['services'];

    expect($services[0]['name'])->toBe('Box braids');
    expect($services[0]['options'])->toBe([
        ['id' => $braids->options()->first()->id, 'name' => 'Capelli lunghi', 'extra_price' => '20.00', 'extra_duration' => 60],
    ]);
    expect(array_column($services[1]['options'], 'name'))->toBe(['Perline']);
    expect($services[1]['options'][0]['extra_duration'])->toBe(0);
});

test('a service has its price and its duration range or fixed duration', function () {
    $provider = Provider::factory()->published()->create();
    $category = Category::factory()->create();
    Service::factory()->for($provider)->for($category)->create([
        'name' => 'A treccine', 'price' => 90, 'duration_min' => 180, 'duration_max' => 360,
    ]);
    Service::factory()->for($provider)->for($category)->create([
        'name' => 'B taglio', 'price' => 18.5, 'duration_min' => 30, 'duration_max' => 30,
    ]);

    [$range, $fixed] = providerPageProps($provider)['service_groups'][0]['services'];

    expect($range)->toMatchArray(['price' => '90.00', 'duration_min' => 180, 'duration_max' => 360]);
    expect($fixed)->toMatchArray(['price' => '18.50', 'duration_min' => 30, 'duration_max' => 30]);
});

test('opening hours list the seven days from Monday to Sunday, with every slot and closed days', function () {
    $provider = Provider::factory()->published()->create();
    // Created out of order, to check the sorting.
    Availability::factory()->for($provider)->create(['weekday' => Weekday::Tuesday, 'start_time' => '15:00:00', 'end_time' => '19:30:00']);
    Availability::factory()->for($provider)->create(['weekday' => Weekday::Sunday, 'start_time' => '10:00:00', 'end_time' => '12:00:00']);
    Availability::factory()->for($provider)->create(['weekday' => Weekday::Tuesday, 'start_time' => '09:00:00', 'end_time' => '13:00:00']);

    $hours = providerPageProps($provider)['opening_hours'];

    expect(array_column($hours, 'weekday'))->toBe([1, 2, 3, 4, 5, 6, 7]);
    expect(array_column($hours, 'day'))->toBe(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
    expect($hours[0]['slots'])->toBe([]);
    // Local times as stored, without seconds and without any time zone conversion.
    expect($hours[1]['slots'])->toBe([
        ['start' => '09:00', 'end' => '13:00'],
        ['start' => '15:00', 'end' => '19:30'],
    ]);
    expect($hours[6]['slots'])->toBe([['start' => '10:00', 'end' => '12:00']]);
});

test('the WhatsApp link is built only for an international number', function (?string $stored, ?array $expected) {
    $provider = Provider::factory()->published()->create(['whatsapp' => $stored]);

    expect(providerPageProps($provider)['contacts']['whatsapp'])->toBe($expected);
})->with([
    'with +' => ['+39 333 000 0001', ['number' => '+39 333 000 0001', 'url' => 'https://wa.me/393330000001']],
    'with 00' => ['0039 333-000-0001', ['number' => '0039 333-000-0001', 'url' => 'https://wa.me/393330000001']],
    'national, no prefix guessed' => ['333 000 0001', ['number' => '333 000 0001', 'url' => null]],
    'only the prefix' => ['+', ['number' => '+', 'url' => null]],
    'empty' => ['', null],
    'missing' => [null, null],
]);

test('the Instagram link is built only for a valid username', function (?string $stored, ?array $expected) {
    $provider = Provider::factory()->published()->create(['instagram' => $stored]);

    expect(providerPageProps($provider)['contacts']['instagram'])->toBe($expected);
})->with([
    'plain' => ['trecce.sahel', ['username' => 'trecce.sahel', 'url' => 'https://instagram.com/trecce.sahel']],
    'leading @' => ['@under_score.99', ['username' => 'under_score.99', 'url' => 'https://instagram.com/under_score.99']],
    'two @' => ['@@doppio', ['username' => '@doppio', 'url' => null]],
    'spaces' => ['nome con spazi', ['username' => 'nome con spazi', 'url' => null]],
    'script' => ['javascript:alert(1)', ['username' => 'javascript:alert(1)', 'url' => null]],
    'path' => ['nome/../altro', ['username' => 'nome/../altro', 'url' => null]],
    'only @' => ['@', null],
    'missing' => [null, null],
]);

test('the page does not expose internal fields', function () {
    $provider = Provider::factory()->published()->create();
    $service = Service::factory()->for($provider)->create();
    ServiceOption::factory()->for($service)->create();
    Availability::factory()->for($provider)->create();

    $props = providerPageProps($provider);

    expect($props)->not->toHaveKeys(['id', 'user_id', 'is_published', 'avatar_path', 'whatsapp', 'instagram', 'created_at', 'updated_at']);
    expect($props['service_groups'][0]['services'][0])->not->toHaveKeys(['provider_id', 'category_id', 'is_active', 'created_at']);
    expect($props['service_groups'][0]['services'][0]['options'][0])->not->toHaveKeys(['service_id', 'created_at']);
    expect($props['opening_hours'][0])->not->toHaveKeys(['provider_id', 'start_time', 'end_time']);
});

test('a card of the list points to the provider page', function () {
    $provider = Provider::factory()->published()->create(['slug' => 'barberia-lagos']);

    $this->get(route('providers.index'))
        ->assertInertia(fn (Assert $page) => $page->where('providers.data.0.slug', 'barberia-lagos'));

    $this->get(route('providers.show', 'barberia-lagos'))->assertOk();
});

test('the number of queries does not grow with services, options and availabilities', function () {
    $countQueries = function (Provider $provider): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('providers.show', $provider->slug))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $small = Provider::factory()->published()->create();
    ServiceOption::factory()->for(Service::factory()->for($small))->create();
    Availability::factory()->for($small)->create();

    $large = Provider::factory()->published()->create();
    foreach (range(1, 4) as $index) {
        ServiceOption::factory()->count(3)->for(Service::factory()->for($large))->create();
    }
    Availability::factory()->count(5)->for($large)->create();

    expect($countQueries($large))->toBe($countQueries($small));
});
