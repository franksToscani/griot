{{-- HTML version of CustomerCancelledNotification. Every value goes through {{ }}, so what the customer wrote is escaped and shown as text: no Markdown, no HTML, no links. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 24px; background-color: #ffffff; color: #18181b; font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.5;">
    <p>{{ __('booking.cancelled_notice.greeting', ['name' => $providerName]) }}</p>
    <p>{{ __('booking.cancelled_notice.intro') }}</p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse: collapse;">
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.cancelled_notice.service') }}</td>
            <td style="padding: 4px 0;">{{ $service }}</td>
        </tr>
        @if ($options !== null)
            <tr>
                <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.cancelled_notice.options') }}</td>
                <td style="padding: 4px 0;">{{ $options }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.cancelled_notice.when') }}</td>
            <td style="padding: 4px 0;">{{ $date }}<br>{{ $time }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.cancelled_notice.customer') }}</td>
            <td style="padding: 4px 0;">{{ $customerName }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.cancelled_notice.phone') }}</td>
            {{-- Plain text, not a tel: link. --}}
            <td style="padding: 4px 0;">{{ $customerPhone }}</td>
        </tr>
    </table>
</body>
</html>
