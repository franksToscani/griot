<?php

namespace App\Profile;

/**
 * The profile fields a provider writes: only data. Empty optional fields are null.
 */
final readonly class ProfileDetails
{
    public function __construct(
        public string $name,
        public string $city,
        public ?string $bio = null,
        public ?string $whatsapp = null,
        public ?string $instagram = null,
    ) {}
}
