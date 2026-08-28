<?php

namespace App\Modules\Channels\Application;

use App\Modules\Channels\Domain\Enums\TelegramMenuLaunchMode;
use LogicException;

final class GetTelegramMenu
{
    public function __construct(private readonly ResolveTelegramMiniAppEntry $entries) {}

    /** @return list<array{key: string, label: string, url: string, web_app: bool, launch: string}> */
    public function handle(?string $language): array
    {
        $locale = str_starts_with(strtolower((string) $language), 'ru') ? 'ru' : 'en';
        $entries = config('portal.telegram.menu.'.$locale, []);
        $menu = [];

        if (! is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $key = (string) ($entry['key'] ?? '');
            $launch = $this->entries->launchMode($key);

            if ($key === '' || ! $launch instanceof TelegramMenuLaunchMode) {
                continue;
            }

            try {
                $url = $launch === TelegramMenuLaunchMode::MiniApp
                    ? $this->entries->launchUrl($key)
                    : $this->entries->externalUrl($key);
            } catch (LogicException) {
                continue;
            }

            if (! is_string($url) || $url === '') {
                continue;
            }

            $menu[] = [
                'key' => $key,
                'label' => (string) ($entry['label'] ?? $key),
                'url' => $url,
                'web_app' => $launch === TelegramMenuLaunchMode::MiniApp,
                'launch' => $launch->value,
            ];
        }

        return $menu;
    }
}
