// npm audit --omit=dev with exceptions that expire (docs/ci.md, « Eccezioni all'audit npm »).
// Run by the audit job of .github/workflows/production-checks.yml: node scripts/npm-audit.mjs
// Only Node, no package. It fails (exit 1):
// - on every advisory that is not in EXCEPTIONS, whatever its severity, as plain npm audit does;
// - on an advisory whose exception has expired (the day after the date, UTC);
// - on an exception without a valid date;
// - when npm audit does not answer.
// It groups by advisory, not by package: vite, tinyglobby and the other links of a chain are
// covered by the advisory they lead to, never by an exception of their own.
// The date is always today's date: nothing from the environment can move it.
import { execFileSync } from 'node:child_process';

// One exception per advisory, always with an expiry date, at most 2-3 weeks away, explained in
// docs/ci.md and with an issue to remember the date. None today: GHSA-fc5q-39xc-vjpf (picomatch)
// was taken away on 26/11/2026, after GitHub withdrew the advisory on 18/11/2026.
const EXCEPTIONS = {
    // 'GHSA-xxxx-xxxx-xxxx': 'YYYY-MM-DD',
};

const today = new Date().toISOString().slice(0, 10);
let failed = false;

for (const [id, until] of Object.entries(EXCEPTIONS)) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(until))) {
        console.error(
            `The exception of ${id} has no valid date (YYYY-MM-DD): ${until}`,
        );
        failed = true;
    }
}

let raw;
try {
    raw = execFileSync('npm', ['audit', '--omit=dev', '--json'], {
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024,
    });
} catch (error) {
    // npm audit exits with 1 when it finds something: the report is still on stdout.
    raw = error.stdout;
}

let report;
try {
    report = JSON.parse(raw || '{}');
} catch {
    report = {};
}
if (!report.vulnerabilities || typeof report.vulnerabilities !== 'object') {
    console.error('npm audit did not answer:', report.error ?? raw);
    process.exit(1);
}

const advisories = new Map();
for (const vuln of Object.values(report.vulnerabilities)) {
    for (const via of vuln.via ?? []) {
        // A string is a link of a chain: the advisory is on the package it names.
        if (typeof via !== 'object') {
            continue;
        }
        const id = String(via.url ?? via.source)
            .split('/')
            .pop();
        advisories.set(id, via);
    }
}

for (const [id, via] of advisories) {
    const line = `${id} ${via.name} ${via.range} (${via.severity}) ${via.title}`;
    const until = EXCEPTIONS[id];
    if (until && today <= until) {
        console.log(`Excepted until ${until}: ${line}`);
        continue;
    }
    failed = true;
    console.error(until ? `Exception expired on ${until}: ${line}` : line);
}

for (const id of Object.keys(EXCEPTIONS)) {
    if (!advisories.has(id)) {
        console.log(
            `::warning::${id} is no longer reported: remove its exception from scripts/npm-audit.mjs and docs/ci.md`,
        );
    }
}

console.log(failed ? 'npm audit: failed.' : 'npm audit: passed.');
process.exit(failed ? 1 : 0);
