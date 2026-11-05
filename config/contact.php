<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The Site's Contacts
    |--------------------------------------------------------------------------
    |
    | How customers reach the people who run Griot, shown in the footer of
    | the public pages (App\Support\SiteContact). Read from the environment
    | only: no address or number is written in the code. A missing or invalid
    | value is not shown. Not the privacy contact (config/privacy.php): that
    | one is for the requests about personal data.
    |
    | whatsapp: an international number, with + or 00 (for example +39…).
    |
    */

    'email' => env('CONTACT_EMAIL'),

    'whatsapp' => env('CONTACT_WHATSAPP'),

];
