<?php

namespace App\Modules\Channels\Application;

use App\Modules\Channels\Domain\Enums\TelegramMenuLaunchMode;
use App\Modules\Content\Application\ListPublishedContentSections;
use App\Modules\Content\Domain\Models\ContentSection;
use LogicException;

final class GetTelegramMenu
{
    public function __construct(
        private readonly ResolveTelegramMiniAppEntry $entries,
        private readonly ListPublishedContentSections $sections,
    ) {}

    /** @return list<array{key: string, label: string, url: string, web_app: bool, launch: string, callback_data?: string}> */
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

            if ($this->isTelegramContent($key, $locale) && strlen('content:'.$key) <= 64) {
                $menu[] = [
                    'key' => $key,
                    'label' => (string) ($entry['label'] ?? $key),
                    'url' => '',
                    'web_app' => false,
                    'launch' => 'telegram_content',
                    'callback_data' => 'content:'.$key,
                ];

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

    private function isTelegramContent(string $key, string $locale): bool
    {
        $registeredSections = config('portal.content_sections', []);
        if (! is_array($registeredSections) || ! is_array($registeredSections[$key] ?? null)) {
            return false;
        }

        try {
            $content = $this->sections->handle($key);
        } catch (LogicException) {
            return false;
        }

        $localized = $content->where('locale', $locale);
        if ($localized->isEmpty()) {
            $localized = $content->where('locale', $locale === 'ru' ? 'en' : 'ru');
        }

        return $localized->isNotEmpty()
            && $localized->every(static fn (ContentSection $section): bool => $section->delivery_mode->supportsTelegram());
    }
}
