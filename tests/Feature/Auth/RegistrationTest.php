<?php

use Laravel\Fortify\Features;

// Nobody registers by themselves (issue #58, docs/production.md): the providers are created by
// "php artisan provider:create" (CreateProviderCommand). Before #58 these two tests showed the
// page and created an account; now they check that neither exists.

test('there is no registration screen: no route, a 404 at its old address', function () {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse()
        ->and(Features::enabled(Features::registration()))->toBeFalse();

    $this->get('/register')->assertNotFound();
    expect(file_exists(resource_path('js/pages/auth/register.tsx')))->toBeFalse();
});

test('nobody can create an account by posting the old form', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'test@example.test']);
});
