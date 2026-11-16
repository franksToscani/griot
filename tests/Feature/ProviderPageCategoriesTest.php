<?php

// Issue #179: on the provider's page the categories are badges at the top only; each group of
// services has its category as an h3 of text, never the same badge again. Read from the source:
// there is no JavaScript runner.

function ppcSource(): string
{
    return (string) file_get_contents(resource_path('js/pages/providers/show.tsx'));
}

test('the badges of the categories stay at the top of the page', function () {
    expect(ppcSource())->toMatch('/\{categories\.map\(\(category\) => \(\s*<li key=\{category\.id\}>\s*<Badge variant="secondary">\s*\{category\.name\}\s*<\/Badge>/');
});

test('each group of services has its category as an h3 of text, without a badge', function () {
    $source = ppcSource();

    expect($source)->toMatch('/<CardTitle>\s*<h3 className="text-lg">\s*\{group\.category\.name\}\s*<\/h3>\s*<\/CardTitle>/')
        ->not->toMatch('/<Badge[^>]*>\s*\{group\.category\.name\}/')
        // One badge with a category name only: the one at the top.
        ->and(preg_match_all('/<Badge[^>]*>\s*\{[a-z.]*category\.name\}/', $source))->toBe(1);
});

test('the headings keep their order: h2 of the services, h3 of the categories, h4 of the services', function () {
    $source = ppcSource();

    $services = strpos($source, "{t('providers.show.services')}");
    $category = strpos($source, '<h3 className="text-lg">');

    expect($services)->toBeInt()->and($category)->toBeInt()
        ->and($services)->toBeLessThan($category)
        ->and($source)->toMatch('/<h4 className="font-medium">\{service\.name\}<\/h4>/')
        ->and(substr_count($source, '<h3'))->toBe(1);
});
