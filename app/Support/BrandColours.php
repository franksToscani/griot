<?php

namespace App\Support;

/**
 * The colours of the provisional logo (docs/brand.md), one place for every file that needs them:
 * scripts/brand-assets.php draws the images with them (it requires this file, without Composer),
 * the web app manifest and the theme-color tags of the pages read them too. They are the tokens of
 * resources/css/app.css, light theme unless said otherwise: BrandAssetsTest compares them.
 *
 * Plain PHP, no other class: the script loads this file on its own.
 */
final class BrandColours
{
    /**
     * --hero: the brown of the letters and of the square icon; the manifest's theme_color.
     */
    public const BROWN = [0x3C, 0x13, 0x04];

    /**
     * --hero of the dark theme: the theme-color of the pages in the dark theme.
     */
    public const BROWN_DARK = [0x42, 0x18, 0x08];

    /**
     * --hero-foreground: the cream of the dark wordmark.
     */
    public const CREAM = [0xFE, 0xFA, 0xF1];

    /**
     * --background: the page, the link preview, the manifest's background_color.
     */
    public const BACKGROUND = [0xFB, 0xFA, 0xF7];

    /**
     * --decor: the orange line of the link preview.
     */
    public const DECOR = [0xE7, 0x60, 0x00];

    /**
     * "#3C1304".
     *
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public static function hex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', ...$rgb);
    }
}
