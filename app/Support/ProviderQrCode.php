<?php

namespace App\Support;

use App\Models\Provider;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The QR code of a provider's public page ("Il tuo link", docs/provider-link.md), made on our server
 * with bacon/bacon-qr-code: no other service sees anything.
 *
 * - It holds the public address only, on APP_URL (PublicUrl), never on the host of the request,
 *   and without parameters: the same address as public_url in the dashboard.
 * - Error correction M (15%): enough without a logo on it, and fewer modules than Q or H, so each
 *   one is bigger once printed.
 * - A quiet zone of 4 modules, the one of the standard, black on white: the brown of the site would
 *   lower the contrast for a camera.
 * - SVG, which needs no extension, and PNG of 1024 px with GD (already used for the photos), never
 *   Imagick.
 */
final class ProviderQrCode
{
    /**
     * The quiet zone, in modules.
     */
    public const MARGIN = 4;

    /**
     * The side of the PNG, in pixels.
     */
    public const PNG_SIZE = 1024;

    /**
     * The side of the SVG, in its own units: it scales without loss.
     */
    public const SVG_SIZE = 400;

    public const ENCODING = 'UTF-8';

    /**
     * What the QR code holds: the whole address of the public page, nothing more.
     */
    public static function content(Provider $provider): string
    {
        return PublicUrl::to(route('providers.show', $provider->slug, absolute: false));
    }

    public static function errorCorrection(): ErrorCorrectionLevel
    {
        return ErrorCorrectionLevel::M();
    }

    public static function svg(Provider $provider): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::SVG_SIZE, self::MARGIN), new SvgImageBackEnd));

        return $writer->writeString(self::content($provider), self::ENCODING, self::errorCorrection());
    }

    public static function png(Provider $provider): string
    {
        $writer = new Writer(new GDLibRenderer(self::PNG_SIZE, self::MARGIN));

        return $writer->writeString(self::content($provider), self::ENCODING, self::errorCorrection());
    }
}
