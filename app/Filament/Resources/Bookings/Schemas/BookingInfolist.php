<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
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
                            ->with(['actorUser', 'actorClient'])
                            ->orderBy('occurred_at')
                            ->get()
                            ->map(fn (BookingEvent $event): string => self::formatHistoryEvent($event))
                            ->implode("\n");
                    })
                    ->columnSpanFull(),
            ]);
    }

    private static function formatHistoryEvent(BookingEvent $event): string
    {
        $oldStatus = self::safeValue($event->old_values, 'status');
        $newStatus = self::safeValue($event->new_values, 'status');
        $oldStart = self::safeValue($event->old_values, 'starts_at');
        $newStart = self::safeValue($event->new_values, 'starts_at');
        $actor = match ($event->actor_type) {
            'user' => $event->actorUser instanceof User ? $event->actorUser->name : 'Staff',
            'client' => $event->actorClient instanceof Client ? $event->actorClient->full_name : 'Client',
            default => 'System',
        };
        $values = [
            $event->event_type->value,
            $event->occurred_at->toIso8601String(),
            $actor.' ('.$event->actor_type.')',
        ];

        if ($oldStatus !== null || $newStatus !== null) {
            $values[] = 'status: '.($oldStatus ?? '—').' → '.($newStatus ?? '—');
        }

        if ($oldStart !== null || $newStart !== null) {
            $values[] = 'time: '.($oldStart ?? '—').' → '.($newStart ?? '—');
        }

        if ($event->reason !== null) {
            $values[] = 'reason: '.$event->reason;
        }

        return implode(' · ', $values);
    }

    /** @param array<string, mixed> $values */
    private static function safeValue(array $values, string $key): ?string
    {
        return isset($values[$key]) && is_string($values[$key]) ? $values[$key] : null;
    }
}
