<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Schemas;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\RelationManagers\ClientClinicalAiRelationManager;
use App\Filament\Resources\Clients\Schemas\ClinicalProfileInfolist;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\CrmLabel;
use App\Models\User;
use App\Modules\AI\Application\Services\ReadClinicalAiClientSummary;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\MedicalFrequencyPeriodCatalog;
use App\Modules\MedicalProfiles\Application\MedicalProfileAuthorization;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Sessions\Application\DTOs\MedicalSessionData;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\GetSessionDynamics;
use App\Modules\Sessions\Application\ListSessionAttachments;
use App\Modules\Sessions\Application\MedicalSessionAuthorization;
use App\Modules\Sessions\Domain\Enums\MedicalSessionAttachmentGallery;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

final class SessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Сеанс'))
                    ->schema([
                        TextEntry::make('occurred_at')
                            ->label(__('Дата и время сеанса'))
                            ->dateTime('d.m.Y H:i')
                            ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone()),
                        TextEntry::make('specialist.display_name')
                            ->label(__('Специалист'))
                            ->placeholder('—')
                            ->url(fn (MedicalSession $record): ?string => CrmEntityLinks::specialistUrl($record->specialist))
                            ->color(fn (MedicalSession $record): ?string => CrmEntityLinks::specialistUrl($record->specialist) === null ? null : 'primary'),
                        TextEntry::make('bookingLabel')
                            ->label(__('Запись на приём'))
                            ->state(function (MedicalSession $record, TextEntry $entry): string {
                                $booking = $record->booking;

                                if (! $booking) {
                                    return __('Без записи на приём');
                                }

                                $date = Carbon::parse((string) $booking->getAttribute('starts_at'), 'UTC')
                                    ->setTimezone(app(OrganizationContext::class)->defaultTimezone())
                                    ->format('d.m.Y H:i');
                                $status = self::statusLabel($booking->status);
                                $parts = array_filter([$date, $status], static fn ($v): bool => filled($v));

                                return $parts ? implode(' · ', $parts) : __('Дата не указана');
                            }),
                    ])->columns(3),
                Section::make(__('Карта здоровья клиента'))
                    ->description(__('Актуальные данные медицинского профиля'))
                    ->schema(ClinicalProfileInfolist::profile())->columns(2)
                    ->visible(function (MedicalSession $record): bool {
                        $actor = auth()->user();
                        $client = $record->client;

                        return $actor instanceof User
                            && app(MedicalProfileAuthorization::class)->allowsView($actor, $client);
                    }),
                Section::make(__('Клиническое резюме'))
                    ->schema([
                        ViewEntry::make('clinicalSummary')
                            ->hiddenLabel()
                            ->view(
                                'filament.resources.clients.session-clinical-summary',
                                fn (MedicalSession $record, ViewEntry $entry): array => self::clinicalSummaryViewData($record, $entry),
                            )
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (MedicalSession $record, Section $section): bool => self::clinicalSummary($record, $section) !== null),
                Section::make(__('Боли'))
                    ->schema([
                        RepeatableEntry::make('pains')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('name')->label(__('Боль'))->wrap(),
                                TextEntry::make('frequency')->label(__('Частота'))->wrap(),
                                TextEntry::make('time_of_day')->label(__('Время суток'))->wrap(),
                                TextEntry::make('before_score')->label(__('До сеанса'))->suffix('/10')->placeholder(__('Не указано')),
                                TextEntry::make('after_score')->label(__('После сеанса'))->suffix('/10')->placeholder(__('Не указано')),
                                TextEntry::make('previous_after_score')->label(__('После предыдущего сеанса'))->suffix('/10')->placeholder(__('Не указано')),
                            ])
                            ->columns(3)
                            ->state(fn (MedicalSession $record, RepeatableEntry $entry): array => self::pains($record, $entry))
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (MedicalSession $record, Section $section): bool => self::hasPains($record, $section)),
                Section::make(__('Особые жалобы'))
                    ->schema([
                        TextEntry::make('special_complaints')
                            ->hiddenLabel()
                            ->state(fn (MedicalSession $record, TextEntry $entry): ?string => self::sessionData($record, $entry)?->pain)
                            ->placeholder(__('Не заполнено'))
                            ->columnSpanFull()
                            ->wrap(),
                    ])
                    ->visible(fn (MedicalSession $record, Section $section): bool => self::hasSpecialComplaints($record, $section)),
                Section::make(__('Клинические тесты и причины'))
                    ->schema([
                        TextEntry::make('test_counters')
                            ->label(__('Нарушения по тестам'))
                            ->state(fn (MedicalSession $record, TextEntry $entry): ?string => self::testCounters($record, $entry))
                            ->placeholder(__('Тесты пока не заполнены'))
                            ->columnSpanFull(),
                        RepeatableEntry::make('test_results')
                            ->label(__('Проведённые тесты'))
                            ->schema([
                                TextEntry::make('test')->label(__('Тест')),
                                TextEntry::make('before')->label(__('До сеанса')),
                                TextEntry::make('after')->label(__('После сеанса')),
                                TextEntry::make('causes')->label(__('Причины нарушений'))->placeholder(__('Не указаны'))->columnSpanFull(),
                            ])
                            ->columns(3)
                            ->state(fn (MedicalSession $record, RepeatableEntry $entry): array => self::testResults($record, $entry))
                            ->columnSpanFull(),
                        TextEntry::make('causes')
                            ->label(__('Все причины нарушений'))
                            ->listWithLineBreaks()
                            ->placeholder(__('Не указаны'))
                            ->state(fn (MedicalSession $record, TextEntry $entry): array => self::causes($record, $entry)),
                        RepeatableEntry::make('root_causes')
                            ->label(__('Первопричины'))
                            ->schema([
                                TextEntry::make('cause')->label(__('Причина'))->placeholder(__('Свободное описание')),
                                TextEntry::make('free_text')->label(__('Комментарий'))->placeholder(__('Нет комментария'))->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->state(fn (MedicalSession $record, RepeatableEntry $entry): array => self::rootCauses($record, $entry))
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (MedicalSession $record, Section $section): bool => self::hasTestsOrCauses($record, $section)),
                Section::make(__('Протокол и результат сеанса'))
                    ->schema([
                        TextEntry::make('root_cause_hypothesis')
                            ->label(__('Первопричина'))
                            ->state(fn (MedicalSession $record, TextEntry $entry): ?string => self::sessionData($record, $entry)?->rootCauseHypothesis)
                            ->placeholder(__('Не заполнено'))
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('protocol')
                            ->label(__('Алгоритм / протокол сеанса'))
                            ->state(fn (MedicalSession $record, TextEntry $entry): ?string => self::sessionData($record, $entry)?->protocol)
                            ->placeholder(__('Не заполнено'))
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('subjective_feelings')
                            ->label(__('Субъективные ощущения клиента после сеанса'))
                            ->state(fn (MedicalSession $record, TextEntry $entry): ?string => self::sessionData($record, $entry)?->subjectiveFeelings)
                            ->placeholder(__('Не заполнено'))
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('visual_posture_diagnostics')
                            ->label(__('Визуальная диагностика осанки'))
                            ->state(fn (MedicalSession $record, TextEntry $entry): ?string => self::sessionData($record, $entry)?->visualPostureDiagnostics)
                            ->placeholder(__('Не заполнено'))
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('result')
                            ->label(__('Результат после сеанса'))
                            ->state(fn (MedicalSession $record, TextEntry $entry): ?string => self::sessionData($record, $entry)?->result)
                            ->placeholder(__('Не заполнено'))
                            ->columnSpanFull()
                            ->wrap(),
                    ])
                    ->visible(fn (MedicalSession $record, Section $section): bool => self::hasCurrentClinicalFields($record, $section)),
                Section::make(__('Динамика подтверждённых фактов'))
                    ->schema([
                        RepeatableEntry::make('comparison')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('period')->label(__('Период'))->columnSpanFull(),
                                TextEntry::make('occurred_at')->label(__('Дата')),
                                TextEntry::make('specialist')->label(__('Специалист')),
                                TextEntry::make('booking')->label(__('Запись на приём'))->columnSpanFull(),
                                TextEntry::make('pain')->label(__('Особые жалобы'))->placeholder(__('Не заполнено')),
                                TextEntry::make('pain_vas')->label(__('Шкала боли'))->suffix('/10')->placeholder(__('Не указана')),
                                TextEntry::make('tests')->label(__('Тесты'))->placeholder(__('Не заполнено')),
                                TextEntry::make('observations')->label(__('Наблюдения'))->placeholder(__('Не заполнено')),
                                TextEntry::make('root_cause_hypothesis')->label(__('Первопричина'))->placeholder(__('Не заполнено')),
                                TextEntry::make('protocol')->label(__('Протокол'))->placeholder(__('Не заполнено')),
                                TextEntry::make('result')->label(__('Результат после сеанса'))->placeholder(__('Не заполнено')),
                            ])
                            ->columns(2)
                            ->state(function (MedicalSession $record, RepeatableEntry $entry): array {
                                $actor = auth()->user();
                                $livewire = $entry->getLivewire();
                                $parent = method_exists($livewire, 'getParentRecord') ? $livewire->getParentRecord() : null;

                                if (! $actor instanceof User || ! $parent instanceof Client) {
                                    return [];
                                }

                                return app(GetSessionDynamics::class)->handle($actor, $record, $parent)->comparison();
                            })
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Фото до сеанса'))
                    ->schema([
                        ViewEntry::make('beforePhotos')
                            ->hiddenLabel()
                            ->view(
                                'filament.resources.clients.session-photo-gallery',
                                fn (MedicalSession $record, ViewEntry $entry): array => self::photoGalleryViewData($record, $entry, MedicalSessionAttachmentGallery::Before),
                            )
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Фото после сеанса'))
                    ->schema([
                        ViewEntry::make('afterPhotos')
                            ->hiddenLabel()
                            ->view(
                                'filament.resources.clients.session-photo-gallery',
                                fn (MedicalSession $record, ViewEntry $entry): array => self::photoGalleryViewData($record, $entry, MedicalSessionAttachmentGallery::After),
                            )
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Файлы сеанса'))
                    ->schema([
                        RepeatableEntry::make('attachments')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('filename')
                                    ->label(__('Файл'))
                                    ->url(fn (Get $get): ?string => $get('preview_url') ?? $get('download_url'))
                                    ->openUrlInNewTab()
                                    ->columnSpanFull(),
                                TextEntry::make('type')->label(__('Тип')),
                                TextEntry::make('gallery')->label(__('Раздел')),
                                TextEntry::make('size')->label(__('Размер')),
                                TextEntry::make('uploaded_at')->label(__('Загружен')),
                                TextEntry::make('status')->label(__('Состояние')),
                                TextEntry::make('download')
                                    ->label(__('Скачать'))
                                    ->state(__('Скачать'))
                                    ->url(fn (Get $get): ?string => $get('download_url'))
                                    ->openUrlInNewTab(),
                            ])
                            ->columns(2)
                            ->state(function (MedicalSession $record, RepeatableEntry $entry): array {
                                $actor = auth()->user();
                                $livewire = $entry->getLivewire();
                                $parent = method_exists($livewire, 'getParentRecord') ? $livewire->getParentRecord() : null;

                                if (! $actor instanceof User || ! $parent instanceof Client) {
                                    return [];
                                }

                                return array_map(
                                    static fn ($attachment): array => $attachment->toArray(),
                                    app(ListSessionAttachments::class)->handle($actor, $record, $parent),
                                );
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function statusLabel(BookingStatus $status): string
    {
        return CrmLabel::enum($status) ?? __('Без статуса');
    }

    /** @return array{summary: array<string, mixed>|null, clinicalAiUrl: string|null} */
    private static function clinicalSummaryViewData(MedicalSession $record, ViewEntry $entry): array
    {
        $summary = self::clinicalSummary($record, $entry);

        return [
            'summary' => $summary,
            'clinicalAiUrl' => $summary === null ? null : self::clinicalAiUrl(self::parentClient($record, $entry)),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function clinicalSummary(MedicalSession $record, Component $component): ?array
    {
        $actor = auth()->user();
        $client = self::parentClient($record, $component);

        if (! $actor instanceof User || $client === null || ! app(MedicalSessionAuthorization::class)->allowsView($actor, $record)) {
            return null;
        }

        return app(ReadClinicalAiClientSummary::class)->handle($actor, $client);
    }

    /** @return list<array<string, mixed>> */
    private static function testResults(MedicalSession $record, Component $component): array
    {
        $data = self::sessionData($record, $component);
        if (! $data instanceof MedicalSessionData) {
            return [];
        }

        return array_map(static fn ($test): array => [
            'test' => $test->testName,
            'before' => $test->beforeLabel ?? __('Не указано'),
            'after' => $test->afterLabel ?? __('Не указано'),
            'causes' => collect($test->causes)->pluck('name')->implode(', '),
        ], $data->testResults);
    }

    /** @return list<string> */
    private static function causes(MedicalSession $record, Component $component): array
    {
        $data = self::sessionData($record, $component);
        if (! $data instanceof MedicalSessionData) {
            return [];
        }

        return array_values(collect($data->causes)
            ->pluck('name')
            ->filter(static fn (mixed $name): bool => is_string($name))
            ->map(static fn (mixed $name): string => $name)
            ->values()
            ->all());
    }

    /** @return list<array<string, mixed>> */
    private static function rootCauses(MedicalSession $record, Component $component): array
    {
        $data = self::sessionData($record, $component);
        if (! $data instanceof MedicalSessionData) {
            return [];
        }

        return array_map(static fn ($cause): array => [
            'cause' => $cause->causeName,
            'free_text' => $cause->freeText,
        ], $data->rootCauses);
    }

    private static function hasTestsOrCauses(MedicalSession $record, Component $component): bool
    {
        $data = self::sessionData($record, $component);

        return $data instanceof MedicalSessionData && ($data->testResults !== [] || $data->causes !== [] || $data->rootCauses !== []);
    }

    private static function hasCurrentClinicalFields(MedicalSession $record, Component $component): bool
    {
        $data = self::sessionData($record, $component);

        return $data instanceof MedicalSessionData
            && ($data->rootCauseHypothesis !== null
                || $data->protocol !== null
                || $data->subjectiveFeelings !== null
                || $data->visualPostureDiagnostics !== null
                || $data->result !== null);
    }

    private static function testCounters(MedicalSession $record, Component $component): ?string
    {
        $data = self::sessionData($record, $component);
        if (! $data instanceof MedicalSessionData || $data->testResults === []) {
            return null;
        }

        $before = count(array_filter($data->testResults, static fn ($test): bool => $test->beforeAbnormal));
        $after = count(array_filter($data->testResults, static fn ($test): bool => $test->afterAbnormal));

        return __('До: :before · После: :after', [
            'before' => $before,
            'after' => $after,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function pains(MedicalSession $record, Component $component): array
    {
        $data = self::sessionData($record, $component);
        if (! $data instanceof MedicalSessionData) {
            return [];
        }

        $periods = app(MedicalFrequencyPeriodCatalog::class)->options(app(OrganizationContext::class)->id());
        foreach (collect($data->pains)->pluck('frequencyPeriodUnit')->filter(static fn (mixed $key): bool => is_string($key))->unique() as $periodKey) {
            $periods = [
                ...$periods,
                ...app(MedicalFrequencyPeriodCatalog::class)->optionsForSelection(app(OrganizationContext::class)->id(), (string) $periodKey),
            ];
        }
        $timeLabels = [
            'all' => __('Весь день'),
            'morning' => __('Утро'),
            'day' => __('День'),
            'evening' => __('Вечер'),
            'night' => __('Ночь'),
        ];

        return array_map(static function ($pain) use ($periods, $timeLabels): array {
            $periodKey = $pain->frequencyPeriodUnit;
            $periodLabel = is_string($periodKey) ? ($periods[$periodKey] ?? __('период')) : __('период');
            $frequency = $pain->frequencyMode === 'count'
                ? __(':count раз за :period', [
                    'count' => $pain->frequencyCount ?? '—',
                    'period' => $periodLabel,
                ])
                : __('Постоянная');

            $times = array_map(
                static fn (string $time): string => $timeLabels[$time] ?? $time,
                $pain->timeOfDay,
            );

            return [
                'name' => $pain->name,
                'frequency' => $frequency,
                'time_of_day' => implode(', ', $times),
                'before_score' => $pain->beforeScore,
                'after_score' => $pain->afterScore,
                'previous_after_score' => $pain->previousAfterScore,
            ];
        }, $data->pains);
    }

    private static function hasPains(MedicalSession $record, Component $component): bool
    {
        return self::pains($record, $component) !== [];
    }

    /** @return array{attachments: list<array<string, mixed>>} */
    private static function photoGalleryViewData(MedicalSession $record, Component $component, MedicalSessionAttachmentGallery $gallery): array
    {
        $actor = auth()->user();
        $parent = self::parentClient($record, $component);
        if (! $actor instanceof User || ! $parent instanceof Client) {
            return ['attachments' => []];
        }

        $attachments = array_map(
            static fn ($attachment): array => $attachment->toArray(),
            app(ListSessionAttachments::class)->handle($actor, $record, $parent),
        );

        return [
            'attachments' => array_values(array_filter(
                $attachments,
                static fn (array $attachment): bool => $attachment['gallery_key'] === $gallery->value,
            )),
        ];
    }

    private static function hasSpecialComplaints(MedicalSession $record, Component $component): bool
    {
        return filled(self::sessionData($record, $component)?->pain);
    }

    private static function sessionData(MedicalSession $record, Component $component): ?MedicalSessionData
    {
        $actor = auth()->user();
        $client = self::parentClient($record, $component);

        if (! $actor instanceof User || ! $client instanceof Client) {
            return null;
        }

        return app(GetSession::class)->handle($actor, $record, $client);
    }

    private static function parentClient(MedicalSession $record, Component $component): ?Client
    {
        $livewire = $component->getLivewire();
        $parent = method_exists($livewire, 'getParentRecord') ? $livewire->getParentRecord() : null;

        return $parent instanceof Client && (int) $record->client_id === (int) $parent->getKey() ? $parent : null;
    }

    private static function clinicalAiUrl(?Client $client): ?string
    {
        if (! $client instanceof Client) {
            return null;
        }

        $clinicalRelation = array_search(ClientClinicalAiRelationManager::class, ClientResource::getRelations(), true);

        return $clinicalRelation === false
            ? ClientResource::getUrl('view', ['record' => $client])
            : ClientResource::getUrl('view', [
                'record' => $client,
                'relation' => (string) $clinicalRelation,
            ]);
    }
}
