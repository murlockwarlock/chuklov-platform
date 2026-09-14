<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Modules\AI\Application\Actions\GetClinicalAiResult;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ClinicalAiResultAction
{
    public static function make(string $name, User $actor, Client $client, Closure $resolveRun): Action
    {
        $canViewTrace = self::canViewTrace($actor);

        return Action::make($name)
            ->label('Открыть результат')
            ->icon('heroicon-o-eye')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->fillForm(function (Model $record) use ($actor, $client, $resolveRun, $canViewTrace): array {
                $run = $resolveRun($record);
                abort_unless($run instanceof AiRun, 404);
                $result = app(GetClinicalAiResult::class)->handle($actor, $run->id, $client->id);
                $form = [
                    'result' => ClinicalAiPresentation::result($run->capability, $result->outputPayload, $result->outputText),
                    'review' => ClinicalAiPresentation::review($run->human_review_status),
                    'lifecycle' => ClinicalAiPresentation::reviewGuidance($run->human_review_status),
                    'sources' => self::sourceText($run, $result->attachmentProvenance),
                ];

                if ($canViewTrace) {
                    $form['technical'] = self::technicalText($run);
                }

                return $form;
            })
            ->schema(self::schema($canViewTrace));
    }

    private static function canViewTrace(User $actor): bool
    {
        try {
            $organization = app(OrganizationContext::class)->organization();

            return app(OrganizationAuthorizer::class)->allows(
                $actor,
                $organization,
                OrganizationPermission::ViewAiTrace,
            );
        } catch (LogicException) {
            return false;
        }
    }

    private static function schema(bool $canViewTrace): array
    {
        $schema = [
            Textarea::make('result')
                ->label('Результат анализа')
                ->rows(14)
                ->disabled()
                ->dehydrated(false),
            Textarea::make('review')
                ->label('Проверка специалиста')
                ->rows(2)
                ->disabled()
                ->dehydrated(false),
            Textarea::make('lifecycle')
                ->label('Состояние результата')
                ->rows(5)
                ->disabled()
                ->dehydrated(false),
            Textarea::make('sources')
                ->label('Источники')
                ->rows(4)
                ->disabled()
                ->dehydrated(false),
        ];

        if ($canViewTrace) {
            $schema[] = Section::make('Техническая информация (аудит)')
                ->schema([
                    Textarea::make('technical')
                        ->label('Версии и время запуска')
                        ->rows(4)
                        ->disabled()
                        ->dehydrated(false),
                ])
                ->collapsed();
        }

        return $schema;
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

        $sources = collect($provenance)
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

        return $sources !== '' ? $sources : 'Профиль клиента и выбранные источники анализа.';
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
