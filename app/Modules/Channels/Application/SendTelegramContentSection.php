<?php

namespace App\Modules\Channels\Application;

use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Content\Application\ListPublishedContentSections;
use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
use App\Modules\Identity\Application\VerifiedChannelIdentity;
use Illuminate\Support\Facades\Cache;

final class SendTelegramContentSection
{
    private const SECTION_DEDUPLICATION_TTL = 300;

    public function __construct(
        private readonly ListPublishedContentSections $sections,
        private readonly NotificationChannelRegistry $channels,
        private readonly BuildTelegramContentSectionMessage $messages,
    ) {}

    public function handle(
        VerifiedChannelIdentity $identity,
        string $sectionKey,
        string $locale,
        ?string $requestId = null,
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

        $normalizedRequestId = $requestId === null ? null : trim($requestId);
        $deliver = function () use ($channel, $identity, $localized, $locale, $normalizedRequestId): NotificationDeliveryResult {
            $lastResult = NotificationDeliveryResult::unavailable('content_unavailable');
            $deliveredSectionCount = 0;
            $skippedSectionCount = 0;

            foreach ($localized as $section) {
                $sendSection = fn (): NotificationDeliveryResult => $channel->send($this->messages->handle(
                    $identity->externalId,
                    $section,
                    $locale,
                    includeMediaStream: true,
                ));

                if ($normalizedRequestId !== null && $normalizedRequestId !== '') {
                    $sectionFingerprint = hash('sha256', implode('|', [
                        'telegram-content-section',
                        $identity->externalId,
                        $section->getKey(),
                        $section->updated_at?->getTimestamp() ?? 0,
                        $locale,
                    ]));
                    $sectionCompletedKey = 'telegram-content-section-callback:completed:'.$sectionFingerprint;

                    if (Cache::has($sectionCompletedKey)) {
                        $skippedSectionCount++;

                        continue;
                    }

                    $sectionLock = Cache::lock('telegram-content-section-callback:lock:'.$sectionFingerprint, 60);
                    if (! $sectionLock->get()) {
                        return NotificationDeliveryResult::retryable('content_in_progress');
                    }

                    try {
                        if (Cache::has($sectionCompletedKey)) {
                            $skippedSectionCount++;

                            continue;
                        }

                        $lastResult = $sendSection();
                        if ($lastResult->outcome->value !== 'delivered') {
                            return $lastResult;
                        }

                        $deliveredSectionCount++;
                        Cache::put($sectionCompletedKey, true, self::SECTION_DEDUPLICATION_TTL);
                    } finally {
                        $sectionLock->release();
                    }

                    continue;
                }

                $lastResult = $sendSection();

                if ($lastResult->outcome->value !== 'delivered') {
                    return $lastResult;
                }

                $deliveredSectionCount++;
            }

            if ($normalizedRequestId !== null
                && $deliveredSectionCount === 0
                && $skippedSectionCount > 0) {
                return NotificationDeliveryResult::suppressed('duplicate_callback');
            }

            return $lastResult;
        };

        if ($requestId === null || trim($requestId) === '') {
            return $deliver();
        }

        return $this->deduplicateCallback(
            requestId: trim($requestId),
            externalId: $identity->externalId,
            sectionKey: $sectionKey,
            locale: $locale,
            deliver: $deliver,
        );
    }

    private function deduplicateCallback(
        string $requestId,
        string $externalId,
        string $sectionKey,
        string $locale,
        callable $deliver,
    ): NotificationDeliveryResult {
        $fingerprint = hash('sha256', implode('|', [$requestId, $externalId, $sectionKey, $locale]));
        $completedKey = 'telegram-content-callback:completed:'.$fingerprint;

        if (Cache::has($completedKey)) {
            return NotificationDeliveryResult::suppressed('duplicate_callback');
        }

        $lock = Cache::lock('telegram-content-callback:lock:'.$fingerprint, 60);
        if (! $lock->get()) {
            return NotificationDeliveryResult::retryable('callback_in_progress');
        }

        try {
            if (Cache::has($completedKey)) {
                return NotificationDeliveryResult::suppressed('duplicate_callback');
            }

            $result = $deliver();
            if ($result->outcome->value === 'delivered') {
                Cache::put($completedKey, true, 86400);
            }

            return $result;
        } finally {
            $lock->release();
        }
    }
}
