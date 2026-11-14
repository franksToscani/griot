{{-- Plain text version of BookingReceivedNotification. Printed without escaping on purpose: a text/plain part is never read as HTML or Markdown, and escaping would show "&lt;" instead of "<". --}}
{!! __('booking.notice.greeting', ['name' => $customerName]) !!}

{!! __('booking.received.intro', ['provider' => $providerName]) !!}

{!! __('booking.notification.service') !!}: {!! $service !!}
@if ($options !== null)
{!! __('booking.notification.options') !!}: {!! $options !!}
@endif
{!! __('booking.notice.when') !!}: {!! $date !!}, {!! $time !!}
{!! __('booking.confirmation.price') !!}: {!! $price !!}
@if ($notes !== null)
{!! __('booking.notification.notes') !!}:
{!! $notes !!}
@endif

{!! __('booking.notice.link') !!}
{!! $url !!}

{!! __('booking.received.warning') !!}
