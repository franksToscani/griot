{{-- HTML version of NewBookingNotification. Every value goes through {{ }}, so what the customer wrote is escaped and shown as text: no Markdown, no HTML, no links. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 24px; background-color: #ffffff; color: #18181b; font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.5;">
    <p>{{ __('booking.notification.greeting', ['name' => $providerName]) }}</p>
    <p>{{ __('booking.notification.intro') }}</p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse: collapse;">
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.notification.service') }}</td>
            <td style="padding: 4px 0;">{{ $service }}</td>
        </tr>
        @if ($options !== null)
            <tr>
                <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.notification.options') }}</td>
                <td style="padding: 4px 0;">{{ $options }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.notification.when') }}</td>
            <td style="padding: 4px 0;">{{ $date }}<br>{{ $time }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.notification.customer') }}</td>
            <td style="padding: 4px 0;">{{ $customerName }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.notification.phone') }}</td>
            {{-- Plain text, not a tel: link: the number is not normalized. --}}
            <td style="padding: 4px 0;">{{ $customerPhone }}</td>
        </tr>
        @if ($customerEmail !== null)
            <tr>
                <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.notification.email') }}</td>
                <td style="padding: 4px 0;">{{ $customerEmail }}</td>
            </tr>
        @endif
        @if ($notes !== null)
            <tr>
                <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.notification.notes') }}</td>
                {{-- e() escapes first, then nl2br() only adds <br> for the line breaks. --}}
                <td style="padding: 4px 0;">{!! nl2br(e($notes)) !!}</td>
            </tr>
        @endif
    </table>

    <p>{{ __('booking.notification.outro') }}</p>
</body>
</html>
