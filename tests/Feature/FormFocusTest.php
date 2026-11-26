<?php

use Illuminate\Support\Facades\Process;

// The focus after a refused submit in the forms of the provider's area (#244, docs/accessibility.md,
// « Dopo un invio rifiutato »): the first wrong field in the order of the page, found by its name
// (lib/form-focus.ts, run with Node); the submit button, disabled while sending, gets the focus back
// at the end only if nobody took it (hooks/use-form-focus.ts). The forms are read in their source.

function ffSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

/**
 * @return array<string, mixed>
 */
function ffNode(string $body): array
{
    $script = sys_get_temp_dir().'/griot-ff-'.bin2hex(random_bytes(6)).'.mts';
    $helper = resource_path('js/lib/form-focus.ts');
    file_put_contents($script, "import * as f from '{$helper}';\n{$body}\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

// The forms that use the hook, by file. A form with fields gets onError too; every form gets the
// focus back at the end (onFinish) on its submit button (ref).
const FF_FORMS = [
    'js/pages/provider/services/index.tsx',
    'js/pages/provider/services/edit.tsx',
    'js/pages/provider/services/options.tsx',
    'js/pages/provider/availability/index.tsx',
    'js/pages/provider/edit.tsx',
    'js/pages/provider/setup.tsx',
    'js/pages/settings/profile.tsx',
    'js/pages/dashboard.tsx',
];

// What makes a form a form "with fields".
const FF_FIELDS = '/<Input\b|<select\b|<Textarea\b|<ProviderProfileFields\b|<OptionFields\b|<AvailabilityFields\b|<ServiceDurationFields\b/';

// ---------------------------------------------------------------------------------------------
// The choice of the field, with Node
// ---------------------------------------------------------------------------------------------

test('the first wrong field in the order of the page, whatever the order of the errors', function () {
    $out = ffNode("
const field = (name, extra = {}) => ({ name, disabled: false, type: 'text', visible: true, ...extra });
const fields = [field('name'), field('city'), field('bio'), field('whatsapp')];
console.log(JSON.stringify({
    order: f.firstErrorField(fields, { whatsapp: 'x', city: 'y' }),
    one: f.firstErrorField(fields, { bio: 'x' }),
    none: f.firstErrorField(fields, { services: 'Troppe richieste' }),
    empty: f.firstErrorField([], { name: 'x' }),
    disabled: f.firstErrorField([field('name', { disabled: true }), field('city')], { name: 'x', city: 'y' }),
    hidden: f.firstErrorField([field('token', { type: 'hidden' }), field('city')], { token: 'x', city: 'y' }),
    invisible: f.firstErrorField([field('name', { visible: false }), field('city')], { name: 'x', city: 'y' }),
    inDetails: f.firstErrorField([field('name', { visible: false, inClosedDetails: true })], { name: 'x' }),
    noName: f.firstErrorField([field(''), field('city')], { '': 'x', city: 'y' }),
    brackets: f.firstErrorField([field('options[0][name]'), field('options[1][name]')], { 'options.1.name': 'x' }),
    key: f.errorKey('options[0][name]'),
    plain: f.errorKey('price'),
}));
");

    expect($out)->toBe([
        // whatsapp is first among the errors, city first on the page: city.
        'order' => 1,
        'one' => 2,
        // An error of no field: no field, the button keeps the focus.
        'none' => null,
        'empty' => null,
        'disabled' => 1,
        'hidden' => 1,
        'invisible' => 1,
        // Not visible because a <details> is closed: the hook opens it.
        'inDetails' => 0,
        'noName' => 1,
        'brackets' => 1,
        'key' => 'options.0.name',
        'plain' => 'price',
    ]);
});

// ---------------------------------------------------------------------------------------------
// The hook
// ---------------------------------------------------------------------------------------------

test('the hook: on the next frame the field takes the focus and comes to the middle at once; the button only when nobody took the focus', function () {
    $hook = ffSource('js/hooks/use-form-focus.ts');

    expect($hook)
        ->toContain("import { firstErrorField } from '@/lib/form-focus';")
        ->toMatch('/const onError = useCallback\(\(errors: Record<string, string>\) => \{\s*window\.requestAnimationFrame\(\(\) => \{\s*const form = ref\.current\?\.form;/')
        ->toContain('visible: field.getClientRects().length > 0,')
        ->toContain("field.closest('details:not([open])') !== null,")
        ->toMatch('/if \(details !== null && !details\.open\) \{\s*details\.open = true;\s*\}/')
        ->toContain('field.focus({ preventScroll: true });')
        // At once: never a smooth scroll.
        ->toContain("field.scrollIntoView({ block: 'center' });")
        ->not->toMatch("/behavior:\\s*'smooth'/")
        // The button only if the focus is lost: never over the field of an error.
        ->toMatch('/const onFinish = useCallback\(\(\) => \{\s*window\.requestAnimationFrame\(\(\) => \{\s*const active = document\.activeElement;\s*if \(active === null \|\| active === document\.body\) \{\s*ref\.current\?\.focus\(\);/')
        ->toContain('return { ref, onError, onFinish };')
        // No ARIA, no announcement (section 9).
        ->not->toMatch('/\baria-[a-z]+|\brole=/');
    // The old name stays, from the same hook.
    expect(ffSource('js/components/add-panel.tsx'))
        ->toMatch("/export \\{\\s*useFormFocus,\\s*useFormFocus as useSubmitFocus,?\\s*\\} from '@\\/hooks\\/use-form-focus';/")
        ->not->toContain('function useSubmitFocus');
});

// ---------------------------------------------------------------------------------------------
// The forms
// ---------------------------------------------------------------------------------------------

test('every form of the list: the field of an error when it has fields, the button back at the end, the button still disabled while sending', function () {
    $checked = 0;

    foreach (FF_FORMS as $path) {
        $source = ffSource($path);
        expect($source)->toContain("import { useFormFocus } from '@/hooks/use-form-focus';");
        preg_match_all('/<Form\b[\s\S]*?<\/Form>/', $source, $forms);
        expect(count($forms[0]))->toBeGreaterThan(0, $path);

        foreach ($forms[0] as $form) {
            $checked++;
            expect(str_contains($form, 'submit.onFinish'))->toBeTrue($path)
                ->and($form)->toContain('ref={submit.ref}')
                ->toMatch('/disabled=\{[^}]*processing[^}]*\}/');
            if (preg_match(FF_FIELDS, $form) === 1 || str_contains($form, '<ProviderProfileFields')) {
                expect($form)->toContain('onError={submit.onError}');
            }
        }
        // One hook per form of the file (the avatar's useForm has its own, below).
        expect(substr_count($source, 'useFormFocus()'))->toBe(count($forms[0]) + ($path === 'js/pages/provider/edit.tsx' ? 1 : 0), $path);
    }

    // 1 + 4 + 5 + 3 + 1 + 1 + 1 + 2 forms.
    expect($checked)->toBe(18);
});

test('the photo of the profile (useForm): the field of the error, the button back at the end', function () {
    $page = ffSource('js/pages/provider/edit.tsx');

    expect($page)
        ->toContain('const focus = useFormFocus();')
        ->toMatch('/form\.post\(storeAvatar\.url\(\), \{[\s\S]*?onError: focus\.onError,\s*onFinish: \(\) => \{\s*setPreparing\(false\);\s*focus\.onFinish\(\);\s*\},/')
        ->toMatch('/<Button\s+ref=\{focus\.ref\}\s+type="submit"/');
});

test('the forms with their own focus are left as they are', function () {
    foreach (['js/components/booking-sheet.tsx', 'js/pages/provider/bookings/create.tsx', 'js/pages/settings/security.tsx', 'js/components/delete-user.tsx'] as $path) {
        expect(str_contains(ffSource($path), 'useFormFocus'))->toBeFalse($path);
    }
    expect(ffSource('js/components/booking-sheet.tsx'))->toContain("focusFirstInvalid('booking-form')");
    expect(ffSource('js/pages/provider/bookings/create.tsx'))->toContain("focusFirstInvalid('provider-booking-form')");
});

test('the rule is written where the forms of tomorrow look for it', function () {
    $doc = (string) file_get_contents(base_path('docs/accessibility.md'));

    expect($doc)
        ->toContain('- **Dopo un invio rifiutato** (#244): nei moduli dell\'area del professionista il focus va al **primo campo sbagliato nell\'ordine della pagina**')
        ->toContain('dopo un invio rifiutato il focus va al primo campo sbagliato, con `useFormFocus`')
        ->toContain('Il focus sul primo campo sbagliato è fatto (#244, « Dopo un invio rifiutato »)');
});
