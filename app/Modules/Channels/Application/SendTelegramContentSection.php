<?php

namespace App\Modules\Channels\Application;

use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Content\Application\ListPublishedContentSections;
use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
use App\Modules\Identity\Application\VerifiedChannelIdentity;

final class SendTelegramContentSection
{
    public function __construct(
        private readonly ListPublishedContentSections $sections,
        private readonly NotificationChannelRegistry $channels,
        private readonly BuildTelegramContentSectionMessage $messages,
    ) {}

    public function handle(
        VerifiedChannelIdentity $identity,
        string $sectionKey,
        string $locale,
    ): NotificationDeliveryResult {
        if ($identity->channel !== 'telegram' || trim($identity->externalId) === '') {
            return NotificationDeliveryResult::unavailable('telegram_identity_unavailable');
        }

        $registeredSections = config('portal.content_sections', []);
        if (! is_array($registeredSections) || ! is_array($registeredSections[$sectionKey] ?? null)) {
            return NotificationDeliveryResult::unavailable('content_unavailable');
        }

        $content = $this->sections->handle($sectionKey, ContentDeliveryMode::Telegram);
        $localized = $content->where('locale', $locale);
        if ($localized->isEmpty()) {
            $localized = $content->where('locale', $locale === 'ru' ? 'en' : 'ru');
        }
        if ($localized->isEmpty()) {
            return NotificationDeliveryResult::unavailable('content_unavailable');
        }

        $channel = $this->channels->get('telegram');
        if ($channel === null || ! $channel->capabilities()->supportsProactiveDelivery) {
            return NotificationDeliveryResult::unavailable('telegram_channel_unavailable');
        }

        $lastResult = NotificationDeliveryResult::unavailable('content_unavailable');

        foreach ($localized as $section) {
            $lastResult = $channel->send($this->messages->handle($identity->externalId, $section, $locale));

            if ($lastResult->outcome->value !== 'delivered') {
                return $lastResult;
            }
        }

        return $lastResult;
    }
}
