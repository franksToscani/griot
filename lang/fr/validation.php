<?php

/*
|--------------------------------------------------------------------------
| Validation messages
|--------------------------------------------------------------------------
|
| The French version of lang/it/validation.php: the same rules, the same
| keys and placeholders (TranslationKeysTest checks both).
|
*/

return [

    'array' => 'Le champ :attribute doit être une liste.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'current_password' => "Le mot de passe n'est pas correct.",
    'date' => 'Le champ :attribute doit être une date valide.',
    'date_format' => 'Le champ :attribute doit respecter le format :format.',
    'dimensions' => "Les dimensions de l'image :attribute ne sont pas valides.",
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'enum' => "La valeur choisie pour :attribute n'est pas valide.",
    'exists' => "La valeur choisie pour :attribute n'est pas valide.",
    'file' => 'Le champ :attribute doit être un fichier.',
    'in' => "La valeur choisie pour :attribute n'est pas valide.",
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'max' => [
        'array' => 'Le champ :attribute peut contenir au maximum :max éléments.',
        'file' => 'Le fichier :attribute ne doit pas dépasser :max kilo-octets.',
        'numeric' => 'Le champ :attribute ne peut pas être supérieur à :max.',
        'string' => 'Le champ :attribute peut contenir au maximum :max caractères.',
    ],
    'mimes' => 'Le fichier :attribute doit être de type :values.',
    'min' => [
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
        'file' => 'Le fichier :attribute doit faire au moins :min kilo-octets.',
        'numeric' => 'Le champ :attribute doit être au moins égal à :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'password' => [
        'letters' => 'Le champ :attribute doit contenir au moins une lettre.',
        'mixed' => 'Le champ :attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le champ :attribute doit contenir au moins un chiffre.',
        'symbols' => 'Le champ :attribute doit contenir au moins un symbole.',
        'uncompromised' => 'Ce :attribute est apparu dans une fuite de données. Choisissez-en un autre.',
    ],
    'regex' => "Le format du champ :attribute n'est pas valide.",
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être un texte.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',
    'uploaded' => "Le fichier :attribute n'a pas pu être envoyé.",

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
    */

    'attributes' => [
        'name' => 'nom',
        'email' => 'e-mail',
        'password' => 'mot de passe',
        'password_confirmation' => 'confirmation du mot de passe',
        'current_password' => 'mot de passe actuel',
        'service' => 'prestation',
        'options' => 'options',
        'options.*' => 'option',
        'starts_at' => 'créneau',
        'date' => 'jour',
        'category' => 'catégorie',
        'category_id' => 'catégorie',
        'city' => 'ville',
        'status' => 'statut',
        'from' => 'date de début',
        'to' => 'date de fin',
        'avatar' => 'photo',
        'locale' => 'langue',
    ],

];
