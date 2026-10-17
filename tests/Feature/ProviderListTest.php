<?php

use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('guests can see the provider list', function () {
    $this->get(route('providers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('providers/index'));
});

test('only published providers are listed', function () {
    $published = Provider::factory()->published()->create();
    Provider::factory()->create();

    $this->get(route('providers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('providers.data', 1)
            ->where('providers.data.0.name', $published->name));
});

test('a provider shows name, city, avatar and categories', function () {
    $provider = Provider::factory()->published()->create(['avatar_path' => null]);
    $category = Category::factory()->create();
    Service::factory()->for($provider)->for($category)->create();

    $this->get(route('providers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('providers.data.0', fn (Assert $card) => $card
                ->where('id', $provider->id)
                ->where('slug', $provider->slug)
                ->where('name', $provider->name)
                ->where('city', $provider->city)
                ->where('avatar_url', null)
                ->has('categories', 1)
                ->where('categories.0.name', $category->name)));
});

test('an inactive service does not add its category', function () {
    $provider = Provider::factory()->published()->create();
    $active = Category::factory()->create();
    $inactive = Category::factory()->create();
    Service::factory()->for($provider)->for($active)->create();
    Service::factory()->inactive()->for($provider)->for($inactive)->create();

    $this->get(route('providers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('providers.data.0.categories', 1)
            ->where('providers.data.0.categories.0.id', $active->id));
});

test('categories are listed without duplicates', function () {
    $provider = Provider::factory()->published()->create();
    $category = Category::factory()->create();
    Service::factory()->count(2)->for($provider)->for($category)->create();

    $this->get(route('providers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('providers.data.0.categories', 1));
});

test('the list is paginated by 12', function () {
    Provider::factory()->count(13)->published()->create();

    $this->get(route('providers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('providers.data', 12)
            ->where('providers.meta.current_page', 1)
            ->where('providers.meta.last_page', 2));

    $this->get(route('providers.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('providers.data', 1)
            ->where('providers.meta.current_page', 2));
});

test('the list is empty when no provider is published', function () {
    Provider::factory()->create();

    $this->get(route('providers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('providers.data', 0));
});

test('the number of queries does not grow with the number of providers', function () {
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('providers.index'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    Service::factory()->for(Provider::factory()->published())->create();
    $withOneProvider = $countQueries();

    Service::factory()->count(5)->for(Provider::factory()->published())->create();
    Service::factory()->count(3)->for(Provider::factory()->published())->create();
    $withThreeProviders = $countQueries();

    expect($withThreeProviders)->toBe($withOneProvider);
});
