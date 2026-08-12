<?php

return [
    'onboarding' => [
        'version' => env('CLIENT_ONBOARDING_VERSION', 'm2-v1'),
    ],

    'telegram' => [
        'portal_url' => env('CLIENT_PORTAL_URL'),
        'greeting' => [
            'en' => 'Choose an entry point.',
            'ru' => 'Выберите раздел.',
        ],
        'menu' => [
            'en' => [
                ['key' => 'portal', 'label' => 'Open client portal', 'path' => '/'],
                ['key' => 'author', 'label' => 'Author', 'path' => '/portal/sections/author'],
                ['key' => 'method', 'label' => 'Method', 'path' => '/portal/sections/method'],
                ['key' => 'b2b', 'label' => 'B2B', 'path' => '/portal/sections/b2b'],
                ['key' => 'partner', 'label' => 'Partners', 'path' => '/portal/sections/partner'],
            ],
            'ru' => [
                ['key' => 'portal', 'label' => 'Открыть портал', 'path' => '/'],
                ['key' => 'author', 'label' => 'Об авторе', 'path' => '/portal/sections/author'],
                ['key' => 'method', 'label' => 'Метод', 'path' => '/portal/sections/method'],
                ['key' => 'b2b', 'label' => 'B2B', 'path' => '/portal/sections/b2b'],
                ['key' => 'partner', 'label' => 'Партнёры', 'path' => '/portal/sections/partner'],
            ],
        ],
        'sections' => [
            'author' => ['title' => ['en' => 'Author', 'ru' => 'Об авторе']],
            'method' => ['title' => ['en' => 'Method', 'ru' => 'Метод']],
            'b2b' => ['title' => ['en' => 'B2B', 'ru' => 'B2B']],
            'partner' => ['title' => ['en' => 'Partners', 'ru' => 'Партнёры']],
        ],
    ],
];
