<?php

namespace App\Filament\Support;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use Throwable;

final class ClinicalAiPresentation
{
    public static function capability(AiCapability|string $capability): string
    {
        $capability = $capability instanceof AiCapability ? $capability : AiCapability::tryFrom($capability);

        return match ($capability) {
            AiCapability::ClinicalDocumentExtraction => 'Анализ документов',
            AiCapability::PostureAnalysis => 'Анализ осанки',
            AiCapability::ClinicalSynthesizer => 'Клиническое резюме',
            default => 'Клинический AI',
        };
    }

    public static function status(AiRunStatus|string $status): string
    {
        $status = $status instanceof AiRunStatus ? $status : AiRunStatus::tryFrom($status);

        return $status?->label() ?? 'Неизвестный статус';
    }

    public static function review(HumanReviewStatus|string $status): string
    {
        $status = $status instanceof HumanReviewStatus ? $status : HumanReviewStatus::tryFrom($status);

        return $status?->label() ?? 'Проверка не определена';
    }

    public static function failure(?AiErrorCategory $category, ?Throwable $exception = null): string
    {
        if ($category !== null) {
            return match ($category) {
                AiErrorCategory::InvalidPrompt => 'Нет активной версии промпта для этого анализа.',
                AiErrorCategory::ProviderUnavailable => 'Сервис AI отключён или временно недоступен.',
                AiErrorCategory::AuthenticationFailed => 'Проверьте подключение AI.',
                AiErrorCategory::OutputSchemaValidationFailed => 'Ответ AI не прошёл проверку структуры.',
                AiErrorCategory::ExecutionTimedOut => 'AI не успел завершить анализ. Повторите запуск.',
                AiErrorCategory::SafetyKillSwitchActive => 'AI отключён политикой безопасности организации.',
                default => $category->label(),
            };
        }

        $message = strtolower((string) $exception?->getMessage());
        if (str_contains($message, 'active prompt')) {
            return 'Нет активной версии промпта для этого анализа.';
        }
        if (str_contains($message, 'model') || str_contains($message, 'provider')) {
            return 'Проверьте активную модель и подключение AI.';
        }
        if (str_contains($message, 'attachment') || str_contains($message, 'вложен') || str_contains($message, 'фото')) {
            return 'Файл недоступен, повреждён или не прошёл проверку приватного хранилища.';
        }

        return 'Не удалось запустить анализ. Проверьте настройки AI или повторите попытку.';
    }
}
