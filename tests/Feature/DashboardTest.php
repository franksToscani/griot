<?php

use App\Models\Provider;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users without a provider are sent to the setup page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('provider.setup'));
});

test('authenticated users with a provider can visit the dashboard', function () {
    $provider = Provider::factory()->create();
    $this->actingAs($provider->user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});
