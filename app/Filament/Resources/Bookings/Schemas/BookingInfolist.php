<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class BookingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('client.full_name')->label('Client'),
                TextEntry::make('specialist.display_name')->label('Specialist'),
                TextEntry::make('service.name')->label('Service'),
                TextEntry::make('visit_format')->label('Visit format'),
                TextEntry::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (BookingStatus|string $state): string => $state instanceof BookingStatus ? $state->value : $state),
                TextEntry::make('payment_status')->label('Payment status'),
                TextEntry::make('payment_requirement')->label('Payment handoff'),
                TextEntry::make('party_size')->label('Party size'),
                TextEntry::make('starts_at')->label('Starts')->dateTime(),
                TextEntry::make('ends_at')->label('Ends')->dateTime(),
                TextEntry::make('blocking_ends_at')->label('Buffer ends')->dateTime(),
                TextEntry::make('schedule_timezone')->label('Schedule timezone'),
                TextEntry::make('client_timezone')->label('Client timezone'),
                TextEntry::make('source')->label('Source'),
                TextEntry::make('requested_at')->label('Requested')->dateTime(),
                TextEntry::make('location')->label('Location'),
                TextEntry::make('meeting_link_mode')->label('Meeting-link mode'),
                TextEntry::make('meeting_url')->label('Meeting URL'),
                TextEntry::make('calendar_uid')->label('Calendar UID'),
                TextEntry::make('event_version')->label('Event version'),
                TextEntry::make('history')
                    ->label('Lifecycle history')
                    ->state(function (Booking $record): string {
                        return $record->events()
                            ->orderBy('occurred_at')
                            ->get()
                            ->map(fn ($event): string => implode(' · ', array_filter([
                                $event->event_type->value,
                                $event->actor_type,
                                $event->occurred_at->toIso8601String(),
                                $event->reason,
                            ])))
                            ->implode("\n");
                    })
                    ->columnSpanFull(),
            ]);
    }
}
