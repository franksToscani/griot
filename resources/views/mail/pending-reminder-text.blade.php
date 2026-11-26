{{-- Plain text version of PendingReminderNotification. Printed without escaping on purpose: a text/plain part is never read as HTML or Markdown, and escaping would show "&lt;" instead of "<". "Label: value" through booking.mail.label_value (and booking.mail.label at the end of a line): the French no-break space before the colon (#53). --}}
{!! __('booking.reminder.greeting', ['name' => $providerName]) !!}

{!! __('booking.reminder.intro', ['hours' => $hours]) !!}

{!! __('booking.mail.label_value', ['label' => __('booking.reminder.service'), 'value' => $service]) !!}
@if ($options !== null)
{!! __('booking.mail.label_value', ['label' => __('booking.reminder.options'), 'value' => $options]) !!}
@endif
{!! __('booking.mail.label_value', ['label' => __('booking.reminder.when'), 'value' => $date.', '.$time]) !!}
{!! __('booking.mail.label_value', ['label' => __('booking.reminder.customer'), 'value' => $customerName]) !!}
{!! __('booking.mail.label_value', ['label' => __('booking.reminder.phone'), 'value' => $customerPhone]) !!}

{!! __('booking.reminder.action') !!}
{!! $listUrl !!}
