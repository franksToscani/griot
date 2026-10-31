{{-- Plain text version of CustomerCancelledNotification. Printed without escaping on purpose: a text/plain part is never read as HTML or Markdown, and escaping would show "&lt;" instead of "<". --}}
{!! __('booking.cancelled_notice.greeting', ['name' => $providerName]) !!}

{!! __('booking.cancelled_notice.intro') !!}

{!! __('booking.cancelled_notice.service') !!}: {!! $service !!}
@if ($options !== null)
{!! __('booking.cancelled_notice.options') !!}: {!! $options !!}
@endif
{!! __('booking.cancelled_notice.when') !!}: {!! $date !!}, {!! $time !!}
{!! __('booking.cancelled_notice.customer') !!}: {!! $customerName !!}
{!! __('booking.cancelled_notice.phone') !!}: {!! $customerPhone !!}
