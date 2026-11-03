{{--
    The links of an error page, the first one filled. $links lists them, among: home, providers,
    reload, retry, retry_later. "Reload" and the two "retry" open the current page: inside Inertia's
    dialog an empty href is the page the user is on; after a form sent without Inertia, the page it
    came from, if it is ours (never an address of another site).
--}}
@php
    $referer = request()->headers->get('referer');
    $sameSite = is_string($referer) && parse_url($referer, PHP_URL_HOST) === request()->getHost();
    $current = request()->isMethod('GET') || ! $sameSite ? '' : $referer;
    $targets = [
        'home' => url('/'),
        'providers' => route('providers.index'),
        'reload' => $current,
        'retry' => $current,
        'retry_later' => $current,
    ];
@endphp
@foreach ($links as $link)
    <a class="button{{ $loop->first ? ' primary' : '' }}" href="{{ $targets[$link] }}" target="_top">{{ __('errors.action.'.$link) }}</a>
@endforeach
