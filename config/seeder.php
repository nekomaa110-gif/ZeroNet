<?php

return [
    'allow_production' => filter_var(env('ADMIN_SEED_ALLOW_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN),

    'admin' => [
        'name'     => env('ADMIN_SEED_NAME'),
        'username' => env('ADMIN_SEED_USERNAME'),
        'email'    => env('ADMIN_SEED_EMAIL'),
        'password' => env('ADMIN_SEED_PASSWORD'),
    ],

    'operator' => [
        'name'     => env('OPERATOR_SEED_NAME'),
        'username' => env('OPERATOR_SEED_USERNAME'),
        'email'    => env('OPERATOR_SEED_EMAIL'),
        'password' => env('OPERATOR_SEED_PASSWORD'),
    ],
];
