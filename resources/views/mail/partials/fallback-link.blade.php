{{--
    Under the details of an email (#53): the address of the button written in full, for a program of
    mail that does not show the button, or for whoever wants to see where it goes. Neutral, underlined.
    Parameter: $url, the same as the button's.
--}}
<p style="margin-top: 24px;">{{ __('booking.mail.fallback') }}<br><a href="{{ $url }}" style="color: #18181b; text-decoration: underline; word-break: break-all;">{{ $url }}</a></p>
