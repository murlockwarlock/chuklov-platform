<?php

namespace App\Filament\Support;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use Illuminate\Support\Str;
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

    public static function documentStatus(?AiRunStatus $status): string
    {
        return match ($status) {
            null => 'Не запускался',
            AiRunStatus::Preparing, AiRunStatus::Queued => 'В очереди',
            AiRunStatus::Running => 'Анализируется',
            AiRunStatus::Succeeded => 'Готово',
            default => 'Ошибка',
        };
    }

    public static function documentStatusColor(?AiRunStatus $status): string
    {
        return match ($status) {
            null => 'gray',
            AiRunStatus::Preparing, AiRunStatus::Queued => 'warning',
            AiRunStatus::Running => 'info',
            AiRunStatus::Succeeded => 'success',
            default => 'danger',
        };
    }

    public static function sourceStatus(?AiRun $run): string
    {
        if ($run === null) {
            return 'Не запускался';
        }

        if (! $run->status->isTerminal()) {
            return 'В работе';
        }

        if ($run->status !== AiRunStatus::Succeeded) {
            return 'Ошибка';
        }

        return match ($run->human_review_status) {
            HumanReviewStatus::PendingReview => 'Требует проверки',
            HumanReviewStatus::Accepted, HumanReviewStatus::EditedAndAccepted => 'Проверено',
            HumanReviewStatus::Rejected => 'Отклонено',
            default => 'Готово',
        };
    }

    public static function sourceStatusColor(?AiRun $run): string
    {
        if ($run === null) {
            return 'gray';
        }

        if (! $run->status->isTerminal()) {
            return 'info';
        }

        if ($run->status !== AiRunStatus::Succeeded) {
            return 'danger';
        }

        return match ($run->human_review_status) {
            HumanReviewStatus::PendingReview => 'warning',
            HumanReviewStatus::Rejected => 'danger',
            default => 'success',
        };
    }

    public static function synthesisStatus(?AiRun $run): string
    {
        if ($run === null) {
            return 'Нет';
        }

        if (! $run->status->isTerminal()) {
            return 'Создаётся';
        }

        if ($run->status !== AiRunStatus::Succeeded) {
            return 'Ошибка';
        }

        return match ($run->human_review_status) {
            HumanReviewStatus::Accepted, HumanReviewStatus::EditedAndAccepted => 'Проверено',
            HumanReviewStatus::Rejected => 'Отклонено',
            default => 'Требует проверки',
        };
    }

    public static function synthesisStatusColor(?AiRun $run): string
    {
        if ($run === null) {
            return 'gray';
        }

        if (! $run->status->isTerminal()) {
            return 'info';
        }

        if ($run->status !== AiRunStatus::Succeeded) {
            return 'danger';
        }

        return match ($run->human_review_status) {
            HumanReviewStatus::Accepted, HumanReviewStatus::EditedAndAccepted => 'success',
            HumanReviewStatus::Rejected => 'danger',
            default => 'warning',
        };
    }

    public static function review(HumanReviewStatus|string $status): string
    {
        $status = $status instanceof HumanReviewStatus ? $status : HumanReviewStatus::tryFrom($status);

        return $status?->label() ?? 'Проверка не определена';
    }

    public static function reviewColor(HumanReviewStatus|string $status): string
    {
        $value = $status instanceof HumanReviewStatus ? $status->value : $status;
        $reviewStatus = HumanReviewStatus::tryFrom($value);

        if ($reviewStatus === null) {
            foreach (HumanReviewStatus::cases() as $candidate) {
                if ($candidate->label() === $value) {
                    $reviewStatus = $candidate;

                    break;
                }
            }
        }

        return match ($reviewStatus) {
            HumanReviewStatus::Accepted, HumanReviewStatus::EditedAndAccepted => 'success',
            HumanReviewStatus::PendingReview => 'warning',
            HumanReviewStatus::Rejected => 'danger',
            default => 'gray',
        };
    }

    public static function reviewGuidance(HumanReviewStatus|string $status): string
    {
        $status = $status instanceof HumanReviewStatus ? $status : HumanReviewStatus::tryFrom($status);

        return match ($status) {
            HumanReviewStatus::PendingReview => implode("\n", [
                'Результат сохранён в истории Клинического AI.',
                'Он ещё не подтверждён специалистом.',
                'Клиенту ничего не отправлено.',
                'В медицинский профиль данные автоматически не внесены.',
                'Проверьте результат, чтобы использовать его в дальнейшем клиническом анализе.',
            ]),
            HumanReviewStatus::Accepted, HumanReviewStatus::EditedAndAccepted => implode("\n", [
                'Результат проверен специалистом и сохранён в истории.',
                'Он может использоваться при формировании клинического резюме.',
                'В медицинский профиль данные автоматически не внесены.',
            ]),
            HumanReviewStatus::Rejected => implode("\n", [
                'Результат отклонён специалистом и сохранён в истории.',
                'Он не используется как подтверждённый источник для клинического резюме.',
            ]),
            default => implode("\n", [
                'Результат сохранён в истории Клинического AI.',
                'Дополнительная проверка специалиста не требуется.',
                'Клиенту ничего не отправлено.',
                'В медицинский профиль данные автоматически не внесены.',
            ]),
        };
    }

    public static function result(AiCapability|string $capability, ?array $payload, ?string $text): string
    {
        $safeText = self::safeText($text);
        if ($safeText !== null) {
            return $safeText;
        }

        $capability = $capability instanceof AiCapability ? $capability : AiCapability::tryFrom($capability);
        if ($capability === null || $payload === null) {
            return 'Результат получен, но не может быть отображён в текущем формате.';
        }

        return match ($capability) {
            AiCapability::ClinicalDocumentExtraction => self::documentResult($payload),
            AiCapability::PostureAnalysis => self::postureResult($payload),
            AiCapability::ClinicalSynthesizer => self::synthesizerResult($payload),
            default => null,
        } ?? 'Результат получен, но не может быть отображён в текущем формате.';
    }

    public static function preview(AiCapability|string $capability, ?array $payload, ?string $text): string
    {
        $result = trim(self::result($capability, $payload, $text));
        if ($result === '') {
            return 'Результат получен, но не может быть отображён в текущем формате.';
        }

        $lines = preg_split('/\R+/u', $result, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $preview = implode("\n", array_slice(array_map(static fn (string $line): string => trim($line), $lines), 0, 5));

        return Str::limit($preview !== '' ? $preview : $result, 700);
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

    private static function safeText(?string $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE || str_starts_with($text, '{') || str_starts_with($text, '[')) {
            return null;
        }

        return $text;
    }

    private static function documentResult(array $payload): ?string
    {
        return self::joinSections([
            self::section('Тип исследования', $payload['exam_type'] ?? null),
            self::section('Анатомическая область', $payload['anatomical_region'] ?? null),
            self::findingsSection('Что обнаружено', $payload['key_findings'] ?? null),
            self::listSection('Структурные особенности', $payload['structural_deformations'] ?? null),
            self::listSection('Критические флаги', $payload['critical_flags'] ?? null),
            self::section('Резюме', $payload['plain_summary'] ?? null),
        ]);
    }

    private static function postureResult(array $payload): ?string
    {
        return self::joinSections([
            self::visualFindingsSection($payload['visual_findings'] ?? null),
            self::listSection('Ведущие компенсаторные паттерны', $payload['leading_compensatory_patterns'] ?? null),
            self::listSection('Фокус специалиста', $payload['practitioner_focus'] ?? null),
            self::listSection('Ограничения анализа', $payload['limitations'] ?? null),
        ]);
    }

    private static function synthesizerResult(array $payload): ?string
    {
        return self::joinSections([
            self::section('Сводка по клиенту', $payload['client_summary'] ?? null),
            self::section('Основной запрос', $payload['main_request'] ?? null),
            self::listSection('Факты', $payload['source_facts'] ?? null),
            self::hypothesesSection($payload['hypotheses'] ?? null),
            self::listSection('Ограничения и риски', $payload['critical_limitations_risks'] ?? null),
            self::listSection('Что уточнить', $payload['blind_spots_questions'] ?? null),
            self::listSection('Фокус первой сессии', $payload['recommended_first_session_focus'] ?? null),
            self::listSection('Недостающая информация', $payload['missing_information'] ?? null),
        ]);
    }

    private static function section(string $label, mixed $value): ?string
    {
        $text = self::scalarText($value);

        return $text === null ? null : $label.":\n".$text;
    }

    private static function listSection(string $label, mixed $value): ?string
    {
        $items = self::scalarList($value);
        if ($items === []) {
            return null;
        }

        return $label.":\n- ".implode("\n- ", $items);
    }

    private static function findingsSection(string $label, mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $items = [];
        foreach ($value as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $parts = [];
            foreach ([
                'location' => 'Область',
                'pathology' => 'Изменение',
                'impact' => 'Влияние',
            ] as $key => $fieldLabel) {
                $field = self::scalarText($finding[$key] ?? null);
                if ($field !== null) {
                    $parts[] = $fieldLabel.': '.$field;
                }
            }

            $size = self::scalarText($finding['size_mm'] ?? null);
            if ($size !== null) {
                $parts[] = 'Размер: '.$size.' мм';
            }

            if ($parts !== []) {
                $items[] = implode(' · ', $parts);
            }
        }

        return $items === [] ? null : $label.":\n- ".implode("\n- ", $items);
    }

    private static function visualFindingsSection(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $planes = [
            'front' => 'Спереди',
            'side' => 'Сбоку',
            'back' => 'Сзади',
        ];
        $items = [];
        foreach ($value as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $observations = self::scalarList($finding['observations'] ?? null);
            if ($observations === []) {
                continue;
            }

            $plane = $planes[(string) ($finding['plane'] ?? '')] ?? 'Наблюдения';
            $items[] = $plane.': '.implode('; ', $observations);
        }

        return $items === [] ? null : 'Визуальные наблюдения:\n- '.implode("\n- ", $items);
    }

    private static function hypothesesSection(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $items = [];
        foreach ($value as $hypothesis) {
            if (! is_array($hypothesis)) {
                continue;
            }

            $parts = [];
            $statement = self::scalarText($hypothesis['statement'] ?? null);
            if ($statement !== null) {
                $parts[] = 'Формулировка: '.$statement;
            }
            $facts = self::scalarList($hypothesis['supporting_facts'] ?? null);
            if ($facts !== []) {
                $parts[] = 'Основание: '.implode('; ', $facts);
            }
            $uncertainty = self::scalarText($hypothesis['uncertainty'] ?? null);
            if ($uncertainty !== null) {
                $parts[] = 'Неопределённость: '.$uncertainty;
            }

            if ($parts !== []) {
                $items[] = implode(' · ', $parts);
            }
        }

        return $items === [] ? null : 'Гипотезы:\n- '.implode("\n- ", $items);
    }

    private static function scalarList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = self::scalarText($item);
            if ($text !== null) {
                $items[] = $text;
            }
        }

        return $items;
    }

    private static function scalarText(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }

        return null;
    }

    private static function joinSections(array $sections): ?string
    {
        $sections = array_values(array_filter(
            $sections,
            static fn (mixed $section): bool => is_string($section) && $section !== '',
        ));

        return $sections === [] ? null : implode("\n\n", $sections);
    }
}
