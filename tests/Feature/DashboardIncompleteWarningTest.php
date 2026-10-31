<?php

use App\Models\Availability;
use App\Models\Provider;
use App\Models\Service;
use Inertia\Testing\AssertableInertia as Assert;

function diwSource(): string
{
    return (string) file_get_contents(resource_path('js/pages/dashboard.tsx'));
}

test('a published provider gets what is missing, as an unpublished one', function (bool $service, bool $inactiveService, bool $availability, array $missing) {
    $provider = Provider::factory()->create(['name' => 'Salone Awa', 'city' => 'Bologna', 'is_published' => true]);
    if ($service) {
        Service::factory()->for($provider)->create();
    }
    if ($inactiveService) {
        Service::factory()->for($provider)->inactive()->create();
    }
    if ($availability) {
        Availability::factory()->for($provider)->create();
    }

    $this->actingAs($provider->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('provider.is_published', true)
            ->where('publishing', ['can_publish' => $missing === [], 'missing' => $missing]));
})->with([
    'no services, no availabilities' => [false, false, false, ['service', 'availability']],
    'a service, no availabilities' => [true, false, false, ['availability']],
    'only an inactive service' => [false, true, true, ['service']],
    'complete' => [true, false, true, []],
]);

// The page has no JavaScript test runner: these tests read the source. They break on a rename or a
// different shape of the JSX even when the behaviour is right, and they do not prove rendering.

test('the warning is shown only for a published profile, above the unpublish button', function () {
    $source = diwSource();

    expect($source)->toMatch('/\{provider\.is_published \? \(\s*<>\s*<IncompleteWarning publishing=\{publishing\} \/>\s*<UnpublishButton \/>\s*<\/>\s*\) : \(\s*<PublishSection\b/');
    expect(substr_count($source, '<IncompleteWarning'))->toBe(1);
    expect(substr_count($source, '<UnpublishButton'))->toBe(1);
});

test('the warning is a static notice, with the list only for known codes', function () {
    preg_match('/function IncompleteWarning\(.*?\n}\n/s', diwSource(), $match);
    $component = $match[0] ?? '';

    expect($component)
        ->toContain('if (publishing.missing.length === 0) {')
        ->toContain('publishing.missing.filter(isMissingCode)')
        ->toMatch('/<Alert\s+variant="destructive"\s+role="status"/')
        ->not->toContain('role="alert"')
        // With only unknown codes: neither the title nor the list, and not the "cannot publish" text.
        ->toMatch('/\{missing\.length > 0 && \(\s*(?:\/\/[^\n]*\n\s*)?<AlertTitle[^>]*>\s*\{t\(\'provider\.publish\.published_incomplete\'\)\}/')
        ->toMatch('/\{missing\.length > 0 && \(\s*<ul\b/')
        ->toContain("{t('provider.publish.published_incomplete_help')}")
        ->not->toContain('provider.publish.blocked');
});

test('the Italian texts are exactly the expected ones', function () {
    expect(__('provider.publish.published_incomplete'))->toBe('Il tuo profilo è pubblicato, ma i clienti non possono ancora prenotarti: ti manca ancora:');
    expect(__('provider.publish.published_incomplete_help'))->toBe('Completa quello che manca oppure togli il profilo dalla pubblicazione.');
});
