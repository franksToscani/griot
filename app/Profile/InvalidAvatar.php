<?php

namespace App\Profile;

use RuntimeException;

/**
 * The uploaded file passed the validation but is not an image GD can read or write (for
 * example a truncated JPEG). It happens in normal use: the request turns it into a message for
 * the provider. The technical message has no personal data and no file name.
 */
final class InvalidAvatar extends RuntimeException
{
    public static function unreadable(): self
    {
        return new self('The uploaded avatar could not be decoded as an image.');
    }

    public static function notEncoded(): self
    {
        return new self('The avatar could not be encoded as WebP.');
    }
}
