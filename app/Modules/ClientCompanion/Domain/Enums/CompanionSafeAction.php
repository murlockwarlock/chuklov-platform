<?php

namespace App\Modules\ClientCompanion\Domain\Enums;

enum CompanionSafeAction: string
{
    case RequestHuman = 'request_human';
    case ReinspectRecentImage = 'reinspect_recent_image';
    case OpenPortal = 'open_portal';
    case FeedbackHelpful = 'feedback_helpful';
    case FeedbackNotHelpful = 'feedback_not_helpful';

    public function label(string $locale): string
    {
        return match ($this) {
            self::RequestHuman => str_starts_with($locale, 'ru') ? 'Нужен специалист' : 'Ask a specialist',
            self::ReinspectRecentImage => str_starts_with($locale, 'ru') ? 'Уточнить по фото' : 'Ask about the photo',
            self::OpenPortal => str_starts_with($locale, 'ru') ? 'Открыть кабинет' : 'Open Portal',
            self::FeedbackHelpful => str_starts_with($locale, 'ru') ? 'Полезно' : 'Helpful',
            self::FeedbackNotHelpful => str_starts_with($locale, 'ru') ? 'Не помогло' : 'Not helpful',
        };
    }
}
