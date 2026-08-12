<?php

namespace App\Modules\Channels\Application;

class GetTelegramMenu
{
    /** @return list<array{key: string, label: string, url: string, web_app: bool}> */
    public function handle(?string $language): array
    {
        $locale = str_starts_with(strtolower((string) $language), 'ru') ? 'ru' : 'en';
        $entries = config('portal.telegram.menu.'.$locale, []);
        $portalUrl = config('portal.telegram.portal_url');
        $menu = [];

        foreach ($entries as $entry) {
            $key = (string) ($entry['key'] ?? '');
            $path = (string) ($entry['path'] ?? '/');
            $url = $key === 'portal' && is_string($portalUrl) && $portalUrl !== ''
                ? $portalUrl
                : rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');

            $menu[] = [
                'key' => $key,
                'label' => (string) ($entry['label'] ?? $key),
                'url' => $url,
                'web_app' => $key === 'portal',
            ];
        }

        return $menu;
    }
}
