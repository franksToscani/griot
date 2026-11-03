{{--
    Griot's error pages. They stand on their own: no Vite, no database, no session, no Inertia, no
    JavaScript, so that a 500 or a 503 still shows them. The CSS is here, with the colours of the
    Laguna palette (the same tokens as resources/css/app.css: ErrorPagesTest compares them), the
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
                --background: oklch(1 0 0);
                --foreground: oklch(0.2 0.025 240);
                --muted-foreground: oklch(0.48 0.03 240);
                --primary: oklch(0.43 0.09 170);
                --primary-foreground: oklch(0.99 0.005 200);
                --input: oklch(0.6 0.03 235);
                --ring: oklch(0.24 0.06 170);
                --decor: oklch(0.56 0.17 30);
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --background: oklch(0.18 0.02 245);
                    --foreground: oklch(0.97 0.01 225);
                    --muted-foreground: oklch(0.76 0.03 230);
                    --primary: oklch(0.8 0.11 170);
                    --primary-foreground: oklch(0.2 0.04 200);
                    --input: oklch(0.56 0.04 240);
                    --ring: oklch(0.85 0.09 170);
                    --decor: oklch(0.68 0.16 30);
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
