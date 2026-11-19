{{--
    Installing Griot on a home screen (docs/production.md): the manifest, and the colour of the
    browser's bar in each theme, the brown of the icon (--hero, App\Support\BrandColours). On every
    page, the error pages included. No service worker.
--}}
@php($brand = \App\Support\BrandColours::class)
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="{{ $brand::hex($brand::BROWN) }}">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="{{ $brand::hex($brand::BROWN_DARK) }}">
