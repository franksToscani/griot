<?php

use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use Illuminate\Database\QueryException;

test('a service can be created with the factory', function () {
    $service = Service::factory()->create();

    expect($service->exists)->toBeTrue();
    expect($service->duration_max)->toBeGreaterThanOrEqual($service->duration_min);
    $this->assertDatabaseHas('services', ['id' => $service->id]);
});

test('a service is active by default', function () {
    $provider = Provider::factory()->create();
    $category = Category::factory()->create();

    $service = $provider->services()->create([
        'category_id' => $category->id,
        'name' => 'Treccine',
        'price' => 80,
        'duration_min' => 180,
        'duration_max' => 300,
    ]);

    expect($service->refresh()->is_active)->toBeTrue();
});

test('a service price keeps two decimals', function () {
    $service = Service::factory()->create(['price' => 35.5]);

    expect($service->refresh()->price)->toBe('35.50');
});

test('a service belongs to a provider', function () {
    $provider = Provider::factory()->create();
    $service = Service::factory()->for($provider)->create();

    expect($service->provider->is($provider))->toBeTrue();
});

test('a provider has many services', function () {
    $provider = Provider::factory()->create();
    Service::factory()->count(2)->for($provider)->create();

    expect($provider->services)->toHaveCount(2);
});

test('a service belongs to a category', function () {
    $category = Category::factory()->create();
    $service = Service::factory()->for($category)->create();

    expect($service->category->is($category))->toBeTrue();
});

test('a category has many services', function () {
    $category = Category::factory()->create();
    Service::factory()->count(2)->for($category)->create();

    expect($category->services)->toHaveCount(2);
});

test('deleting a provider deletes their services', function () {
    $service = Service::factory()->create();

    $service->provider->delete();

    $this->assertModelMissing($service);
});

test('a category with services cannot be deleted', function () {
    $service = Service::factory()->create();

    expect(fn () => $service->category->delete())
        ->toThrow(QueryException::class);

    $this->assertModelExists($service->category);
});
