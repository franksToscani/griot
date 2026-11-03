<?php

use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

// Errors that screen readers find and announce (issue #55, phase 2): the booking forms, the login
// pages and the errors of the provider's bookings list. There is no JavaScript test runner: the
// helper runs in Node, the rest is read in the sources.

/**
 * The forms of this phase: source file => the id of its <Form>.
 *
 * @return array<string, string>
 */
function aceForms(): array
{
    return [
        'js/components/booking-sheet.tsx' => 'booking-form',
        'js/pages/provider/bookings/create.tsx' => 'provider-booking-form',
        'js/pages/auth/login.tsx' => 'login-form',
        'js/pages/auth/register.tsx' => 'register-form',
        'js/pages/auth/forgot-password.tsx' => 'forgot-password-form',
        'js/pages/auth/reset-password.tsx' => 'reset-password-form',
        'js/pages/auth/confirm-password.tsx' => 'confirm-password-form',
    ];
}

/**
 * The sources whose fields and errors are checked: the forms and the shared booking fields.
 *
 * @return list<string>
 */
function aceSources(): array
{
    return [...array_keys(aceForms()), 'js/components/booking-fields.tsx'];
}

function aceSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * Run resources/js/lib/form-a11y.ts with Node, as the browser would, and give back what it printed.
 *
 * @return array<string, mixed>
 */
function aceRunHelper(string $calls): array
{
    $script = sys_get_temp_dir().'/griot-form-a11y-'.bin2hex(random_bytes(6)).'.mts';
    $helper = resource_path('js/lib/form-a11y.ts');
    file_put_contents($script, "import * as f from '{$helper}';\nconsole.log(JSON.stringify({$calls}));\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------------------------
// The helper and InputError
// ---------------------------------------------------------------------------------------------

test('fieldA11y marks a field invalid only with an error, and lists the help before the error', function () {
    $result = aceRunHelper("{
        none: f.fieldA11y('x'),
        error: f.fieldA11y('x', 'Errore'),
        help: f.fieldA11y('x', undefined, 'x-help'),
        both: f.fieldA11y('x', 'Errore', 'x-help'),
        emptyError: f.fieldA11y('x', '', 'x-help'),
        id: f.errorId('customer_phone'),
    }");

    expect($result)->toBe([
        'none' => [],
        'error' => ['aria-invalid' => true, 'aria-describedby' => 'x-error'],
        'help' => ['aria-describedby' => 'x-help'],
        'both' => ['aria-invalid' => true, 'aria-describedby' => 'x-help x-error'],
        'emptyError' => ['aria-describedby' => 'x-help'],
        'id' => 'customer_phone-error',
    ]);
});

test('after a refused submit the focus goes to the first invalid field of that form', function () {
    expect(aceSource('js/lib/form-a11y.ts'))
        ->toMatch('/export function focusFirstInvalid\(formId: string\): void \{\s*requestAnimationFrame\(\(\) => \{\s*document\s*\.querySelector<HTMLElement>\(`#\$\{formId\} \[aria-invalid="true"\]`\)\s*\?\.focus\(\);/');
});

test('InputError is read with its field, and only an error without a field is an alert', function () {
    expect(aceSource('js/components/input-error.tsx'))
        ->toContain("role={announce ? 'alert' : undefined}")
        ->toContain('announce = false,')
        ->toContain('{...props}');
});

// ---------------------------------------------------------------------------------------------
// Every form of this phase
// ---------------------------------------------------------------------------------------------

test('every form has its id and moves the focus to its first invalid field on an error', function (string $path, string $formId) {
    $source = aceSource($path);

    expect($source)
        ->toContain("id=\"{$formId}\"")
        ->toContain("focusFirstInvalid('{$formId}')");
})->with(fn () => collect(aceForms())->map(fn (string $id, string $path) => [$path, $id])->values()->all());

test('every error of a field has its id, and its field points to it', function (string $path) {
    $source = aceSource($path);
    preg_match_all('/<InputError\b[\s\S]*?\/>/', $source, $errors);

    expect($errors[0])->not->toBeEmpty();

    foreach ($errors[0] as $error) {
        // An error without a field of its own is announced instead (role="alert"). The free times
        // that could not be loaded are read by the status line of the times (role="status").
        if (str_contains($error, 'announce') || ($path === 'js/components/booking-fields.tsx' && $error === '<InputError message={error} />')) {
            continue;
        }

        expect(preg_match("/id=\\{errorId\\('([a-z_-]+)'\\)\\}/", $error, $id))->toBe(1, "An InputError without its id in {$path}: {$error}");
        // The field: fieldA11y('x', …) or, for a group of checkboxes, errorId('x') a second time.
        $linked = preg_match("/fieldA11y\\(\\s*'{$id[1]}'/", $source) === 1 || substr_count($source, "errorId('{$id[1]}')") > 1;
        expect($linked)->toBeTrue("No field points to the error {$id[1]} in {$path}");
    }
})->with(fn () => aceSources());

test('the phone keeps its help and adds its error in aria-describedby', function () {
    expect(aceSource('js/components/booking-fields.tsx'))
        ->toMatch("/\\{\\.\\.\\.fieldA11y\\(\\s*'customer_phone',\\s*errors\\.customer_phone,\\s*'customer_phone-help',?\\s*\\)\\}/");
    expect(aceSource('js/pages/provider/bookings/create.tsx'))
        ->toMatch("/\\{\\.\\.\\.fieldA11y\\('customer_locale', error, 'customer_locale-help'\\)\\}/");
});

// ---------------------------------------------------------------------------------------------
// The time of the booking
// ---------------------------------------------------------------------------------------------

test('no submit button is disabled for a missing time: only while sending', function (string $path) {
    $source = aceSource($path);

    expect($source)->not->toContain("startsAt === '' ||")
        ->toMatch('/<Button\s+type="submit"\s+disabled=\{processing\}/');
})->with(['js/components/booking-sheet.tsx', 'js/pages/provider/bookings/create.tsx']);

test('the browser stops a booking without a time, with the translated message on the day', function () {
    $source = aceSource('js/components/booking-fields.tsx');

    expect($source)
        ->toContain("const noTime = t('booking.validation.starts_at_required');")
        ->toMatch("/dayRef\\.current\\?\\.setCustomValidity\\(startsAt === '' \\? noTime : ''\\);/")
        ->toContain('onInvalid={() => setMissingTime(true)}')
        // The server's error first, the browser's while no time is chosen; both on the day field.
        ->toMatch("/const timeError =\\s*startsAtError \\?\\? \\(missingTime && startsAt === '' \\? noTime : undefined\\);/")
        ->toContain("{...fieldA11y('booking-day', timeError)}")
        ->toContain("<InputError id={errorId('booking-day')} message={timeError} />");
    expect(__('booking.validation.starts_at_required', [], 'it'))->toBe('Scegli un orario.')
        ->and(__('booking.validation.starts_at_required', [], 'fr'))->toBe('Choisissez un créneau.')
        ->and(__('booking.validation.starts_at_required', [], 'en'))->toBe('Choose a time slot.');
});

test('the loading and the result of the free times are announced', function () {
    expect(aceSource('js/components/booking-fields.tsx'))
        ->toMatch('/<p role="status" className="sr-only">\s*\{status\}\s*<\/p>/')
        ->toContain("t('booking.form.slots_loading')")
        ->toContain("t('booking.form.no_slots')")
        ->toContain("t('booking.form.slots_found', { count: slots.length })");
    expect(__('booking.form.slots_found', ['count' => 3], 'it'))->toBe('Orari disponibili: 3.')
        ->and(__('booking.form.slots_found', ['count' => 3], 'fr'))->toBe("Créneaux disponibles\u{00A0}: 3.")
        ->and(__('booking.form.slots_found', ['count' => 3], 'en'))->toBe('Available time slots: 3.');
});

test('the errors without a field of the customer\'s form are announced', function () {
    expect(aceSource('js/components/booking-sheet.tsx'))
        ->toContain('<InputError message={errors.service} announce />')
        ->toContain('<InputError message={errors.throttle} announce />');
});

// ---------------------------------------------------------------------------------------------
// The provider's bookings list
// ---------------------------------------------------------------------------------------------

test('a refused action is announced and brought into view, the focus stays on the action', function () {
    expect(aceSource('js/pages/provider/bookings/index.tsx'))
        ->toMatch('/<div ref=\{actionErrors\} className="max-w-2xl">\s*<InputError message=\{errors\.booking\} announce \/>\s*<InputError message=\{errors\.services\} announce \/>/')
        ->toMatch("/if \\(errors\\.booking \\|\\| errors\\.services\\) \\{\\s*actionErrors\\.current\\?\\.scrollIntoView\\(\\{ block: 'center' \\}\\);/");
});

// ---------------------------------------------------------------------------------------------
// The server, unchanged: the safety net
// ---------------------------------------------------------------------------------------------

test('the public form without a time, or with a wrong phone, gets the same errors as before', function () {
    app()->setLocale('it');
    $provider = Provider::factory()->published()->create();
    $service = Service::factory()->for($provider)->create();

    $this->from(route('providers.show', $provider->slug))
        ->post(route('providers.bookings.store', $provider->slug), ['service' => $service->id, 'customer_name' => 'Awa Diop', 'customer_phone' => 'abc'])
        ->assertRedirect(route('providers.show', $provider->slug))
        ->assertSessionHasErrors([
            'starts_at' => 'Scegli un orario.',
            'customer_phone' => __('booking.phone.invalid'),
        ]);

    expect(Booking::query()->count())->toBe(0);
});

test('the provider\'s form without a time, or with a wrong phone, gets the same errors as before', function () {
    app()->setLocale('it');
    $this->travelTo(CarbonImmutable::parse('2026-10-19 07:00', 'UTC'));
    $provider = Provider::factory()->published()->create();
    Availability::factory()->for($provider)->create(['weekday' => Weekday::Monday, 'start_time' => '09:00:00', 'end_time' => '13:00:00']);
    $service = Service::factory()->for($provider)->create();

    $this->actingAs($provider->user)
        ->from(route('provider.bookings.create'))
        ->post(route('provider.bookings.store'), ['service' => $service->id, 'customer_name' => 'Awa Diop', 'customer_phone' => 'abc'])
        ->assertRedirect(route('provider.bookings.create'))
        ->assertSessionHasErrors([
            'starts_at' => 'Scegli un orario.',
            'customer_phone' => __('booking.phone.invalid'),
        ]);

    expect(Booking::query()->count())->toBe(0);
});
