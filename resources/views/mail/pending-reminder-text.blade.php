{{-- Plain text version of PendingReminderNotification. Printed without escaping on purpose: a text/plain part is never read as HTML or Markdown, and escaping would show "&lt;" instead of "<". --}}
{!! __('booking.reminder.greeting', ['name' => $providerName]) !!}

{!! __('booking.reminder.intro', ['hours' => $hours]) !!}

{!! __('booking.reminder.service') !!}: {!! $service !!}
@if ($options !== null)
{!! __('booking.reminder.options') !!}: {!! $options !!}
@endif
{!! __('booking.reminder.when') !!}: {!! $date !!}, {!! $time !!}
{!! __('booking.reminder.customer') !!}: {!! $customerName !!}
{!! __('booking.reminder.phone') !!}: {!! $customerPhone !!}

{!! __('booking.reminder.action') !!}: {!! $listUrl !!}
