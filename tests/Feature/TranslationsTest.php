<?php

use Inertia\Testing\AssertableInertia as Assert;

test('inertia shares the current locale and its translations', function () {
    app()->setLocale('it');

    $this->get('/')->assertInertia(function (Assert $page) {
        $page->where('locale', 'it');

        // The keys contain dots, so they are read from the array directly.
        $translations = $page->toArray()['props']['translations'];

        expect($translations)->toHaveKey('providers.list.title', 'Trova un professionista');
    });
});

test('a locale without a translation file shares no translations', function () {
    app()->setLocale('xx');

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('locale', 'xx')
        ->where('translations', []));
});
