<?php

use App\Profile\ProviderSlug;

test('the slug comes from the name', function (string $name, string $slug) {
    expect(ProviderSlug::fromName($name))->toBe($slug);
})->with([
    'words' => ['Barberia Lagos', 'barberia-lagos'],
    'numbers' => ['Studio 54', 'studio-54'],
    'accents' => ['Atelier Wàx è Bello', 'atelier-wax-e-bello'],
    'more accents' => ['Café Zoé', 'cafe-zoe'],
    'symbols and spaces' => ['  Trecce &  Co.  ', 'trecce-co'],
    'only symbols' => ['!!!', 'provider'],
    'only emoji' => ['😀😀', 'provider'],
]);

test('a long name gives at most 100 characters, without a final dash', function () {
    $slug = ProviderSlug::fromName(str_repeat('a', 200));

    expect($slug)->toBe(str_repeat('a', 100));
    expect(strlen($slug))->toBeLessThanOrEqual(100);
});

test('a dash right after the cut is removed', function () {
    // "aaa…a-b": the 100th character is the dash.
    $slug = ProviderSlug::fromName(str_repeat('a', 99).' b');

    expect($slug)->toBe(str_repeat('a', 99));
    expect($slug)->not->toEndWith('-');
});
