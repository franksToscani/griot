<?php

use App\Models\Service;
use App\Models\ServiceOption;

test('a service option can be created with the factory', function () {
    $option = ServiceOption::factory()->create();

    expect($option->exists)->toBeTrue();
    $this->assertDatabaseHas('service_options', ['id' => $option->id]);
});

test('a service option adds no price or time by default', function () {
    $service = Service::factory()->create();

    $option = $service->options()->create(['name' => 'Capelli lunghi']);

    $option->refresh();
    expect($option->extra_price)->toBe('0.00');
    expect($option->extra_duration)->toBe(0);
});

test('a service option belongs to a service', function () {
    $service = Service::factory()->create();
    $option = ServiceOption::factory()->for($service)->create();

    expect($option->service->is($service))->toBeTrue();
});

test('a service has many options', function () {
    $service = Service::factory()->create();
    ServiceOption::factory()->count(2)->for($service)->create();

    expect($service->options)->toHaveCount(2);
});

test('deleting a service deletes its options', function () {
    $option = ServiceOption::factory()->create();

    $option->service->delete();

    $this->assertModelMissing($option);
});
