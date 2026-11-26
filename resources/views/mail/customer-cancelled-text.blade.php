{{-- Plain text version of CustomerCancelledNotification. Printed without escaping on purpose: a text/plain part is never read as HTML or Markdown, and escaping would show "&lt;" instead of "<". "Label: value" through booking.mail.label_value (and booking.mail.label at the end of a line): the French no-break space before the colon (#53). --}}
{!! __('booking.cancelled_notice.greeting', ['name' => $providerName]) !!}

{!! __('booking.cancelled_notice.intro') !!}

{!! __('booking.mail.label_value', ['label' => __('booking.cancelled_notice.service'), 'value' => $service]) !!}
@if ($options !== null)
{!! __('booking.mail.label_value', ['label' => __('booking.cancelled_notice.options'), 'value' => $options]) !!}
@endif
{!! __('booking.mail.label_value', ['label' => __('booking.cancelled_notice.when'), 'value' => $date.', '.$time]) !!}
{!! __('booking.mail.label_value', ['label' => __('booking.cancelled_notice.customer'), 'value' => $customerName]) !!}
{!! __('booking.mail.label_value', ['label' => __('booking.cancelled_notice.phone'), 'value' => $customerPhone]) !!}
