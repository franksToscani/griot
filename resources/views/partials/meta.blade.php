{{--
    The preview of a shared link (App\Support\LinkPreview), in the HTML of the first response: the
    crawlers of WhatsApp, Instagram and Facebook do not run JavaScript. A private page gets no
    preview and is not indexed. Blade escapes every value (a bio with quotes or < > stays text).
--}}
@inject('linkPreview', 'App\Support\LinkPreview')
@if ($linkPreview->isPublic())
    @foreach ($linkPreview->tags() as $name => $content)
        @if ($name === 'canonical')
            <link rel="canonical" href="{{ $content }}">
        @elseif (str_starts_with($name, 'og:'))
            <meta property="{{ $name }}" content="{{ $content }}">
        @else
            <meta name="{{ $name }}" content="{{ $content }}">
        @endif
    @endforeach
@else
    <meta name="robots" content="noindex, nofollow">
@endif
