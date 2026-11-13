<?php

use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The home page and the list useful before the first professional (docs/home.md,
// docs/providers-list.md): every category with "In arrivo" for those without anything to book,
// the links to the list, the warm empty state, the header of the private page of a booking,
// "Come funziona" along a line, and the two buttons that stay at the bottom right of the home page.

function huSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

/**
 * @return array<string, string>
 */
function huLang(string $locale): array
{
    return json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The categories of the home page in a language.
 *
 * @return list<array<string, mixed>>
 */
function huCategories(string $locale = 'it'): array
{
    $categories = [];
    test()->withCookie('locale', $locale)->get('/')->assertOk()->assertInertia(function (Assert $page) use (&$categories) {
        $categories = $page->toArray()['props']['categories'];
    });

    return $categories;
}

/**
 * A published provider with an active service in the category.
 */
function huOffer(string $slug): void
{
    Service::factory()->for(Provider::factory()->published())->for(Category::query()->where('slug', $slug)->firstOrFail())->create(['is_active' => true]);
}

/**
 * The card of a category not offered yet: from "if (!card.offered) {" to its "</div>".
 */
function huComingSoonCard(): string
{
    expect(preg_match('/if \(!card\.offered\) \{[\s\S]*?<\/div>/', huSource('components/home/category-card.tsx'), $match))->toBe(1);

    return $match[0];
}

// ---------------------------------------------------------------------------------------------
// The categories
// ---------------------------------------------------------------------------------------------

test('a new site, 7 categories and no professional: every category is there, none offered, by name', function () {
    $this->seed(CategorySeeder::class);

    $categories = huCategories();

    expect($categories)->toHaveCount(7)
        ->and(array_column($categories, 'offered'))->toBe(array_fill(0, 7, false))
        ->and(array_column($categories, 'name'))->toBe(['Barbiere', 'Ciglia', 'Couture', 'Nails', 'Ristorazione', 'Sopracciglia', 'Treccine']);
});

test('with some professionals, the offered categories come first, then the others, each group by name', function () {
    $this->seed(CategorySeeder::class);
    huOffer('treccine');
    huOffer('barbiere');

    $categories = huCategories();

    expect(array_column($categories, 'slug'))->toBe(['barbiere', 'treccine', 'ciglia', 'couture', 'nails', 'ristorazione', 'sopracciglia'])
        ->and(array_column($categories, 'offered'))->toBe([true, true, false, false, false, false, false]);
    // In French the names, and so the order, change; the offered ones stay first.
    expect(array_column(huCategories('fr'), 'name'))->toBe(['Barbier', 'Tresses', 'Cils', 'Couture', 'Ongles', 'Restauration', 'Sourcils']);
});

test('the same queries whether no category or every category is offered', function () {
    $this->seed(CategorySeeder::class);
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('/')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $none = $count();
    foreach (['treccine', 'barbiere', 'nails', 'ciglia', 'sopracciglia', 'couture', 'ristorazione'] as $slug) {
        huOffer($slug);
    }

    // The offered ones are featured only with a photo: none here, so the same queries.
    expect($count())->toBe($none);
    expect((string) file_get_contents(app_path('Http/Controllers/HomeController.php')))->toContain('->withOffered()');
});

test('a category not offered yet: no link, no focus, nothing that moves, and "In arrivo" written', function () {
    $card = huComingSoonCard();

    expect($card)
        ->toMatch('/return \(\s*<div className="flex w-36 flex-col gap-2">\s*<CardImage card=\{card\} \/>/')
        ->toContain("{t('home.categories.coming_soon')}")
        ->not->toMatch('/<Link\b|<a\b|href=|tabIndex|onClick|role=/')
        ->not->toMatch('/CARD_MOTION|hover:|group|focus-visible|touchTarget|cursor-pointer/');
    // The offered card is the link it was.
    expect(huSource('components/home/category-card.tsx'))
        ->toMatch('/<Link\s+href=\{index\(\{ query: \{ category: card\.slug \} \}\)\}/');
    expect(huLang('it')['home.categories.coming_soon'])->toBe('In arrivo')
        ->and(huLang('fr')['home.categories.coming_soon'])->toBe('Bientôt disponible')
        ->and(huLang('en')['home.categories.coming_soon'])->toBe('Coming soon');
});

test('the carousel keeps the offered categories only', function () {
    expect(huSource('pages/home.tsx'))
        ->toMatch('/buildSlides\(\s*t,\s*categories\.filter\(\(category\) => category\.offered\),\s*HOME_PHOTOS,?\s*\)/')
        ->toMatch('/items=\{categoryCards\(categories, HOME_PHOTOS\)\}/');
});

// ---------------------------------------------------------------------------------------------
// The links to the list
// ---------------------------------------------------------------------------------------------

test('"Vedi tutti i professionisti" under the search of the band, a link to the list', function () {
    expect(huSource('components/home/home-hero.tsx'))
        ->toMatch('/<HomeSearch cities=\{cities\} \/>[\s\S]*?<Link href=\{index\(\)\} className=\{cn\(HERO\.link, touchTarget\)\}>\s*\{t\(\'home\.intro\.all_providers\'\)\}\s*<\/Link>/');
    expect(huSource('lib/home-hero.ts'))
        ->toContain("link: 'w-fit underline underline-offset-4 outline-none focus-visible:ring-[3px] focus-visible:ring-hero-foreground focus-visible:outline-hidden',");
});

test('"Trova un professionista" in the header of every public page and in the footer', function () {
    $layout = huSource('layouts/public-layout.tsx');
    preg_match('/<header[\s\S]*<\/header>/', $layout, $header);
    preg_match('/<footer[\s\S]*<\/footer>/', $layout, $footer);

    expect($layout)->toContain("import { index as providersIndex } from '@/routes/providers';");
    expect($header[0] ?? '')
        ->toMatch("/<nav className=\"flex flex-wrap items-center justify-end gap-x-4 gap-y-1 text-sm\">\\s*<Link\\s+href=\\{providersIndex\\(\\)\\}\\s+className=\\{touchTarget\\}\\s*>\\s*\\{t\\('nav\\.find_provider'\\)\\}/");
    expect($footer[0] ?? '')
        ->toMatch("/<Link\\s+href=\\{providersIndex\\(\\)\\}\\s+className=\\{cn\\(\\s*'underline underline-offset-4',\\s*touchTarget,\\s*\\)\\}\\s*>\\s*\\{t\\('nav\\.find_provider'\\)\\}\\s*<\\/Link>/");
    // The header wraps on a narrow phone instead of scrolling sideways.
    expect($header[0] ?? '')->toContain('flex flex-wrap items-center justify-end gap-x-4 gap-y-1');
    foreach (['it' => 'Trova un professionista', 'fr' => 'Trouver un professionnel', 'en' => 'Find a professional'] as $locale => $text) {
        expect(huLang($locale)['nav.find_provider'])->toBe($text);
    }
});

test('"Accedi" is gone from the private page of a booking only, through a property of the layout', function () {
    $app = huSource('app.tsx');
    $layout = huSource('layouts/public-layout.tsx');

    // The first case of the switch: before the general one of bookings/.
    expect($app)
        ->toContain("import PublicLayout, { PublicBookingLayout } from '@/layouts/public-layout';")
        ->toMatch("/switch \\(true\\) \\{\\s*(\\/\\/[^\\n]*\\n\\s*)*case name === 'bookings\\/show':\\s*return PublicBookingLayout;/");
    expect($layout)
        ->toMatch('/export default function PublicLayout\(\{\s*children,\s*account = true,\s*\}/')
        ->toMatch('/\{account &&\s*\(auth\.user \?/')
        ->toMatch('/export function PublicBookingLayout\(\{ children \}: \{ children: ReactNode \}\) \{\s*return <PublicLayout account=\{false\}>\{children\}<\/PublicLayout>;/');
    // Nowhere else is the account hidden.
    expect(substr_count($layout.$app, 'account={false}'))->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// The empty list
// ---------------------------------------------------------------------------------------------

test('the empty list without a search or a filter is warm, in the three languages', function () {
    expect(__('providers.list.empty', [], 'it'))->toBe('I primi professionisti stanno arrivando. Torna a trovarci presto.')
        ->and(__('providers.list.empty', [], 'fr'))->toBe('Les premiers professionnels arrivent. Revenez nous voir bientôt.')
        ->and(__('providers.list.empty', [], 'en'))->toBe('The first professionals are on their way. Come back soon.')
        ->and(__('providers.list.empty_pros', [], 'it'))->toBe('Sei un professionista? Scrivici:')
        ->and(__('providers.list.empty_pros', [], 'fr'))->toBe("Vous êtes un professionnel\u{00A0}? Écrivez-nous\u{00A0}:")
        ->and(__('providers.list.empty_pros', [], 'en'))->toBe('Are you a professional? Write to us:');

    $this->get(route('providers.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('providers.meta.total', 0)
        ->where('translations', fn ($translations) => $translations['providers.list.empty'] === 'I primi professionisti stanno arrivando. Torna a trovarci presto.'));
});

test('the invitation for the professionals: inside the status, only empty without a search or a filter, only with a contact', function () {
    $page = huSource('pages/providers/index.tsx');
    $invite = huSource('components/professionals-invite.tsx');

    expect($page)
        ->toMatch('/<p role="status" className="text-muted-foreground">[\s\S]*?<ProfessionalsInvite\s+empty=\{\s*providers\.meta\.total === 0 &&\s*!filtered &&\s*filters\.q === null\s*\}\s*\/>\s*<\/p>/');
    expect($invite)
        ->toContain('const { contact } = usePage().props;')
        ->toMatch('/if \(!empty \|\| \(contact\.email === null && contact\.whatsapp_url === null\)\) \{\s*return null;\s*\}/')
        ->toMatch('/\{contact\.email !== null && \(\s*<a href=\{`mailto:\$\{contact\.email\}`\}/')
        ->toMatch('/\{contact\.whatsapp_url !== null && \(\s*<a\s+href=\{whatsappLink\(\s*contact\.whatsapp_url,\s*t\(\'home\.pros\.whatsapp_message\'\),?\s*\)\}\s+target="_blank"\s+rel="noopener noreferrer"/')
        ->not->toMatch('/aria-label(?!ledby)/')
        // No address, no number of its own: only the shared prop.
        ->not->toMatch('/[\w.+-]+@[\w-]+\.[a-z]{2,}/i')
        ->not->toMatch('/\+?\d[\d \-]{7,}\d/');
});

test('the list of a new site gets the contacts, or none, from the shared prop', function (?string $email, ?string $number, array $expected) {
    config(['contact.email' => $email, 'contact.whatsapp' => $number]);

    $this->get(route('providers.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('providers.meta.total', 0)
        ->where('contact', $expected));
})->with([
    'with both' => ['info@example.test', '+390000000000', ['email' => 'info@example.test', 'whatsapp_url' => 'https://wa.me/390000000000', 'phone_url' => 'tel:+390000000000']],
    'without' => [null, null, ['email' => null, 'whatsapp_url' => null, 'phone_url' => null]],
]);

// ---------------------------------------------------------------------------------------------
// "Come funziona" along a line
// ---------------------------------------------------------------------------------------------

test('"Come funziona": a row of circles joined by a line from 768 px, a column on phones, never over a text', function () {
    $steps = huSource('components/home/how-it-works.tsx');

    expect($steps)
        ->toContain("export const HOW_IT_WORKS_TITLE_ID = 'home-steps-title';")
        ->toMatch('/<section\s+aria-labelledby=\{HOW_IT_WORKS_TITLE_ID\}/')
        ->toMatch('/<h2\s+id=\{HOW_IT_WORKS_TITLE_ID\}\s+tabIndex=\{-1\}\s+className="text-xl font-semibold outline-none focus-visible:outline-hidden"\s*>/')
        // The horizontal line: from 768 px, decorative, between the centres of the circles.
        ->toMatch('/<span\s+aria-hidden="true"\s+className="absolute top-\[1\.4375rem\] right-\[calc\(\(100%_-_3rem\)\/6\)\] left-\[calc\(\(100%_-_3rem\)\/6\)\] hidden h-0\.5 bg-brand md:block forced-colors:hidden"\s*\/>/')
        // On phones: the column of the circles, the cards beside it; the line goes down between two circles.
        ->toContain('className="reveal-item relative grid grid-cols-[3rem_1fr] items-start gap-4 md:flex md:flex-col md:items-center"')
        ->toMatch('/\{index < STEPS\.length - 1 && \(\s*<span\s+aria-hidden="true"\s+className="absolute top-12 -bottom-6 left-\[calc\(1\.5rem_-_1px\)\] w-0\.5 bg-brand md:hidden forced-colors:hidden"\s*\/>\s*\)\}/')
        // The number in a badge: decorative, it is in the heading.
        ->toMatch('/<span\s+aria-hidden="true"\s+className="flex size-7 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground forced-colors:border"\s*>\s*\{index \+ 1\}\s*<\/span>/')
        ->not->toMatch('/aria-label(?!ledby)/');
    // Nothing moves but the reveal: no transform written by hand (no translate- class).
    expect(withoutMotionSafe($steps))->not->toMatch('/\b(transition|animate-|duration-|translate-)/');
});

test('"Come funziona" keeps its texts: three steps, the same keys', function () {
    foreach (['it', 'fr', 'en'] as $locale) {
        foreach (['choose', 'book', 'confirm'] as $step) {
            expect(huLang($locale))->toHaveKey("home.steps.{$step}.title")->toHaveKey("home.steps.{$step}.text");
        }
    }
    expect(huSource('components/home/how-it-works.tsx'))
        ->toContain("{t('home.steps.title')}")
        ->toContain('{t(`home.steps.${step}.title`)}')
        ->toContain('{t(`home.steps.${step}.text`)}');
});

// ---------------------------------------------------------------------------------------------
// The two buttons at the bottom right of the home page
// ---------------------------------------------------------------------------------------------

test('the arrow and the help are on the home page only', function () {
    expect(huSource('pages/home.tsx'))->toMatch('/<\/div>\s*<HomeFloatingActions \/>\s*<\/>/');

    $pages = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js')));
    $users = [];
    foreach ($pages as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['ts', 'tsx'], true) && str_contains((string) file_get_contents($file->getPathname()), '<HomeFloatingActions')) {
            $users[] = str_replace(resource_path('js').'/', '', $file->getPathname());
        }
    }

    expect($users)->toBe(['pages/home.tsx']);
});

test('the arrow: a sentinel watched by IntersectionObserver, hidden only after the mount, straight to the top', function () {
    $source = huSource('components/home/floating-actions.tsx');

    expect($source)
        // As React draws it the arrow is there; only the observer hides it.
        ->toContain('const [atTop, setAtTop] = useState(false);')
        ->toMatch("/if \\(\\s*element === null \\|\\|\\s*typeof window\\.IntersectionObserver !== 'function'\\s*\\) \\{\\s*return;\\s*\\}/")
        ->toMatch('/new IntersectionObserver\(\(entries\) => \{\s*for \(const entry of entries\) \{\s*setAtTop\(entry\.isIntersecting\);/')
        ->toContain('observer.disconnect();')
        ->toMatch('/<div\s+ref=\{sentinel\}\s+aria-hidden="true"\s+className="pointer-events-none absolute top-0 left-0 h-svh w-px"\s*\/>/')
        ->toMatch('/\{!atTop && \(\s*<Button/')
        // At once to the top, the focus at the start of the page without scrolling again.
        ->toMatch('/window\.scrollTo\(0, 0\);\s*document\s*\.getElementById\(MAIN_CONTENT_ID\)\s*\?\.focus\(\{ preventScroll: true \}\);/')
        ->toContain("<span className=\"sr-only\">{t('home.back_to_top')}</span>")
        // No listener of the scroll, no timer, no smooth scroll.
        ->not->toMatch("/addEventListener\\(\\s*['\"]scroll|onScroll/")
        ->not->toMatch('/\b(setInterval|setTimeout|requestAnimationFrame)\b/')
        ->not->toMatch('/scroll-smooth|scroll-behavior|behavior:\s*[\'"]smooth/');
});

test('the help: the kit\'s Sheet, "?" named by a translated text, each contact only with its value', function () {
    $source = huSource('components/home/floating-actions.tsx');

    expect($source)
        ->toMatch('/<SheetTrigger asChild>\s*<Button type="button" size="icon" className=\{ROUND\}>\s*<span aria-hidden="true" className="text-xl font-semibold">\s*\?\s*<\/span>\s*<span className="sr-only">\{t\(\'home\.help\.open\'\)\}<\/span>/')
        ->toContain("<SheetTitle>{t('home.help.title')}</SheetTitle>")
        ->toContain("<SheetDescription>{t('home.help.text')}</SheetDescription>")
        ->toMatch('/\{contact\.whatsapp_url !== null && \(\s*<Button asChild>\s*<a\s+href=\{whatsappLink\(\s*contact\.whatsapp_url,\s*t\(\'home\.help\.whatsapp_message\'\),?\s*\)\}\s+target="_blank"\s+rel="noopener noreferrer"\s*>\s*\{t\(\'home\.help\.whatsapp\'\)\}\s*<NewTabHint \/>/')
        ->toMatch('/\{contact\.phone_url !== null && \(\s*<Button variant="outline" asChild>\s*<a href=\{contact\.phone_url\}>\s*\{t\(\'home\.help\.call\'\)\}\s*<\/a>/')
        ->toMatch('/\{contact\.email !== null && \(\s*<Button variant="outline" asChild>\s*<a href=\{`mailto:\$\{contact\.email\}`\}>/')
        // Always: "Come funziona" on this page and the list.
        ->toContain("{t('home.steps.title')}")
        ->toMatch("/<Link href=\\{index\\(\\)\\}>\\{t\\('nav\\.find_provider'\\)\\}<\\/Link>/")
        // No form, no opening hours, nothing written by hand.
        ->not->toMatch('/<form\b|<input\b|<textarea\b/')
        ->not->toMatch('/aria-label(?!ledby)/')
        ->not->toMatch('/[\w.+-]+@[\w-]+\.[a-z]{2,}/i')
        ->not->toMatch('/\+?\d[\d \-]{7,}\d|wa\.me\/\d|tel:\+?\d/');
});

test('"Come funziona" in the help closes the panel and puts the focus on the title of the steps', function () {
    expect(huSource('components/home/floating-actions.tsx'))
        ->toMatch('/onClick=\{\(\) => \{\s*goToSteps\.current = true;\s*setOpen\(false\);\s*\}\}/')
        ->toMatch('/onCloseAutoFocus=\{\(event\) => \{\s*if \(!goToSteps\.current\) \{\s*return;\s*\}\s*goToSteps\.current = false;\s*event\.preventDefault\(\);[\s\S]*?document\.getElementById\(HOW_IT_WORKS_TITLE_ID\)\?\.focus\(\);/');
    // The title takes the focus from a script only: tabIndex -1, never with the Tab.
    expect(huSource('components/home/how-it-works.tsx'))
        ->toMatch('/id=\{HOW_IT_WORKS_TITLE_ID\}\s*tabIndex=\{-1\}/');
});

test('the "Chiama" link is built on the server from the same number as WhatsApp', function () {
    config(['contact.email' => null, 'contact.whatsapp' => '+39 000 000 0000']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('contact.phone_url', 'tel:+390000000000')
        ->where('contact.whatsapp_url', 'https://wa.me/390000000000'));
    expect((string) file_get_contents(app_path('Support/SiteContact.php')))
        ->toContain("'phone_url' => \$digits !== null ? 'tel:+'.\$digits : null,");
});

test('the buttons stay at the bottom right without ever hiding the focus: room at the bottom and the safe area', function () {
    $source = huSource('components/home/floating-actions.tsx');
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($source)
        ->toContain("const ROUND = 'size-12 rounded-full shadow-md';")
        ->toMatch('/<div\s+data-floating-actions=""\s+className="fixed right-\[max\(1rem,env\(safe-area-inset-right\)\)\] bottom-\[max\(1rem,env\(safe-area-inset-bottom\)\)\] z-40 flex flex-col items-end gap-3"\s*>/');
    expect($css)
        ->toMatch('/html:has\(\[data-floating-actions\]\) \{\s*scroll-padding-bottom: 8rem;\s*\}/')
        ->toMatch('/body:has\(\[data-floating-actions\]\) \{\s*padding-bottom: calc\(8rem \+ env\(safe-area-inset-bottom\)\);\s*\}/');
    expect((string) file_get_contents(base_path('docs/accessibility.md')))->toContain('Elementi che restano fermi');
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the new texts exist in the three languages, with the French spacing, "tu" and "vous"', function () {
    $keys = ['nav.find_provider', 'home.intro.all_providers', 'home.categories.coming_soon', 'providers.list.empty_pros', 'home.back_to_top', 'home.help.open', 'home.help.title', 'home.help.text', 'home.help.whatsapp', 'home.help.whatsapp_message', 'home.help.call', 'home.help.email'];

    foreach ($keys as $key) {
        foreach (['it', 'fr', 'en'] as $locale) {
            expect(huLang($locale))->toHaveKey($key);
        }
        expect(preg_match('/ [:?!;](?![a-z_])/', huLang('fr')[$key]))->toBe(0, "fr {$key}");
    }

    expect(huLang('it')['home.help.text'])->toBe('Hai bisogno di una mano?')
        ->and(huLang('fr')['home.help.whatsapp'])->toContain('Écrivez-nous')
        ->and(huLang('fr')['providers.list.empty'])->toContain('Revenez')
        ->and(huLang('it')['providers.list.empty'])->toContain('Torna');
});
