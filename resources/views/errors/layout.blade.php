{{--
    Griot's error pages. They stand on their own: no Vite, no database, no session, no Inertia, no
    JavaScript, so that a 500 or a 503 still shows them. The CSS is here, with the colours of the
    warm palette (the same tokens as resources/css/app.css: ErrorPagesTest compares them), the
    font is the system's. Never any detail of the exception.

    Inside the app, Inertia shows an error page in a dialog over the current page: every link has
    target="_top", so it opens the whole page, not the dialog.
--}}
@php($appName = config('app.name', 'Griot'))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>@yield('title') - {{ $appName }}</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <style>
            :root {
                color-scheme: light dark;
                --background: oklch(0.99 0.006 80);
                --foreground: oklch(0.25 0.07 40);
                --muted-foreground: oklch(0.47 0.045 50);
                --primary: oklch(0.32 0.08 40);
                --primary-foreground: oklch(0.985 0.012 85);
                --input: oklch(0.58 0.045 55);
                --ring: oklch(0.2 0.06 40);
                --decor: oklch(0.64 0.18 50);
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --background: oklch(0.17 0.025 45);
                    --foreground: oklch(0.96 0.015 80);
                    --muted-foreground: oklch(0.78 0.03 70);
                    --primary: oklch(0.82 0.165 79);
                    --primary-foreground: oklch(0.22 0.06 40);
                    --input: oklch(0.58 0.05 60);
                    --ring: oklch(0.82 0.165 79);
                    --decor: oklch(0.71 0.17 55);
                }
            }

            *, ::before, ::after {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                background: var(--background);
                color: var(--foreground);
                font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Noto Sans', 'Helvetica Neue', Arial, sans-serif;
                line-height: 1.5;
                -webkit-text-size-adjust: 100%;
            }

            header {
                border-bottom: 2px solid var(--decor);
            }

            .wrap {
                max-width: 36rem;
                margin: 0 auto;
                padding: 1rem;
            }

            .brand {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                color: var(--primary);
                font-size: 1.25rem;
                font-weight: 700;
                text-decoration: none;
            }

            .brand svg {
                width: 2rem;
                height: 2rem;
                flex: none;
            }

            .brand .mark-square {
                fill: var(--primary);
            }

            .brand .mark-letter {
                stroke: var(--primary-foreground);
            }

            main {
                padding-block: 2rem 3rem;
            }

            h1 {
                margin: 0 0 0.75rem;
                font-size: 1.5rem;
                line-height: 1.25;
            }

            p {
                margin: 0 0 1.5rem;
                color: var(--muted-foreground);
            }

            .actions {
                display: flex;
                flex-wrap: wrap;
                gap: 0.75rem;
            }

            .button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 2.5rem;
                padding: 0.5rem 1rem;
                border: 1px solid var(--input);
                border-radius: 0.5rem;
                color: var(--foreground);
                font-weight: 500;
                text-decoration: none;
            }

            /* Filled: its border keeps the shape where the background is not drawn (forced colours). */
            .button.primary {
                border-color: transparent;
                background: var(--primary);
                color: var(--primary-foreground);
            }

            a:focus-visible {
                outline: 3px solid var(--ring);
                outline-offset: 2px;
            }

            /* Fingers on phones and tablets: 44px targets below 768px. */
            @media (max-width: 767.98px) {
                .brand,
                .button {
                    min-height: 2.75rem;
                }
            }

            @media (forced-colors: active) {
                .button.primary {
                    border-color: ButtonText;
                }

                a:focus-visible {
                    outline-color: Highlight;
                }
            }
        </style>
    </head>
    <body>
        <header>
            <div class="wrap">
                <a class="brand" href="{{ url('/') }}" target="_top">
                    <svg viewBox="0 0 64 64" aria-hidden="true" focusable="false">
                        <rect class="mark-square" width="64" height="64" rx="14" />
                        <path class="mark-letter" d="M41.9 22.1A14 14 0 1 0 46 32H33" fill="none" stroke-width="7" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    {{ $appName }}
                </a>
            </div>
        </header>
        <main class="wrap">
            <h1>@yield('title')</h1>
            <p>@yield('message')</p>
            <div class="actions">
                @yield('actions')
            </div>
        </main>
    </body>
</html>
