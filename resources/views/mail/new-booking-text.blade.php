{{-- Plain text version of NewBookingNotification. Printed without escaping on purpose: a text/plain part is never read as HTML or Markdown, and escaping would show "&lt;" instead of "<". --}}
{!! __('booking.notification.greeting', ['name' => $providerName]) !!}

{!! __('booking.notification.intro') !!}

{!! __('booking.notification.service') !!}: {!! $service !!}
@if ($options !== null)
{!! __('booking.notification.options') !!}: {!! $options !!}
@endif
{!! __('booking.notification.when') !!}: {!! $date !!}, {!! $time !!}
{!! __('booking.notification.customer') !!}: {!! $customerName !!}
{!! __('booking.notification.phone') !!}: {!! $customerPhone !!}
@if ($customerEmail !== null)
{!! __('booking.notification.email') !!}: {!! $customerEmail !!}
@endif
@if ($notes !== null)
{!! __('booking.notification.notes') !!}:
{!! $notes !!}
@endif

{!! __('booking.notification.outro') !!}
