{{--
    The action of an email (#53, docs/bookings.md « Email »): a full button in the site's green, right
    after the first sentence. The one exception to the neutral emails (BrandPaletteTest): #fefcf7 on
    #005c48, 7.8:1. The background is on the cell (bgcolor and style), so Outlook for Windows, which
    ignores the padding of a link and the rounded corners, still shows a green rectangle; the link is
    a block inside it, so the whole cell is clickable: 12 + 20 + 12 = 44px high. No image.
    Parameters: $url, $label (a translated text). The same address is written in full under the
    details (mail/partials/fallback-link).
--}}
<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse: separate; margin: 24px 0;">
    <tr>
        <td bgcolor="#005c48" style="background-color: #005c48; border-radius: 6px;">
            <a href="{{ $url }}" style="display: block; padding: 12px 20px; line-height: 20px; font-size: 16px; color: #fefcf7; font-weight: bold; text-decoration: none;">{{ $label }}</a>
        </td>
    </tr>
</table>
