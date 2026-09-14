<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Modules\AI\Application\Actions\GetClinicalAiResult;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Identity\Domain\Models\Client;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;

final class ClinicalAiResultAction
{
    public static function make(string $name, User $actor, Client $client, Closure $resolveRun): Action
    {
        return Action::make($name)
            ->label('Открыть результат')
            ->icon('heroicon-o-eye')
            ->fillForm(function (Model $record) use ($actor, $client, $resolveRun): array {
                $run = $resolveRun($record);
                abort_unless($run instanceof AiRun, 404);
                $result = app(GetClinicalAiResult::class)->handle($actor, $run->id, $client->id);

                return [
                    'result' => self::resultText($result->outputPayload, $result->outputText),
                    'sources' => self::sourceText($run, $result->attachmentProvenance),
                    'technical' => self::technicalText($run),
                ];
            })
            ->schema([
                Textarea::make('result')
                    ->label('Результат анализа')
                    ->rows(14)
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('sources')
                    ->label('Источники')
                    ->rows(4)
                    ->disabled()
                    ->dehydrated(false),
                Section::make('Техническая информация (аудит)')
                    ->schema([
                        Textarea::make('technical')
                            ->label('Версии и время запуска')
                            ->rows(4)
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->collapsed(),
            ]);
    }

    private static function resultText(?array $payload, ?string $text): string
    {
        if ($payload !== null) {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: 'Результат отсутствует.';
        }

        return $text ?: 'Результат отсутствует.';
    }

    private static function sourceText(AiRun $record, array $provenance): string
    {
        if ($record->capability === AiCapability::ClinicalSynthesizer) {
            $references = collect((array) $record->input_references)->filter('is_array');
            $hasSessions = $references->contains(fn (array $reference): bool => ($reference['type'] ?? null) === 'medical_session');
            $hasSurveys = $references->contains(fn (array $reference): bool => ($reference['type'] ?? null) === 'survey_attempt');
            $hasUpstream = $references->contains(fn (array $reference): bool => ($reference['type'] ?? null) === 'ai_run');

            return implode("\n", [
                'Профиль клиента: доступен.',
                'Проверенные результаты предыдущих анализов: '.($hasUpstream ? 'доступны.' : 'отсутствуют.'),
                'История сеансов: '.($hasSessions ? 'доступна.' : 'отсутствует.'),
                'Совместимые опросы: '.($hasSurveys ? 'доступны.' : 'отсутствуют.'),
                'Источник 9 систем/MSQ и его оценивание: отсутствует в авторитетных материалах.',
            ]);
        }

        if ($provenance === []) {
            return 'Профиль клиента и выбранные источники анализа.';
        }

        return collect($provenance)
            ->map(static function (array $source): string {
                $role = match ($source['role'] ?? null) {
                    'front' => 'Спереди',
                    'side' => 'Сбоку',
                    'back' => 'Сзади',
                    default => null,
                };
                $type = match ($source['attachment_type'] ?? null) {
                    AttachmentType::MedicalReport->value => 'Медицинский документ',
                    AttachmentType::PosturePhoto->value => 'Фото осанки',
                    default => 'Защищённый файл',
                };
                $format = match (strtolower((string) ($source['mime_type'] ?? ''))) {
                    'application/pdf' => 'PDF',
                    'image/jpeg', 'image/png', 'image/webp' => 'Изображение',
                    default => null,
                };

                return trim(implode(' · ', array_filter([$role, $type, $format])));
            })
            ->filter()
            ->implode("\n");
    }

    private static function technicalText(AiRun $record): string
    {
        return implode("\n", [
            'Запуск: #'.$record->getKey(),
            'Версия промпта: '.($record->prompt_version_id ?? '—'),
            'Релиз модели: '.($record->model_release_id ?? '—'),
            'Сгенерирован: '.($record->finished_at?->format('d.m.Y H:i:s') ?? '—'),
            'Проверка: '.ClinicalAiPresentation::review($record->human_review_status),
        ]);
    }
}
