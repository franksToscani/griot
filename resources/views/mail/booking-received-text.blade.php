{{-- Plain text version of BookingReceivedNotification. Printed without escaping on purpose: a text/plain part is never read as HTML or Markdown, and escaping would show "&lt;" instead of "<". "Label: value" through booking.mail.label_value (and booking.mail.label at the end of a line): the French no-break space before the colon (#53). --}}
{!! __('booking.notice.greeting', ['name' => $customerName]) !!}

{!! __('booking.received.intro', ['provider' => $providerName]) !!}

{!! __('booking.mail.label_value', ['label' => __('booking.notification.service'), 'value' => $service]) !!}
@if ($options !== null)
{!! __('booking.mail.label_value', ['label' => __('booking.notification.options'), 'value' => $options]) !!}
@endif
{!! __('booking.mail.label_value', ['label' => __('booking.notice.when'), 'value' => $date.', '.$time]) !!}
{!! __('booking.mail.label_value', ['label' => __('booking.confirmation.price'), 'value' => $price]) !!}
@if ($notes !== null)
{!! __('booking.mail.label', ['label' => __('booking.notification.notes')]) !!}
{!! $notes !!}
@endif

{!! __('booking.notice.action') !!}
{!! $url !!}

{!! __('booking.received.warning') !!}
