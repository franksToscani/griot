{{-- HTML version of BookingStatusNotification. Every value goes through {{ }}, so what the customer and the provider wrote is escaped and shown as text: no Markdown, no HTML, no links but the private address. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 24px; background-color: #ffffff; color: #18181b; font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.5;">
    <p>{{ __('booking.notice.greeting', ['name' => $customerName]) }}</p>
    <p>{{ $outcomeText }}</p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse: collapse;">
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.notification.service') }}</td>
            <td style="padding: 4px 0;">{{ $service }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.notice.when') }}</td>
            <td style="padding: 4px 0;">{{ $date }}<br>{{ $time }}</td>
        </tr>
        @if ($reason !== null)
            <tr>
                <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.notice.reason') }}</td>
                {{-- e() escapes first, then nl2br() only adds <br> for the line breaks. --}}
                <td style="padding: 4px 0;">{!! nl2br(e($reason)) !!}</td>
            </tr>
        @endif
    </table>

    <p>{{ __('booking.notice.link') }}<br><a href="{{ $url }}">{{ $url }}</a></p>
</body>
</html>
