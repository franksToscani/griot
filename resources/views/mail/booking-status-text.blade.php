{{-- Plain text version of BookingStatusNotification. Printed without escaping on purpose: a text/plain part is never read as HTML or Markdown, and escaping would show "&lt;" instead of "<". "Label: value" through booking.mail.label_value (and booking.mail.label at the end of a line): the French no-break space before the colon (#53). --}}
{!! __('booking.notice.greeting', ['name' => $customerName]) !!}

{!! $outcomeText !!}

{!! __('booking.mail.label_value', ['label' => __('booking.notification.service'), 'value' => $service]) !!}
{!! __('booking.mail.label_value', ['label' => __('booking.notice.when'), 'value' => $date.', '.$time]) !!}
@if ($reason !== null)
{!! __('booking.mail.label', ['label' => __('booking.notice.reason')]) !!}
{!! $reason !!}
@endif

{!! __('booking.notice.action') !!}
{!! $url !!}
