<?php

use App\Models\Provider;
use Inertia\Testing\AssertableInertia as Assert;

// The profile page made shorter (#53, docs/accessibility.md « Pagina del profilo »): two fields side
// by side from sm, an index of the sections below xl, two columns from xl (the gallery on the
// right), nothing that stays in view. The pages are read in their source.

function plySource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

const PLY_PAGE = 'js/pages/provider/edit.tsx';
const PLY_FIELDS = 'js/components/provider-profile-fields.tsx';

// ---------------------------------------------------------------------------------------------
// The fields
// ---------------------------------------------------------------------------------------------

test('name and city, WhatsApp and Instagram side by side from sm, in the order of the document', function () {
    $fields = plySource(PLY_FIELDS);

    expect($fields)->toContain("export const FIELD_PAIR = 'grid gap-6 sm:grid-cols-2';")
        ->and(substr_count($fields, '<div className={FIELD_PAIR}>'))->toBe(2)
        // Never two columns on a phone: only with sm:.
        ->and($fields)->not->toMatch('/(?<!sm:)grid-cols-2/');

    // The order of the fields, and so of Tab, is the one of before: name, city, bio, WhatsApp,
    // Instagram.
    $positions = array_map(fn (string $id) => (int) strpos($fields, "htmlFor=\"{$id}\""), ['name', 'city', 'bio', 'whatsapp', 'instagram']);
    $sorted = $positions;
    sort($sorted);
    expect($positions)->toBe($sorted)->and(min($positions))->toBeGreaterThan(0);

    // Each pair in its own row: name and city in the first, WhatsApp and Instagram in the second.
    $first = (int) strpos($fields, '<div className={FIELD_PAIR}>');
    $second = (int) strpos($fields, '<div className={FIELD_PAIR}>', $first + 1);
    expect($positions[0])->toBeGreaterThan($first)
        ->and($positions[1])->toBeLessThan($positions[2])
        ->and($positions[2])->toBeLessThan($second)
        ->and($positions[3])->toBeGreaterThan($second);
    // The creation page uses the same fields.
    expect(plySource('js/pages/provider/setup.tsx'))->toContain('<ProviderProfileFields errors={errors} />');
});

// ---------------------------------------------------------------------------------------------
// The columns
// ---------------------------------------------------------------------------------------------

test('from xl two columns with the widths of add-panel.tsx, nothing sticky, fixed or scrolling on its own', function () {
    $page = plySource(PLY_PAGE);

    expect($page)
        ->toMatch("/import \\{\\s*CARD_TITLE_FOCUS,\\s*LIST_COLUMN,\\s*TWO_COLUMNS,\\s*\\} from '@\\/components\\/add-panel';/")
        ->toMatch('/<div className=\{TWO_COLUMNS\}>\s*<div className=\{LIST_COLUMN\}>\s*<Card className="max-w-2xl">/')
        ->not->toMatch('/\bsticky\b|["\'` ]fixed[ "\'`]|max-h-|overflow-y-|\bxl:grid\b/')
        ->not->toMatch('/aria-label(?!ledby)/');
    // The grid of the two columns is the one of the services and hours: xl only.
    expect((string) file_get_contents(resource_path('js/components/add-panel.tsx')))
        ->toContain("'flex flex-col gap-6 xl:grid xl:grid-cols-[minmax(0,42rem)_24rem] xl:items-start xl:justify-start'");
});

test('the gallery is the last of the content, the footer of the guide is outside the columns', function () {
    $page = plySource(PLY_PAGE);

    // The left column closes after the photo of the profile, the columns after the gallery, then the
    // footer, then the page.
    expect($page)->toMatch('/<AvatarForm[\s\S]*?\/>\s*<\/div>\s*<GalleryForm[\s\S]*?\/>\s*<\/div>\s*<SetupStepsFooter page="business" \/>\s*<\/div>/');
    $columns = (int) strpos($page, '<div className={TWO_COLUMNS}>');
    expect((int) strpos($page, '<SetupStepsNav page="business" />'))->toBeLessThan($columns)
        ->and((int) strpos($page, '<ProfileIndex />'))->toBeLessThan($columns)
        ->and((int) strpos($page, '<SetupStepsNav page="business" />'))->toBeLessThan((int) strpos($page, '<ProfileIndex />'));
});

// ---------------------------------------------------------------------------------------------
// The index
// ---------------------------------------------------------------------------------------------

test('below xl an index of the sections: plain links, the focus on the title, no "#" in the address, 44 px', function () {
    $page = plySource(PLY_PAGE);

    expect($page)
        ->toMatch('/<nav\s+aria-labelledby="profile-index-title"\s+className="max-w-2xl xl:hidden"\s*>/')
        ->toMatch("/<p id=\"profile-index-title\" className=\"text-sm font-semibold\">\\s*\\{t\\('provider\\.edit\\.index'\\)\\}/")
        ->toMatch('/href=\{`#\$\{section\.id\}`\}\s*onClick=\{\(event\) => \{\s*event\.preventDefault\(\);\s*document\.getElementById\(section\.id\)\?\.focus\(\);/')
        ->toContain('className={`text-sm underline underline-offset-4 ${touchTarget}`}')
        // The four sections, in the order of the page.
        ->toMatch("/\\{ id: PAGE_TITLE_ID, label: t\\('provider\\.edit\\.title'\\) \\},\\s*\\{ id: ADDRESS_TITLE_ID, label: t\\('provider\\.edit\\.address_title'\\) \\},\\s*\\{ id: AVATAR_TITLE_ID, label: t\\('provider\\.avatar\\.title'\\) \\},\\s*\\{ id: GALLERY_TITLE_ID, label: t\\('provider\\.gallery\\.title'\\) \\},/");
});

test('the titles the index leads to: reached by script only, without a ring', function () {
    $page = plySource(PLY_PAGE);

    expect($page)
        ->toMatch("/<h2\\s+id=\\{ADDRESS_TITLE_ID\\}\\s+tabIndex=\\{-1\\}\\s+className=\\{`text-lg font-semibold \\\$\\{CARD_TITLE_FOCUS\\}`\\}\\s*>\\s*\\{t\\(\\s*'provider\\.edit\\.address_title',?\\s*\\)\\}/")
        ->toMatch('/<h2\\s+id=\\{AVATAR_TITLE_ID\\}\\s+tabIndex=\\{-1\\}\\s+className=\\{`text-xl leading-none font-semibold \\$\\{CARD_TITLE_FOCUS\\}`\\}/')
        ->toMatch('/<h1\s+id=\{PAGE_TITLE_ID\}\s+tabIndex=\{-1\}/')
        ->toContain('TITLE_ID as GALLERY_TITLE_ID,')
        // The address title comes right before the address field.
        ->toMatch("/\\{t\\(\\s*'provider\\.edit\\.address_title',?\\s*\\)\\}\\s*<\\/h2>\\s*<div className=\"grid gap-2\">\\s*<Label htmlFor=\"slug\">/");
    expect(plySource('js/components/gallery-form.tsx'))->toContain("export const TITLE_ID = 'gallery-title';");
});

test('the profile page still answers, for a published and an unpublished provider', function () {
    foreach ([true, false] as $published) {
        $provider = Provider::factory()->create(['is_published' => $published]);

        $this->actingAs($provider->user)->get(route('provider.edit'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('provider/edit'));
    }
});

test('the texts in the three languages, with the French spaces', function () {
    $nb = "\u{00A0}";
    $expected = [
        'it' => ['provider.edit.address_title' => 'Indirizzo della tua pagina', 'provider.edit.index' => 'In questa pagina', 'setup.welcome' => 'Benvenuto: quattro passi e la tua pagina è online.'],
        'fr' => ['provider.edit.address_title' => 'Adresse de votre page', 'provider.edit.index' => 'Sur cette page', 'setup.welcome' => "Bienvenue{$nb}: quatre étapes et votre page est en ligne."],
        'en' => ['provider.edit.address_title' => 'Address of your page', 'provider.edit.index' => 'On this page', 'setup.welcome' => 'Welcome: four steps and your page is online.'],
    ];

    foreach ($expected as $locale => $texts) {
        $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
        foreach ($texts as $key => $text) {
            expect($json[$key] ?? null)->toBe($text, "{$locale} {$key}");
            if ($locale === 'fr') {
                expect(preg_match('/ [:?!;](?![a-z_])/', $json[$key]))->toBe(0, "fr {$key}");
            }
        }
    }
});
