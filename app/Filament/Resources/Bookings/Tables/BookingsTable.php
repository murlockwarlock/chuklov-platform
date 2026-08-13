<?php

namespace App\Filament\Resources\Bookings\Tables;

use App\Models\User;
use App\Modules\Scheduling\Application\ApproveHomeVisitBooking;
use App\Modules\Scheduling\Application\RejectHomeVisitBooking;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
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
                TextColumn::make('visit_format')->label('Format'),
                TextColumn::make('status')->badge()->sortable(),
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
                    ])
                    ->visible(fn (Booking $record): bool => $record->status === BookingStatus::PendingReview
                        && $record->visit_format === VisitFormat::HomeVisit)
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(ApproveHomeVisitBooking::class)->handle($actor, $record, $data['reason'] ?? null);
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
            ]);
    }
}
