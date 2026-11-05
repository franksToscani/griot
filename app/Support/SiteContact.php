<?php

namespace App\Support;

/**
 * The site's own contacts for the footer of the public pages (issue #144, docs/home.md), from
 * config/contact.php. A missing, empty or invalid value is null: it is not shown, and it is no
 * error. Never a value written in the code.
 */
final class SiteContact
{
    /**
     * @return array{email: string|null, whatsapp_url: string|null}
     */
    public static function shared(): array
    {
        $email = trim((string) config('contact.email'));

        return [
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
            'whatsapp_url' => WhatsApp::url((string) config('contact.whatsapp')),
        ];
    }
}
