<?php

/*
|--------------------------------------------------------------------------
| Validation messages
|--------------------------------------------------------------------------
|
| Written from Laravel's English file, only for the rules this application
| uses (the Form Requests, Fortify's actions and the password rules of
| AppServiceProvider). Most fields of Griot have their own messages in
| messages(): these are the ones left, for the kit's forms (login,
| registration, settings) and for the errors only a wrong client causes.
|
| With "it" as fallback, a rule missing here shows its key: a rule added
| to a Form Request needs its message here too (TranslationsTest checks
| every rule used today).
|
*/

return [

    'array' => 'Il campo :attribute deve essere un elenco.',
    'confirmed' => 'La conferma di :attribute non corrisponde.',
    'current_password' => 'La password non è corretta.',
    'date' => 'Il campo :attribute deve essere una data valida.',
    'date_format' => 'Il campo :attribute deve avere il formato :format.',
    'dimensions' => 'Le dimensioni dell\'immagine in :attribute non sono valide.',
    'email' => 'Il campo :attribute deve essere un indirizzo email valido.',
    'enum' => 'Il valore scelto per :attribute non è valido.',
    'exists' => 'Il valore scelto per :attribute non è valido.',
    'file' => 'Il campo :attribute deve essere un file.',
    'in' => 'Il valore scelto per :attribute non è valido.',
    'integer' => 'Il campo :attribute deve essere un numero intero.',
    'max' => [
        'array' => 'Il campo :attribute può avere al massimo :max elementi.',
        'file' => 'Il file :attribute può pesare al massimo :max kilobyte.',
        'numeric' => 'Il campo :attribute non può essere maggiore di :max.',
        'string' => 'Il campo :attribute può avere al massimo :max caratteri.',
    ],
    'mimes' => 'Il file :attribute deve essere di tipo: :values.',
    'min' => [
        'array' => 'Il campo :attribute deve avere almeno :min elementi.',
        'file' => 'Il file :attribute deve pesare almeno :min kilobyte.',
        'numeric' => 'Il campo :attribute deve essere almeno :min.',
        'string' => 'Il campo :attribute deve avere almeno :min caratteri.',
    ],
    'password' => [
        'letters' => 'Il campo :attribute deve contenere almeno una lettera.',
        'mixed' => 'Il campo :attribute deve contenere almeno una lettera maiuscola e una minuscola.',
        'numbers' => 'Il campo :attribute deve contenere almeno un numero.',
        'symbols' => 'Il campo :attribute deve contenere almeno un simbolo.',
        'uncompromised' => 'Questa :attribute è comparsa in una fuga di dati. Scegline un\'altra.',
    ],
    'regex' => 'Il formato del campo :attribute non è valido.',
    'required' => 'Il campo :attribute è obbligatorio.',
    'string' => 'Il campo :attribute deve essere un testo.',
    'unique' => 'Questo valore di :attribute è già in uso.',
    'uploaded' => 'Il caricamento di :attribute non è riuscito.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Messages
    |--------------------------------------------------------------------------
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | Attribute names
    |--------------------------------------------------------------------------
    |
    | The names the messages above use for :attribute. Without one, Laravel
    | writes the field's name with spaces ("starts at").
    |
    */

    'attributes' => [
        'name' => 'nome',
        'email' => 'email',
        'password' => 'password',
        'password_confirmation' => 'conferma della password',
        'current_password' => 'password attuale',
        'service' => 'servizio',
        'options' => 'opzioni',
        'options.*' => 'opzione',
        'starts_at' => 'orario',
        'date' => 'giorno',
        'category' => 'categoria',
        'category_id' => 'categoria',
        'city' => 'città',
        'status' => 'stato',
        'from' => 'data di inizio',
        'to' => 'data di fine',
        'avatar' => 'foto',
        'locale' => 'lingua',
    ],

];
