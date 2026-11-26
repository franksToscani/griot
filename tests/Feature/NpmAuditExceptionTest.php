<?php

use Symfony\Component\Yaml\Yaml;

/*
 * The exceptions of the npm audit (docs/ci.md, « Eccezioni all'audit npm »): scripts/npm-audit.mjs
 * runs npm audit --omit=dev and lets through only the advisories in its list, each one until a
 * date. The audit job of production-checks.yml calls it on every push, pull request and Monday.
 * Read from the sources: the script runs only in the CI (and by hand).
 */

function naScript(): string
{
    $path = base_path('scripts/npm-audit.mjs');
    expect(is_file($path))->toBeTrue('scripts/npm-audit.mjs is missing');

    return (string) file_get_contents($path);
}

/**
 * The exceptions of the script, advisory => date as written.
 *
 * @return array<string, string>
 */
function naExceptions(): array
{
    expect(preg_match('/const EXCEPTIONS = \{(.*?)\n\};/s', naScript(), $block))->toBe(1, 'No EXCEPTIONS list');
    preg_match_all("/^\\s*'([^']+)':\\s*'([^']*)',?\\s*$/m", $block[1], $entries, PREG_SET_ORDER);

    // Every line that is not a comment is an entry read above: nothing written another way.
    $lines = array_filter(array_map('trim', explode("\n", $block[1])), fn (string $line) => $line !== '' && ! str_starts_with($line, '//'));
    expect($entries)->toHaveCount(count($lines), 'An exception written another way, or without a date');

    $exceptions = [];
    foreach ($entries as [, $id, $date]) {
        $exceptions[$id] = $date;
    }

    return $exceptions;
}

test('every exception is a GitHub advisory with a real date, and is explained in docs/ci.md', function () {
    $exceptions = naExceptions();
    $docs = (string) file_get_contents(base_path('docs/ci.md'));

    // None today: GHSA-fc5q-39xc-vjpf was taken away after GitHub withdrew it (18/11/2026). The
    // checks below hold for the exceptions of tomorrow.
    expect($exceptions)->toBe([])
        ->and($docs)->toContain("#### Eccezioni all'audit npm")
        ->and($docs)->toContain('un\'eccezione per avviso, sempre con una scadenza, al massimo 2-3 settimane');

    foreach ($exceptions as $id => $date) {
        expect($id)->toMatch('/^GHSA(-[23456789cfghjmpqrvwx]{4}){3}$/');
        expect(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts))->toBe(1, "{$id}: the date is not YYYY-MM-DD");
        expect(checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))->toBeTrue("{$id}: {$date} is not a date");
        expect($docs)->toContain("[{$id}](https://github.com/advisories/{$id})");
    }
});

test('the mechanism stays ready: the list, the rule of the date, no exception in force, the history', function () {
    $script = naScript();
    $docs = (string) file_get_contents(base_path('docs/ci.md'));

    expect($script)
        ->toMatch('/\/\/ One exception per advisory, always with an expiry date, at most 2-3 weeks away,[\s\S]*?\nconst EXCEPTIONS = \{\n/')
        ->toContain('if (until && today <= until) {')
        ->toContain('console.error(until ? `Exception expired on ${until}: ${line}` : line);')
        ->not->toContain('GHSA-fc5q-39xc-vjpf\': ');
    expect($docs)
        ->toContain('**Eccezioni in vigore:** nessuna.')
        ->toContain('- GHSA-fc5q-39xc-vjpf (`picomatch` `<=4.0.7`, ReDoS): eccezione tolta il 26/11/2026, avviso ritirato da GitHub il 18/11/2026.')
        // What to do when an advisory with an exception is no longer reported stays written.
        ->toContain('Quando un avviso con eccezione non compare più, il log mostra un avvertimento: si tolgono l\'eccezione dallo script e la sua voce da qui.');
    expect((string) file_get_contents(base_path('docs/roadmap.md')))
        ->toContain('L\'eccezione per GHSA-fc5q-39xc-vjpf (`picomatch`) è tolta: avviso ritirato il 18/11/2026.')
        ->not->toContain('eccezione a scadenza (2 dicembre 2026)');
});

test('the script runs npm audit on the production packages, fails on the rest and cannot be moved from outside', function () {
    $script = naScript();

    expect($script)
        ->toContain("execFileSync('npm', ['audit', '--omit=dev', '--json']")
        ->toContain('const today = new Date().toISOString().slice(0, 10);')
        // The advisory is the unit, never the package of a chain.
        ->toContain("if (typeof via !== 'object') {")
        ->toContain('if (until && today <= until) {')
        ->toContain("console.error('npm audit did not answer:'")
        ->toContain('::warning::')
        ->toContain('process.exit(failed ? 1 : 0);')
        // Nothing from the environment or the command line: a PR cannot move the date.
        ->not->toContain('process.env')
        ->not->toContain('process.argv')
        // Only Node: no package.
        ->not->toMatch("/from '(?!node:)/")
        ->not->toContain('require(');
});

test('the audit job calls the script, every Monday too; the other jobs do not run on the weekly run', function () {
    $text = (string) file_get_contents(base_path('.github/workflows/production-checks.yml'));
    $workflow = Yaml::parseFile(base_path('.github/workflows/production-checks.yml'));

    expect($workflow['on'])->toHaveKeys(['push', 'pull_request', 'schedule'])
        ->and($workflow['on']['push'])->toBe(['branches' => ['main']])
        ->and($workflow['on']['schedule'])->toBe([['cron' => '17 6 * * 1']]);

    $step = collect($workflow['jobs']['audit']['steps'])->firstWhere('name', 'npm audit (production packages)');
    expect($step)->toBe(['name' => 'npm audit (production packages)', 'run' => 'node scripts/npm-audit.mjs'])
        ->and($workflow['jobs']['audit'])->not->toHaveKey('if')
        // npm audit only through the script, never plain.
        ->and(preg_match('/^\s*(run:\s*)?npm audit\b/m', $text))->toBe(0);

    foreach (['production-build', 'tests-postgres', 'docker-build'] as $job) {
        expect($workflow['jobs'][$job]['if'] ?? null)->toBe("github.event_name != 'schedule'", "{$job} runs on the weekly run");
    }
});
