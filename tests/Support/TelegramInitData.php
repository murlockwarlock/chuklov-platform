<?php

namespace Tests\Support;

use SergiX44\Nutgram\Testing\FakeNutgram;

final class TelegramInitData
{
    public static function make(
        int $userId,
        int $authDate,
        string $token = FakeNutgram::TOKEN,
        string $language = 'en',
        string $firstName = 'Test',
        string $lastName = 'Client',
        ?string $startParameter = null,
        ?string $username = null,
    ): string {
        $user = [
            'id' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'language_code' => $language,
        ];

        if ($username !== null) {
            $user['username'] = $username;
        }

        $parameters = [
            'auth_date' => (string) $authDate,
            'user' => json_encode($user, JSON_THROW_ON_ERROR),
        ];

        if ($startParameter !== null) {
            $parameters['start_param'] = $startParameter;
        }

        ksort($parameters);
        $dataCheckString = implode("\n", array_map(
            static fn (string $key, string $value): string => $key.'='.$value,
            array_keys($parameters),
            array_values($parameters),
        ));
        $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);
        $parameters['hash'] = hash_hmac('sha256', $dataCheckString, $secretKey);

        return http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
