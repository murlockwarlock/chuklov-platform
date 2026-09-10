<?php

namespace App\Modules\Feedback\Application;

final class ReviewDestinationIconResolver
{
    public function resolve(string $url): string
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST)));
        $host = rtrim((string) preg_replace('/^www\./', '', $host), '.');

        return match (true) {
            $this->matches($host, ['2gis.com', '2gis.kz', '2gis.ru']) => 'map',
            $this->matches($host, ['google.com', 'google.ru']) => 'pin',
            $this->matches($host, ['yandex.com', 'yandex.kz', 'yandex.ru']) => 'compass',
            default => 'globe',
        };
    }

    /** @param list<string> $domains */
    private function matches(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
