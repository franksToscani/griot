{{-- HTML version of PendingReminderNotification. Every value goes through {{ }}, so what the customer wrote is escaped and shown as text: no Markdown, no HTML, no links. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 24px; background-color: #ffffff; color: #18181b; font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.5;">
    <p>{{ __('booking.reminder.greeting', ['name' => $providerName]) }}</p>
    <p>{{ __('booking.reminder.intro', ['hours' => $hours]) }}</p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse: collapse;">
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.reminder.service') }}</td>
            <td style="padding: 4px 0;">{{ $service }}</td>
        </tr>
        @if ($options !== null)
            <tr>
                <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.reminder.options') }}</td>
                <td style="padding: 4px 0;">{{ $options }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.reminder.when') }}</td>
            <td style="padding: 4px 0;">{{ $date }}<br>{{ $time }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.reminder.customer') }}</td>
            <td style="padding: 4px 0;">{{ $customerName }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.reminder.phone') }}</td>
            {{-- Plain text, not a tel: link. --}}
            <td style="padding: 4px 0;">{{ $customerPhone }}</td>
        </tr>
    </table>

    {{-- The only link: the provider's own list of pending requests. --}}
    <p style="margin-top: 24px;"><a href="{{ $listUrl }}" style="color: #18181b;">{{ __('booking.reminder.action') }}</a></p>
</body>
</html>
