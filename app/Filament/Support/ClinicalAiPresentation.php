<?php

namespace App\Filament\Support;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use Illuminate\Support\Str;
use Throwable;

final class ClinicalAiPresentation
{
    public static function capability(AiCapability|string $capability, ?string $workflowKey = null): string
    {
        $capability = $capability instanceof AiCapability ? $capability : AiCapability::tryFrom($capability);

        if ($capability === AiCapability::ClinicalSynthesizer
            && $workflowKey === ClinicalSynthesizerWorkflow::CourseReport->value) {
            return __('Итоговый отчёт курса');
        }

        return match ($capability) {
            AiCapability::ClinicalDocumentExtraction => __('Анализ документов'),
            AiCapability::PostureAnalysis => __('Анализ осанки'),
            AiCapability::ClinicalSynthesizer => __('Клиническое резюме'),
            default => __('Клинический AI'),
        };
    }

    public static function status(AiRunStatus|string $status): string
    {
        $status = $status instanceof AiRunStatus ? $status : AiRunStatus::tryFrom($status);

        return CrmLabel::enum($status) ?? __('Неизвестный статус');
    }

    public static function documentStatus(?AiRunStatus $status): string
    {
        return match ($status) {
            null => __('Не запускался'),
            AiRunStatus::Preparing, AiRunStatus::Queued => __('В очереди'),
            AiRunStatus::Running => __('Анализируется'),
            AiRunStatus::Succeeded => __('Готово'),
            default => __('Ошибка'),
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
            return __('Не запускался');
        }

        if (! $run->status->isTerminal()) {
            return __('В работе');
        }

        if ($run->status !== AiRunStatus::Succeeded) {
            return __('Ошибка');
        }

        return match ($run->human_review_status) {
            HumanReviewStatus::PendingReview => __('Требует проверки'),
            HumanReviewStatus::Accepted, HumanReviewStatus::EditedAndAccepted => __('Проверено'),
            HumanReviewStatus::Rejected => __('Отклонено'),
            default => __('Готово'),
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
            return __('Нет');
        }

        if (! $run->status->isTerminal()) {
            return __('Создаётся');
        }

        if ($run->status !== AiRunStatus::Succeeded) {
            return __('Ошибка');
        }

        return match ($run->human_review_status) {
            HumanReviewStatus::Accepted, HumanReviewStatus::EditedAndAccepted => __('Проверено'),
            HumanReviewStatus::Rejected => __('Отклонено'),
            default => __('Требует проверки'),
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

    public static function courseStatus(?AiRun $run): string
    {
        if ($run === null) {
            return __('Нет отчёта');
        }

        if (! $run->status->isTerminal()) {
            return __('Формируется');
        }

        if ($run->status !== AiRunStatus::Succeeded) {
            return __('Ошибка');
        }

        return match ($run->human_review_status) {
            HumanReviewStatus::Accepted, HumanReviewStatus::EditedAndAccepted => __('Проверено'),
            HumanReviewStatus::Rejected => __('Отклонено'),
            default => __('Требует проверки'),
        };
    }

    public static function courseStatusColor(?AiRun $run): string
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

        return CrmLabel::enum($status) ?? __('Проверка не определена');
    }

    public static function reviewColor(HumanReviewStatus|string $status): string
    {
        $value = $status instanceof HumanReviewStatus ? $status->value : $status;
        $reviewStatus = HumanReviewStatus::tryFrom($value);

        if ($reviewStatus === null) {
            foreach (HumanReviewStatus::cases() as $candidate) {
                if (CrmLabel::enum($candidate) === $value) {
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

    public static function reviewGuidance(HumanReviewStatus|string $status, ?string $workflowKey = null): string
    {
        $status = $status instanceof HumanReviewStatus ? $status : HumanReviewStatus::tryFrom($status);
        $resultName = $workflowKey === ClinicalSynthesizerWorkflow::CourseReport->value
            ? __('итогового отчёта курса')
            : __('клинического резюме');

        return match ($status) {
            HumanReviewStatus::PendingReview => implode("\n", [
                __('Результат сохранён в истории Клинического AI.'),
                __('Он ещё не подтверждён специалистом.'),
                __('Клиенту ничего не отправлено.'),
                __('В медицинский профиль данные автоматически не внесены.'),
                __('Проверьте результат, чтобы использовать его в дальнейшем клиническом анализе.'),
            ]),
            HumanReviewStatus::Accepted, HumanReviewStatus::EditedAndAccepted => implode("\n", [
                __('Результат проверен специалистом и сохранён в истории.'),
                __('Он может использоваться при формировании :result.', ['result' => $resultName]),
                __('В медицинский профиль данные автоматически не внесены.'),
            ]),
            HumanReviewStatus::Rejected => implode("\n", [
                __('Результат отклонён специалистом и сохранён в истории.'),
                __('Он не используется как подтверждённый источник для :result.', ['result' => $resultName]),
            ]),
            default => implode("\n", [
                __('Результат сохранён в истории Клинического AI.'),
                __('Дополнительная проверка специалиста не требуется.'),
                __('Клиенту ничего не отправлено.'),
                __('В медицинский профиль данные автоматически не внесены.'),
            ]),
        };
    }

    public static function result(AiCapability|string $capability, ?array $payload, ?string $text, ?string $workflowKey = null): string
    {
        $safeText = self::safeText($text);
        if ($safeText !== null) {
            return $safeText;
        }

        $capability = $capability instanceof AiCapability ? $capability : AiCapability::tryFrom($capability);
        if ($capability === null || $payload === null) {
            return __('Результат получен, но не может быть отображён в текущем формате.');
        }

        return match ($capability) {
            AiCapability::ClinicalDocumentExtraction => self::documentResult($payload),
            AiCapability::PostureAnalysis => self::postureResult($payload),
            AiCapability::ClinicalSynthesizer => $workflowKey === ClinicalSynthesizerWorkflow::CourseReport->value
                ? self::courseReportResult($payload)
                : self::synthesizerResult($payload),
            default => null,
        } ?? __('Результат получен, но не может быть отображён в текущем формате.');
    }

    public static function preview(AiCapability|string $capability, ?array $payload, ?string $text, ?string $workflowKey = null): string
    {
        $result = trim(self::result($capability, $payload, $text, $workflowKey));
        if ($result === '') {
            return __('Результат получен, но не может быть отображён в текущем формате.');
        }

        $lines = preg_split('/\R+/u', $result, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $preview = implode("\n", array_slice(array_map(static fn (string $line): string => trim($line), $lines), 0, 5));

        return Str::limit($preview !== '' ? $preview : $result, 700);
    }

    public static function failure(?AiErrorCategory $category, ?Throwable $exception = null): string
    {
        if ($category !== null) {
            return match ($category) {
                AiErrorCategory::InvalidPrompt => __('Нет активной версии промпта для этого анализа.'),
                AiErrorCategory::ProviderUnavailable => __('Сервис AI отключён или временно недоступен.'),
                AiErrorCategory::AuthenticationFailed => __('Проверьте подключение AI.'),
                AiErrorCategory::OutputSchemaValidationFailed => __('Ответ AI не прошёл проверку структуры.'),
                AiErrorCategory::ExecutionTimedOut => __('AI не успел завершить анализ. Повторите запуск.'),
                AiErrorCategory::SafetyKillSwitchActive => __('AI отключён политикой безопасности организации.'),
                default => CrmLabel::enum($category),
            };
        }

        $message = strtolower((string) $exception?->getMessage());
        if (str_contains($message, 'active prompt')) {
            return __('Нет активной версии промпта для этого анализа.');
        }
        if (str_contains($message, 'model') || str_contains($message, 'provider')) {
            return __('Проверьте активную модель и подключение AI.');
        }
        if (str_contains($message, 'attachment') || str_contains($message, 'вложен') || str_contains($message, 'фото')) {
            return __('Файл недоступен, повреждён или не прошёл проверку приватного хранилища.');
        }

        return __('Не удалось запустить анализ. Проверьте настройки AI или повторите попытку.');
    }

    public static function resultActionLabel(?string $workflowKey): string
    {
        return $workflowKey === ClinicalSynthesizerWorkflow::CourseReport->value
            ? __('Открыть итоговый отчёт')
            : __('Открыть результат');
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

    private static function courseReportResult(array $payload): ?string
    {
        return self::joinSections([
            self::section('Итоговая сводка курса', $payload['course_summary'] ?? null),
            self::section('Исходное состояние', $payload['initial_state'] ?? null),
            self::listSection('Что происходило в течение курса', $payload['course_events'] ?? null),
            self::listSection('Наблюдаемая динамика', $payload['observed_dynamics'] ?? null),
            self::section('Текущее состояние', $payload['current_state'] ?? null),
            self::listSection('Сохраняющиеся ограничения и риски', $payload['ongoing_limitations_risks'] ?? null),
            self::listSection('Нерешённые вопросы', $payload['unresolved_questions'] ?? null),
            self::listSection('Гипотезы для профессиональной проверки', $payload['hypotheses'] ?? null),
            self::listSection('Недостающая информация и ограничения отчёта', $payload['missing_information'] ?? null),
        ]);
    }

    private static function section(string $label, mixed $value): ?string
    {
        $text = self::scalarText($value);

        return $text === null ? null : __($label).":\n".$text;
    }

    private static function listSection(string $label, mixed $value): ?string
    {
        $items = self::scalarList($value);
        if ($items === []) {
            return null;
        }

        return __($label).":\n- ".implode("\n- ", $items);
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
                'location' => __('Область'),
                'pathology' => __('Изменение'),
                'impact' => __('Влияние'),
            ] as $key => $fieldLabel) {
                $field = self::scalarText($finding[$key] ?? null);
                if ($field !== null) {
                    $parts[] = $fieldLabel.': '.$field;
                }
            }

            $size = self::scalarText($finding['size_mm'] ?? null);
            if ($size !== null) {
                $parts[] = __('Размер: :size мм', ['size' => $size]);
            }

            if ($parts !== []) {
                $items[] = implode(' · ', $parts);
            }
        }

        return $items === [] ? null : __($label).":\n- ".implode("\n- ", $items);
    }

    private static function visualFindingsSection(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $planes = [
            'front' => __('Спереди'),
            'side' => __('Сбоку'),
            'back' => __('Сзади'),
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

            $plane = $planes[(string) ($finding['plane'] ?? '')] ?? __('Наблюдения');
            $items[] = $plane.': '.implode('; ', $observations);
        }

        return $items === [] ? null : __('Визуальные наблюдения:')."\n- ".implode("\n- ", $items);
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
                $parts[] = __('Формулировка:').' '.$statement;
            }
            $facts = self::scalarList($hypothesis['supporting_facts'] ?? null);
            if ($facts !== []) {
                $parts[] = __('Основание:').' '.implode('; ', $facts);
            }
            $uncertainty = self::scalarText($hypothesis['uncertainty'] ?? null);
            if ($uncertainty !== null) {
                $parts[] = __('Неопределённость:').' '.$uncertainty;
            }

            if ($parts !== []) {
                $items[] = implode(' · ', $parts);
            }
        }

        return $items === [] ? null : __('Гипотезы:')."\n- ".implode("\n- ", $items);
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
            return $value ? __('Да') : __('Нет');
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
