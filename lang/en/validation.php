<?php

/*
|--------------------------------------------------------------------------
| Validation messages
|--------------------------------------------------------------------------
|
| The English version of lang/it/validation.php: the same rules, the same
| keys and placeholders (TranslationKeysTest checks both), in our tone.
|
*/

return [

    'array' => 'The :attribute field must be a list.',
    'confirmed' => 'The :attribute confirmation does not match.',
    'current_password' => 'The password is not correct.',
    'date' => 'The :attribute field must be a valid date.',
    'date_format' => 'The :attribute field must use the format :format.',
    'dimensions' => 'The image in :attribute does not have valid dimensions.',
    'email' => 'The :attribute field must be a valid email address.',
    'enum' => 'The chosen :attribute is not valid.',
    'exists' => 'The chosen :attribute is not valid.',
    'file' => 'The :attribute field must be a file.',
    'in' => 'The chosen :attribute is not valid.',
    'integer' => 'The :attribute field must be a whole number.',
    'max' => [
        'array' => 'The :attribute field can have up to :max items.',
        'file' => 'The :attribute file can be up to :max kilobytes.',
        'numeric' => 'The :attribute field cannot be greater than :max.',
        'string' => 'The :attribute field can be up to :max characters long.',
    ],
    'mimes' => 'The :attribute file must be of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min items.',
        'file' => 'The :attribute file must be at least :min kilobytes.',
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min characters long.',
    ],
    'password' => [
        'letters' => 'The :attribute field must contain at least one letter.',
        'mixed' => 'The :attribute field must contain at least one uppercase and one lowercase letter.',
        'numbers' => 'The :attribute field must contain at least one number.',
        'symbols' => 'The :attribute field must contain at least one symbol.',
        'uncompromised' => 'This :attribute has appeared in a data leak. Please choose a different one.',
    ],
    'regex' => 'The format of the :attribute field is not valid.',
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute field must be text.',
    'unique' => 'This :attribute is already in use.',
    'uploaded' => 'The :attribute could not be uploaded.',

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
        'name' => 'name',
        'email' => 'email',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'current_password' => 'current password',
        'service' => 'service',
        'options' => 'options',
        'options.*' => 'option',
        'starts_at' => 'time slot',
        'date' => 'day',
        'category' => 'category',
        'category_id' => 'category',
        'city' => 'city',
        'status' => 'status',
        'from' => 'start date',
        'to' => 'end date',
        'avatar' => 'photo',
        'locale' => 'language',
    ],

];
