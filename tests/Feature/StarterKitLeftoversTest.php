<?php

// Issue #115: what was left of the Laravel React starter kit. Read from the files: the components
// and packages that nothing used, the SSR that never ran, the "header" variant of the layout, the
// example test and the package name.

function sklFile(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

/**
 * Every .ts and .tsx file of resources/js, generated ones excluded.
 *
 * @return list<string>
 */
function sklSources(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path = $file->getPathname();

        if (preg_match('/\.tsx?$/', $path) === 1 && preg_match('#/resources/js/(routes|actions|wayfinder)/#', $path) !== 1) {
            $files[] = $path;
        }
    }

    return $files;
}

test('the four unused kit components and their packages are gone, and nothing imports them', function () {
    $components = ['collapsible', 'navigation-menu', 'toggle-group', 'toggle'];
    $packages = ['@radix-ui/react-collapsible', '@radix-ui/react-navigation-menu', '@radix-ui/react-toggle-group', '@radix-ui/react-toggle'];

    foreach ($components as $component) {
        expect(resource_path("js/components/ui/{$component}.tsx"))->not->toBeFile();
    }

    $package = json_decode(sklFile('package.json'), true);
    $lock = json_decode(sklFile('package-lock.json'), true);
    foreach ($packages as $name) {
        expect($package['dependencies'] ?? [])->not->toHaveKey($name)
            ->and($package['devDependencies'] ?? [])->not->toHaveKey($name)
            ->and($lock['packages']['']['dependencies'] ?? [])->not->toHaveKey($name)
            ->and($lock['packages'])->not->toHaveKey("node_modules/{$name}");
    }

    foreach (sklSources() as $path) {
        expect((string) file_get_contents($path))
            ->not->toMatch('#@/components/ui/(collapsible|navigation-menu|toggle-group|toggle)[\'"]#')
            ->not->toMatch('#@radix-ui/react-(collapsible|navigation-menu|toggle-group|toggle)[\'"]#');
    }
});

test('no SSR: off in the config, no script, no folder in the build or the ignored files', function () {
    expect(config('inertia.ssr.enabled'))->toBeFalse();

    $package = json_decode(sklFile('package.json'), true);
    $composer = json_decode(sklFile('composer.json'), true);
    expect($package['scripts'])->not->toHaveKey('build:ssr')->not->toHaveKey('dev:ssr')
        ->and($composer['scripts'])->not->toHaveKey('dev:ssr');

    foreach (['vite.config.ts', '.gitignore', 'Dockerfile', 'render.yaml'] as $file) {
        expect(sklFile($file))->not->toContain('bootstrap/ssr')->not->toContain('--ssr');
    }
});

test('the private area has the sidebar only: no "header" variant', function () {
    expect(sklFile('resources/js/types/ui.ts'))->not->toContain('AppVariant')
        ->and(sklFile('resources/js/components/app-shell.tsx'))->not->toContain('variant')
        ->toContain('<SidebarProvider defaultOpen={isOpen}>{children}</SidebarProvider>')
        ->and(sklFile('resources/js/components/app-content.tsx'))->not->toContain('variant')
        ->toContain('<SidebarInset {...props}>{children}</SidebarInset>')
        ->and(sklFile('resources/js/layouts/app/app-sidebar-layout.tsx'))->not->toContain('variant=');
});

test('a real unit test in place of the example, and the package has its own name', function () {
    expect(base_path('tests/Unit/ExampleTest.php'))->not->toBeFile()
        ->and(base_path('tests/Unit/PhoneNumberTest.php'))->toBeFile()
        ->and(sklFile('tests/Unit/PhoneNumberTest.php'))->not->toContain('expect(true)->toBeTrue()');

    expect(json_decode(sklFile('composer.json'), true)['name'])->toBe('griot/griot');
});

test('the README tells the real stack and the checks', function () {
    expect(sklFile('README.md'))
        ->toContain('Laravel 13')
        ->toContain('PostgreSQL in produzione')
        ->toContain('composer ci:check')
        ->toContain('[docs/roadmap.md](docs/roadmap.md)')
        ->not->toContain('Sprint 0');
});
