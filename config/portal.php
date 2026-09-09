<?php

return [
    'locales' => ['ru', 'en'],

    'default_locale' => env('CLIENT_PORTAL_LOCALE', 'ru'),

    'email_auth' => [
        'code_ttl' => (int) env('CLIENT_EMAIL_AUTH_CODE_TTL', 600),
        'max_attempts' => (int) env('CLIENT_EMAIL_AUTH_MAX_ATTEMPTS', 5),
        'request_limit' => (int) env('CLIENT_EMAIL_AUTH_REQUEST_LIMIT', 5),
        'request_decay' => (int) env('CLIENT_EMAIL_AUTH_REQUEST_DECAY', 900),
    ],

    'onboarding' => [
        'version' => env('CLIENT_ONBOARDING_VERSION', 'm2-v1'),
    ],

    'content_sections' => [
        'author' => ['title' => ['en' => 'Author', 'ru' => 'Об авторе']],
        'method' => ['title' => ['en' => 'Method', 'ru' => 'Метод']],
        'partner' => ['title' => ['en' => 'Partners', 'ru' => 'Партнёры']],
        'communities' => [
            'title' => ['en' => 'Communities', 'ru' => 'Сообщества'],
            'requires_published_content' => true,
        ],
    ],

    'telegram' => [
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'link_ttl' => (int) env('TELEGRAM_CLIENT_LINK_TTL', 600),
        'web_auth_ttl' => (int) env('TELEGRAM_WEB_AUTH_TTL', 600),
        'portal_url' => env('CLIENT_PORTAL_URL'),
        'entries' => [
            'portal' => [
                'launch' => 'mini_app',
                'requires_auth' => true,
                'route' => 'portal.home',
                'parameters' => [],
            ],
            'author' => [
                'launch' => 'mini_app',
                'requires_auth' => false,
                'route' => 'portal.section',
                'parameters' => ['section' => 'author'],
            ],
            'method' => [
                'launch' => 'mini_app',
                'requires_auth' => false,
                'route' => 'portal.section',
                'parameters' => ['section' => 'method'],
            ],
            'b2b' => [
                'launch' => 'mini_app',
                'requires_auth' => true,
                'route' => 'portal.b2b',
                'parameters' => [],
            ],
            'feedback' => [
                'launch' => 'mini_app',
                'requires_auth' => true,
                'route' => 'portal.feedback',
                'parameters' => [],
            ],
            'partner' => [
                'launch' => 'mini_app',
                'requires_auth' => false,
                'route' => 'portal.section',
                'parameters' => ['section' => 'partner'],
            ],
            'partner_cabinet' => [
                'launch' => 'mini_app',
                'requires_auth' => true,
                'route' => 'portal.referrals',
                'parameters' => [],
            ],
            'invite_friend' => [
                'launch' => 'mini_app',
                'requires_auth' => true,
                'route' => 'portal.referrals',
                'parameters' => [],
            ],
            'communities' => [
                'launch' => 'mini_app',
                'requires_auth' => false,
                'route' => 'portal.section',
                'parameters' => ['section' => 'communities'],
            ],
            'tracker' => [
                'launch' => 'mini_app',
                'requires_auth' => true,
                'route' => 'portal.tracker',
                'parameters' => [],
            ],
        ],
        'greeting' => [
            'en' => 'Choose an entry point.',
            'ru' => 'Выберите раздел.',
        ],
        'menu' => [
            'en' => [
                ['key' => 'portal', 'label' => 'Open client portal'],
                ['key' => 'author', 'label' => 'Author'],
                ['key' => 'method', 'label' => 'Method'],
                ['key' => 'b2b', 'label' => '🚀 Want a bot like this? / Grow your business'],
                ['key' => 'partner', 'label' => 'Partners'],
                ['key' => 'invite_friend', 'label' => '🎁 Invite a friend'],
                ['key' => 'partner_cabinet', 'label' => '🤝 Partner cabinet'],
                ['key' => 'communities', 'label' => 'Communities'],
            ],
            'ru' => [
                ['key' => 'portal', 'label' => 'Открыть портал'],
                ['key' => 'author', 'label' => 'Об авторе'],
                ['key' => 'method', 'label' => 'Метод'],
                ['key' => 'b2b', 'label' => '🚀 Хочешь себе такого бота? / Развить бизнес'],
                ['key' => 'partner', 'label' => 'Партнёры'],
                ['key' => 'invite_friend', 'label' => '🎁 Пригласить друга'],
                ['key' => 'partner_cabinet', 'label' => '🤝 Стать партнёром'],
                ['key' => 'communities', 'label' => 'Сообщества'],
            ],
        ],
    ],
];
