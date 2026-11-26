{{-- Plain text version of NewBookingNotification. Printed without escaping on purpose: a text/plain part is never read as HTML or Markdown, and escaping would show "&lt;" instead of "<". "Label: value" through booking.mail.label_value (and booking.mail.label at the end of a line): the French no-break space before the colon (#53). --}}
{!! __('booking.notification.greeting', ['name' => $providerName]) !!}

{!! __('booking.notification.intro') !!}

{!! __('booking.mail.label_value', ['label' => __('booking.notification.service'), 'value' => $service]) !!}
@if ($options !== null)
{!! __('booking.mail.label_value', ['label' => __('booking.notification.options'), 'value' => $options]) !!}
@endif
{!! __('booking.mail.label_value', ['label' => __('booking.notification.when'), 'value' => $date.', '.$time]) !!}
{!! __('booking.mail.label_value', ['label' => __('booking.notification.customer'), 'value' => $customerName]) !!}
{!! __('booking.mail.label_value', ['label' => __('booking.notification.phone'), 'value' => $customerPhone]) !!}
@if ($customerEmail !== null)
{!! __('booking.mail.label_value', ['label' => __('booking.notification.email'), 'value' => $customerEmail]) !!}
@endif
@if ($notes !== null)
{!! __('booking.mail.label', ['label' => __('booking.notification.notes')]) !!}
{!! $notes !!}
@endif

{!! __('booking.notification.action') !!}
{!! $url !!}

{!! __('booking.notification.outro') !!}
