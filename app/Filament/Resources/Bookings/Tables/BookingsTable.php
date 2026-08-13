<?php

namespace App\Filament\Resources\Bookings\Tables;

use App\Models\User;
use App\Modules\Scheduling\Application\ApproveHomeVisitBooking;
use App\Modules\Scheduling\Application\BookingNeedsAttention;
use App\Modules\Scheduling\Application\CancelBooking;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Application\ConfirmBooking;
use App\Modules\Scheduling\Application\MarkBookingNoShow;
use App\Modules\Scheduling\Application\RejectHomeVisitBooking;
use App\Modules\Scheduling\Application\RescheduleBooking;
use App\Modules\Scheduling\Application\SetOnlineMeetingUrl;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\PaymentRequirementType;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client.full_name')->label('Client')->searchable()->sortable(),
                TextColumn::make('specialist.display_name')->label('Specialist')->sortable(),
                TextColumn::make('service.name')->label('Service')->sortable(),
                TextColumn::make('starts_at')->label('Starts')->dateTime()->sortable(),
                TextColumn::make('client_timezone')->label('Client timezone'),
                TextColumn::make('visit_format')->label('Format'),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('needs_attention')
                    ->label('Schedule')
                    ->badge()
                    ->state(fn (Booking $record): string => app(BookingNeedsAttention::class)->handle($record) ? 'Needs attention' : 'Aligned')
                    ->color(fn (string $state): string => $state === 'Needs attention' ? 'danger' : 'success'),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approveHomeVisit')
                    ->label('Approve home visit')
                    ->color('success')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Approval note')
                            ->maxLength(500),
                        Select::make('payment_requirement')
                            ->label('Payment requirement handoff')
                            ->options([
                                PaymentRequirementType::FullPayment->value => 'Full payment',
                                PaymentRequirementType::TransportDeposit->value => 'Transport deposit',
                            ])
                            ->nullable(),
                    ])
                    ->visible(fn (Booking $record): bool => $record->status === BookingStatus::PendingReview
                        && $record->visit_format === VisitFormat::HomeVisit)
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(ApproveHomeVisitBooking::class)->handle(
                            $actor,
                            $record,
                            $data['reason'] ?? null,
                            $data['payment_requirement'] ?? null,
                        );
                    }),
                Action::make('rejectHomeVisit')
                    ->label('Reject home visit')
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Rejection reason')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->visible(fn (Booking $record): bool => $record->status === BookingStatus::PendingReview
                        && $record->visit_format === VisitFormat::HomeVisit)
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(RejectHomeVisitBooking::class)->handle($actor, $record, (string) $data['reason']);
                    }),
                Action::make('confirm')
                    ->label('Confirm booking')
                    ->color('success')
                    ->schema([Textarea::make('reason')->label('Confirmation note')->maxLength(500)])
                    ->visible(fn (Booking $record): bool => $record->status === BookingStatus::Requested
                        && in_array($record->visit_format, [VisitFormat::Office, VisitFormat::Online], true))
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(ConfirmBooking::class)->handle($actor, $record, $data['reason'] ?? null);
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->schema([Textarea::make('reason')->label('Reason')->maxLength(500)])
                    ->visible(fn (Booking $record): bool => ! in_array($record->status->value, BookingStatus::terminalValues(), true))
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(CancelBooking::class)->handle($actor, $record, $data['reason'] ?? null);
                    }),
                Action::make('reschedule')
                    ->label('Reschedule')
                    ->schema([
                        DateTimePicker::make('starts_at')->label('New start')->seconds(false)->required(),
                        Hidden::make('expected_event_version')
                            ->default(fn (Booking $record): int => $record->event_version)
                            ->required(),
                        Textarea::make('reason')->label('Reason')->maxLength(500),
                    ])
                    ->visible(fn (Booking $record): bool => ! in_array($record->status->value, BookingStatus::terminalValues(), true))
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        $startsAt = $data['starts_at'] instanceof \DateTimeInterface
                            ? $data['starts_at']
                            : CarbonImmutable::parse((string) $data['starts_at']);

                        app(RescheduleBooking::class)->handle(
                            actor: $actor,
                            booking: $record,
                            newStartsAt: $startsAt,
                            clientTimezone: null,
                            reason: $data['reason'] ?? null,
                            expectedEventVersion: (int) $data['expected_event_version'],
                        );
                    }),
                Action::make('complete')
                    ->label('Complete')
                    ->color('success')
                    ->schema([Textarea::make('reason')->label('Reason')->maxLength(500)])
                    ->visible(fn (Booking $record): bool => $record->status === BookingStatus::Confirmed)
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(CompleteBooking::class)->handle($actor, $record, $data['reason'] ?? null);
                    }),
                Action::make('noShow')
                    ->label('Mark no-show')
                    ->color('danger')
                    ->schema([Textarea::make('reason')->label('Reason')->maxLength(500)])
                    ->visible(fn (Booking $record): bool => in_array($record->status, [BookingStatus::Requested, BookingStatus::Confirmed], true))
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(MarkBookingNoShow::class)->handle($actor, $record, $data['reason'] ?? null);
                    }),
                Action::make('meetingUrl')
                    ->label('Set meeting URL')
                    ->schema([
                        TextInput::make('meeting_url')->label('Meeting URL')->url()->required()->maxLength(2000),
                        Textarea::make('reason')->label('Reason')->maxLength(500),
                    ])
                    ->visible(fn (Booking $record): bool => $record->visit_format === VisitFormat::Online
                        && $record->meeting_link_mode?->value === 'manual'
                        && in_array($record->status, [BookingStatus::Requested, BookingStatus::Confirmed], true))
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(SetOnlineMeetingUrl::class)->handle($actor, $record, (string) $data['meeting_url'], $data['reason'] ?? null);
                    }),
            ]);
    }
}
