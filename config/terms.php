<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Publisher
    |--------------------------------------------------------------------------
    |
    | Who publishes the site, shown on the terms of use page. Read from the
    | environment only: no name, address or number is written in the code.
    | When a value is missing, the page shows a visible placeholder instead.
    | The contact is the site's own (config/contact.php).
    |
    */

    'publisher_name' => env('TERMS_PUBLISHER_NAME'),

    'publisher_address' => env('TERMS_PUBLISHER_ADDRESS'),

    'publisher_vat_number' => env('TERMS_PUBLISHER_VAT_NUMBER'),

    /*
    |--------------------------------------------------------------------------
    | Reviewed
    |--------------------------------------------------------------------------
    |
    | False until a competent person has checked the text: the terms page
    | then shows a "draft under review" notice at the top.
    |
    */

    'reviewed' => (bool) env('TERMS_REVIEWED', false),

    /*
    |--------------------------------------------------------------------------
    | Last Update
    |--------------------------------------------------------------------------
    |
    | The day the text of the terms page last changed (Y-m-d). Change it with
    | the texts terms.* in lang/.
    |
    */

    'updated_on' => '2026-11-09',

];
