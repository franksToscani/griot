<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data Controller and Contact
    |--------------------------------------------------------------------------
    |
    | Who handles the personal data, and where to write about them. Read from
    | the environment only: no name or address is written in the code. When a
    | value is missing, the privacy page shows a visible placeholder instead.
    |
    */

    'controller_name' => env('PRIVACY_CONTROLLER_NAME'),

    'contact_email' => env('PRIVACY_CONTACT_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Reviewed
    |--------------------------------------------------------------------------
    |
    | False until a competent person has checked the text: the privacy page
    | then shows a "draft under review" notice at the top.
    |
    */

    'reviewed' => (bool) env('PRIVACY_REVIEWED', false),

    /*
    |--------------------------------------------------------------------------
    | Last Update
    |--------------------------------------------------------------------------
    |
    | The day the text of the privacy page last changed (Y-m-d). Change it
    | with the texts privacy.* in lang/.
    |
    */

    'updated_on' => '2026-11-09',

];
