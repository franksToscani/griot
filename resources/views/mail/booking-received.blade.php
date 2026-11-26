{{-- HTML version of BookingReceivedNotification. Every value goes through {{ }}, so what the customer and the provider wrote is escaped and shown as text: no Markdown, no HTML, no links but the private address. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 24px; background-color: #ffffff; color: #18181b; font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.5;">
    <p>{{ __('booking.notice.greeting', ['name' => $customerName]) }}</p>
    <p>{{ __('booking.received.intro', ['provider' => $providerName]) }}</p>

    {{-- The customer's private page of the booking. --}}
    @include('mail.partials.action-button', ['url' => $url, 'label' => __('booking.notice.action')])

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
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.notice.when') }}</td>
            <td style="padding: 4px 0;">{{ $date }}<br>{{ $time }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.confirmation.price') }}</td>
            <td style="padding: 4px 0;">{{ $price }}</td>
        </tr>
        @if ($notes !== null)
            <tr>
                <td style="padding: 4px 16px 4px 0; font-weight: bold; vertical-align: top;">{{ __('booking.notification.notes') }}</td>
                {{-- e() escapes first, then nl2br() only adds <br> for the line breaks. --}}
                <td style="padding: 4px 0;">{!! nl2br(e($notes)) !!}</td>
            </tr>
        @endif
    </table>

    @include('mail.partials.fallback-link', ['url' => $url])
    <p><strong>{{ __('booking.received.warning') }}</strong></p>
</body>
</html>
