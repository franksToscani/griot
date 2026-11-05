<?php

namespace App\Profile;

/**
 * One WebP made by WebpEncoder: its bytes and its real size in pixels.
 */
final readonly class WebpVariant
{
    public function __construct(
        public string $bytes,
        public int $width,
        public int $height,
    ) {}
}
