{{-- Plain text version of BookingStatusNotification. Printed without escaping on purpose: a text/plain part is never read as HTML or Markdown, and escaping would show "&lt;" instead of "<". --}}
{!! __('booking.notice.greeting', ['name' => $customerName]) !!}

{!! $outcomeText !!}

{!! __('booking.notification.service') !!}: {!! $service !!}
{!! __('booking.notice.when') !!}: {!! $date !!}, {!! $time !!}
@if ($reason !== null)
{!! __('booking.notice.reason') !!}:
{!! $reason !!}
@endif

{!! __('booking.notice.link') !!}
{!! $url !!}
