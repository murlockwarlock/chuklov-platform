<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Schemas;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Sessions\Application\DTOs\MedicalSessionData;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
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
                            ->dateTime('d.m.Y H:i'),
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

                                $date = Carbon::parse((string) $booking->getAttribute('starts_at'))->format('d.m.Y H:i');
                                $status = self::statusLabel($booking->status);
                                $parts = array_filter([$date, $status], static fn ($v): bool => filled($v));

                                return $parts ? implode(' · ', $parts) : '#'.$booking->getKey();
                            }),
                    ])->columns(3),
                Section::make('Клинические заметки')
                    ->schema([
                        TextEntry::make('pain')->label('Боль')
                            ->state(fn (MedicalSession $record, TextEntry $entry): ?string => self::dto($record, $entry)?->pain)
                            ->placeholder('Не заполнено')
                            ->columnSpanFull(),
                        TextEntry::make('tests')->label('Тесты')
                            ->state(fn (MedicalSession $record, TextEntry $entry): ?string => self::dto($record, $entry)?->tests)
                            ->placeholder('Не заполнено')
                            ->columnSpanFull(),
                        TextEntry::make('observations')->label('Наблюдения')
                            ->state(fn (MedicalSession $record, TextEntry $entry): ?string => self::dto($record, $entry)?->observations)
                            ->placeholder('Не заполнено')
                            ->columnSpanFull(),
                        TextEntry::make('root_cause_hypothesis')->label('Гипотеза первопричины')
                            ->state(fn (MedicalSession $record, TextEntry $entry): ?string => self::dto($record, $entry)?->rootCauseHypothesis)
                            ->placeholder('Не заполнено')
                            ->columnSpanFull(),
                        TextEntry::make('protocol')->label('Протокол')
                            ->state(fn (MedicalSession $record, TextEntry $entry): ?string => self::dto($record, $entry)?->protocol)
                            ->placeholder('Не заполнено')
                            ->columnSpanFull(),
                        TextEntry::make('result')->label('Результат')
                            ->state(fn (MedicalSession $record, TextEntry $entry): ?string => self::dto($record, $entry)?->result)
                            ->placeholder('Не заполнено')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function dto(MedicalSession $record, TextEntry $entry): ?MedicalSessionData
    {
        $actor = auth()->user();
        $livewire = $entry->getLivewire();
        $parent = method_exists($livewire, 'getParentRecord') ? $livewire->getParentRecord() : null;

        if (! $actor instanceof User) {
            return null;
        }

        return app(GetSession::class)->handle($actor, $record, $parent instanceof Client ? $parent : null);
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
