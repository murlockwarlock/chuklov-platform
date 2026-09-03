<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Schemas;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Sessions\Application\GetSessionDynamics;
use App\Modules\Sessions\Application\ListSessionAttachments;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
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
                Section::make('Сеанс')
                    ->schema([
                        TextEntry::make('occurred_at')
                            ->label('Дата и время сеанса')
                            ->dateTime('d.m.Y H:i')
                            ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone()),
                        TextEntry::make('specialist.display_name')
                            ->label('Специалист')
                            ->placeholder('—'),
                        TextEntry::make('bookingLabel')
                            ->label('Запись на приём')
                            ->state(function (MedicalSession $record, TextEntry $entry): string {
                                $booking = $record->booking;

                                if (! $booking) {
                                    return 'Без записи на приём';
                                }

                                $date = Carbon::parse((string) $booking->getAttribute('starts_at'), 'UTC')
                                    ->setTimezone(app(OrganizationContext::class)->defaultTimezone())
                                    ->format('d.m.Y H:i');
                                $status = self::statusLabel($booking->status);
                                $parts = array_filter([$date, $status], static fn ($v): bool => filled($v));

                                return $parts ? implode(' · ', $parts) : '#'.$booking->getKey();
                            }),
                    ])->columns(3),
                Section::make('Динамика подтверждённых фактов')
                    ->schema([
                        RepeatableEntry::make('comparison')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('period')->label('Период')->columnSpanFull(),
                                TextEntry::make('occurred_at')->label('Дата'),
                                TextEntry::make('specialist')->label('Специалист'),
                                TextEntry::make('booking')->label('Запись на приём')->columnSpanFull(),
                                TextEntry::make('pain')->label('Боль')->placeholder('Не заполнено'),
                                TextEntry::make('tests')->label('Тесты')->placeholder('Не заполнено'),
                                TextEntry::make('observations')->label('Наблюдения')->placeholder('Не заполнено'),
                                TextEntry::make('root_cause_hypothesis')->label('Первопричина')->placeholder('Не заполнено'),
                                TextEntry::make('protocol')->label('Протокол')->placeholder('Не заполнено'),
                                TextEntry::make('result')->label('Результат')->placeholder('Не заполнено'),
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
                Section::make('Файлы сеанса')
                    ->schema([
                        RepeatableEntry::make('attachments')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('filename')
                                    ->label('Файл')
                                    ->url(fn (Get $get): ?string => $get('download_url'))
                                    ->openUrlInNewTab()
                                    ->columnSpanFull(),
                                TextEntry::make('type')->label('Тип'),
                                TextEntry::make('size')->label('Размер'),
                                TextEntry::make('status')->label('Состояние'),
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
        return match ($status) {
            BookingStatus::Requested => 'Ожидает подтверждения',
            BookingStatus::PendingReview => 'На рассмотрении',
            BookingStatus::Confirmed => 'Подтверждена',
            BookingStatus::Rejected => 'Отклонена',
            BookingStatus::Cancelled => 'Отменена',
            BookingStatus::Completed => 'Завершена',
            BookingStatus::NoShow => 'Не состоялась',
        };
    }
}
