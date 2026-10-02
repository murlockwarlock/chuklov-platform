<?php

namespace App\Modules\ClientCompanion\Domain\Enums;

enum CompanionFailureCode: string
{
    case NotConfigured = 'not_configured';
    case ProviderDisabled = 'provider_disabled';
    case ProviderMisconfigured = 'provider_misconfigured';
    case BudgetUnavailable = 'budget_unavailable';
    case ProviderUnavailable = 'provider_unavailable';
    case InvalidOutput = 'invalid_output';
    case RetrievalFailure = 'retrieval_failure';
    case QueueFailure = 'queue_failure';
    case DeliveryFailure = 'delivery_failure';
    case RateLimited = 'rate_limited';
    case ImageUnavailable = 'image_unavailable';
    case DocumentUnavailable = 'document_unavailable';
    case InputLimitExceeded = 'input_limit_exceeded';
    case MediaGroupIncomplete = 'media_group_incomplete';
    case ExecutionDeadlineExceeded = 'execution_deadline_exceeded';

    public function isRetryable(): bool
    {
        return in_array($this, [
            self::ProviderUnavailable,
            self::InvalidOutput,
            self::RetrievalFailure,
            self::QueueFailure,
            self::RateLimited,
            self::ExecutionDeadlineExceeded,
        ], true);
    }

    public function shouldNotifyOperations(): bool
    {
        return $this->isRetryable() || in_array($this, [
            self::NotConfigured,
            self::ProviderDisabled,
            self::ProviderMisconfigured,
            self::BudgetUnavailable,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'Помощник не настроен',
            self::ProviderDisabled => 'AI-провайдер отключён',
            self::ProviderMisconfigured => 'Ошибка настроек AI-провайдера',
            self::BudgetUnavailable => 'Недоступен лимит AI',
            self::ProviderUnavailable => 'AI-провайдер временно недоступен',
            self::InvalidOutput => 'AI вернул ответ в неподдерживаемом формате',
            self::RetrievalFailure => 'Не удалось получить контекст из базы знаний',
            self::QueueFailure => 'Очередь обработки AI недоступна',
            self::RateLimited => 'AI-провайдер ограничил частоту запросов',
            self::DeliveryFailure => 'Не удалось доставить сообщение',
            self::ImageUnavailable => 'Недоступно вложенное изображение',
            self::DocumentUnavailable => 'Недоступен вложенный документ',
            self::InputLimitExceeded => 'Превышен допустимый размер сообщения',
            self::MediaGroupIncomplete => 'Не удалось получить все вложения сообщения',
            self::ExecutionDeadlineExceeded => 'Истекло время обработки AI',
        };
    }
}
