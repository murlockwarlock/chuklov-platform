<?php

namespace App\Filament\Resources\Bookings\Actions;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Application\ApproveHomeVisitBooking;
use App\Modules\Scheduling\Application\CancelBooking;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Application\ConfirmBooking;
use App\Modules\Scheduling\Application\MarkBookingNoShow;
use App\Modules\Scheduling\Application\RejectHomeVisitBooking;
use App\Modules\Scheduling\Application\RescheduleBooking;
use App\Modules\Scheduling\Application\ResolveSpecialistViewerTimezone;
use App\Modules\Scheduling\Application\SetOnlineMeetingUrl;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\PaymentRequirementType;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;

final class BookingLifecycleActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        $actor = auth()->user();
        $canManageScheduling = $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ManageScheduling,
        );

        return [
            Action::make('confirm')
                ->label('Подтвердить запись')
                ->color('success')
                ->icon('heroicon-o-check')
                ->schema([Textarea::make('reason')->label('Комментарий')->maxLength(500)])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && $record->status === BookingStatus::Requested
                    && in_array($record->visit_format, [VisitFormat::Office, VisitFormat::Online], true))
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(ConfirmBooking::class)->handle($actor, $record, $data['reason'] ?? null);
                        $record->refresh();
                        Notification::make()->success()->title('Запись подтверждена')->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    }
                }),

            Action::make('approveHomeVisit')
                ->label('Подтвердить выезд')
                ->color('success')
                ->icon('heroicon-o-truck')
                ->schema([
                    Textarea::make('reason')
                        ->label('Комментарий')
                        ->maxLength(500),
                    Select::make('payment_requirement')
                        ->label('Условие оплаты')
                        ->options([
                            PaymentRequirementType::FullPayment->value => 'Полная оплата',
                            PaymentRequirementType::TransportDeposit->value => 'Депозит за выезд',
                        ])
                        ->nullable(),
                ])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && $record->status === BookingStatus::PendingReview
                    && $record->visit_format === VisitFormat::HomeVisit)
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(ApproveHomeVisitBooking::class)->handle(
                            $actor,
                            $record,
                            $data['reason'] ?? null,
                            $data['payment_requirement'] ?? null,
                        );
                        $record->refresh();
                        Notification::make()->success()->title('Выезд подтверждён')->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    }
                }),

            Action::make('rejectHomeVisit')
                ->label('Отклонить заявку')
                ->color('danger')
                ->icon('heroicon-o-x-mark')
                ->schema([
                    Textarea::make('reason')
                        ->label('Причина отказа')
                        ->required()
                        ->maxLength(500),
                ])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && $record->status === BookingStatus::PendingReview
                    && $record->visit_format === VisitFormat::HomeVisit)
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(RejectHomeVisitBooking::class)->handle($actor, $record, (string) $data['reason']);
                        $record->refresh();
                        Notification::make()->success()->title('Заявка на выезд отклонена')->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    }
                }),

            Action::make('reschedule')
                ->label('Перенести')
                ->icon('heroicon-o-calendar')
                ->schema([
                    DateTimePicker::make('starts_at')
                        ->label('Новая дата и время')
                        ->timezone(fn (): string => self::viewerTimezone())
                        ->seconds(false)
                        ->required(),
                    Select::make('working_location_id')
                        ->label('Локация')
                        ->options(fn (): array => WorkingLocation::query()
                            ->where('organization_id', app(OrganizationContext::class)->id())
                            ->where('is_active', true)
                            ->orderByDesc('is_default_office')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (WorkingLocation $location): array => [
                                $location->getKey() => $location->name.' — '.$location->address,
                            ])
                            ->all())
                        ->default(fn (Booking $record): ?int => $record->working_location_id)
                        ->searchable()
                        ->nullable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            $location = $state === null || $state === ''
                                ? null
                                : WorkingLocation::query()
                                    ->where('organization_id', app(OrganizationContext::class)->id())
                                    ->whereKey((int) $state)
                                    ->first();
                            $set('location', $location?->address);
                        })
                        ->visible(fn (Booking $record): bool => $record->visit_format === VisitFormat::Office),
                    TextInput::make('location_area')
                        ->label('Район выезда')
                        ->default(fn (Booking $record): ?string => $record->location_area)
                        ->maxLength(160)
                        ->visible(fn (Booking $record): bool => $record->visit_format === VisitFormat::HomeVisit),
                    TextInput::make('location')
                        ->label(fn (Booking $record): string => $record->visit_format === VisitFormat::Office ? 'Адрес приёма' : 'Адрес выезда')
                        ->default(fn (Booking $record): ?string => $record->location)
                        ->visible(fn (Booking $record): bool => in_array($record->visit_format, [VisitFormat::Office, VisitFormat::HomeVisit], true))
                        ->maxLength(500),
                    Hidden::make('expected_event_version')
                        ->default(fn (Booking $record): int => $record->event_version)
                        ->required(),
                    Textarea::make('reason')->label('Причина')->maxLength(500),
                ])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && ! in_array($record->status->value, BookingStatus::terminalValues(), true))
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    $startsAt = $data['starts_at'] instanceof DateTimeInterface
                        ? $data['starts_at']
                        : CarbonImmutable::parse(
                            (string) $data['starts_at'],
                            self::viewerTimezone(),
                        );

                    try {
                        app(RescheduleBooking::class)->handle(
                            actor: $actor,
                            booking: $record,
                            newStartsAt: $startsAt,
                            clientTimezone: null,
                            reason: $data['reason'] ?? null,
                            expectedEventVersion: (int) $data['expected_event_version'],
                            location: array_key_exists('location', $data) ? (string) $data['location'] : null,
                            workingLocationId: isset($data['working_location_id']) && $data['working_location_id'] !== ''
                                ? (int) $data['working_location_id']
                                : null,
                            locationArea: isset($data['location_area']) ? (string) $data['location_area'] : null,
                        );
                        $record->refresh();
                        Notification::make()->success()->title('Запись успешно перенесена')->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    }
                }),

            Action::make('complete')
                ->label('Завершить визит')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->schema([Textarea::make('reason')->label('Комментарий')->maxLength(500)])
                ->visible(fn (Booking $record): bool => $canManageScheduling && $record->status === BookingStatus::Confirmed)
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(CompleteBooking::class)->handle($actor, $record, $data['reason'] ?? null);
                        $record->refresh();
                        Notification::make()->success()->title('Визит успешно завершён')->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    }
                }),

            Action::make('noShow')
                ->label('Отметить неявку')
                ->color('danger')
                ->icon('heroicon-o-user-minus')
                ->schema([Textarea::make('reason')->label('Комментарий')->maxLength(500)])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && in_array($record->status, [BookingStatus::Requested, BookingStatus::Confirmed], true))
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(MarkBookingNoShow::class)->handle($actor, $record, $data['reason'] ?? null);
                        $record->refresh();
                        Notification::make()->success()->title('Запись отмечена как не состоявшаяся')->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    }
                }),

            Action::make('meetingUrl')
                ->label('Ссылка на встречу')
                ->icon('heroicon-o-video-camera')
                ->schema([
                    TextInput::make('meeting_url')->label('Ссылка на встречу')->url()->required()->maxLength(2000),
                    Textarea::make('reason')->label('Комментарий')->maxLength(500),
                ])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && $record->visit_format === VisitFormat::Online
                    && $record->meeting_link_mode?->value === 'manual'
                    && in_array($record->status, [BookingStatus::Requested, BookingStatus::Confirmed], true))
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(SetOnlineMeetingUrl::class)->handle($actor, $record, (string) $data['meeting_url'], $data['reason'] ?? null);
                        $record->refresh();
                        Notification::make()->success()->title('Ссылка на встречу обновлена')->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    }
                }),

            Action::make('cancel')
                ->label('Отменить')
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->schema([Textarea::make('reason')->label('Причина')->maxLength(500)])
                ->visible(fn (Booking $record): bool => $canManageScheduling
                    && ! in_array($record->status->value, BookingStatus::terminalValues(), true))
                ->action(function (Booking $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(CancelBooking::class)->handle($actor, $record, $data['reason'] ?? null);
                        $record->refresh();
                        Notification::make()->success()->title('Запись отменена')->send();
                    } catch (ValidationException $exception) {
                        self::sendErrorNotification($exception);
                    }
                }),
        ];
    }

    private static function sendErrorNotification(ValidationException $exception): void
    {
        $message = collect($exception->errors())->flatten()->first() ?: 'Не удалось выполнить действие.';

        Notification::make()
            ->danger()
            ->title('Действие отклонено')
            ->body($message)
            ->send();
    }

    private static function viewerTimezone(): string
    {
        $actor = auth()->user();

        return $actor instanceof User
            ? app(ResolveSpecialistViewerTimezone::class)->forUser($actor)
            : app(OrganizationContext::class)->defaultTimezone();
    }
}
