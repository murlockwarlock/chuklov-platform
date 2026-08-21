<?php

namespace App\Filament\Resources\Bookings\Tables;

use App\Filament\Resources\Bookings\Actions\BookingLifecycleActions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Support\BookingLocalDateRange;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\BookingNeedsAttention;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingsTable
{
    public static function configure(Table $table, bool $includeAttention = true, bool $includeClient = true): Table
    {
        $canManageScheduling = BookingResource::canCreate();
        $columns = [
            TextColumn::make('specialist.display_name')->label('Специалист')->sortable()->wrap(),
            TextColumn::make('service.name')->label('Услуга')->sortable()->wrap(),
            TextColumn::make('starts_at')->label('Дата и время')->dateTime('d.m.Y H:i')->sortable(),
            TextColumn::make('visit_format')
                ->label('Формат')
                ->formatStateUsing(fn (VisitFormat|string $state): string => self::formatLabel($state)),
            TextColumn::make('status')
                ->label('Статус')
                ->badge()
                ->formatStateUsing(fn (BookingStatus|string $state): string => self::statusLabel($state))
                ->sortable()
                ->wrap(),
        ];

        if ($includeClient) {
            array_unshift($columns, TextColumn::make('client.full_name')->label('Клиент')->searchable()->sortable()->wrap());
        }

        if ($includeAttention) {
            $columns[] = TextColumn::make('needs_attention')
                ->label('Проверка времени')
                ->badge()
                ->state(fn (Booking $record): string => app(BookingNeedsAttention::class)->handle($record) ? 'Требует внимания' : 'В порядке')
                ->color(fn (string $state): string => $state === 'Требует внимания' ? 'danger' : 'success');
        }

        $filters = [
            Filter::make('period')
                ->label('Период')
                ->schema([
                    DatePicker::make('from')->label('С'),
                    DatePicker::make('until')->label('По'),
                ])
                ->query(function (Builder $query, array $data): void {
                    BookingLocalDateRange::apply(
                        $query,
                        $data['from'] ?? null,
                        $data['until'] ?? null,
                        self::organizationTimezone(),
                    );
                }),
            SelectFilter::make('status')
                ->label('Статус')
                ->options(self::statusOptions()),
            SelectFilter::make('specialist')
                ->label('Специалист')
                ->relationship(
                    'specialist',
                    'display_name',
                    fn (Builder $query): Builder => $query->where('organization_id', self::organizationId()),
                )
                ->searchable()
                ->preload()
                ->optionsLimit(50),
            SelectFilter::make('service')
                ->label('Услуга')
                ->relationship(
                    'service',
                    'name',
                    fn (Builder $query): Builder => $query->where('organization_id', self::organizationId()),
                )
                ->searchable()
                ->preload()
                ->optionsLimit(50),
            SelectFilter::make('visit_format')
                ->label('Формат визита')
                ->options(self::visitFormatOptions()),
        ];

        if ($includeClient) {
            $filters[] = SelectFilter::make('client')
                ->label('Клиент')
                ->relationship(
                    'client',
                    'full_name',
                    fn (Builder $query): Builder => $query->where('organization_id', self::organizationId()),
                )
                ->getOptionLabelFromRecordUsing(
                    static fn (Client $record): string => is_string($record->full_name) && filled($record->full_name)
                        ? $record->full_name
                        : '#'.$record->getKey(),
                )
                ->searchable()
                ->preload()
                ->optionsLimit(50);
        }

        return $table
            ->stackedOnMobile()
            ->columns($columns)
            ->filters($filters)
            ->recordActions([
                ViewAction::make()->label('Открыть'),
                ActionGroup::make(BookingLifecycleActions::all())
                    ->label('Действия')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->button()
                    ->color('gray')
                    ->size('sm'),
            ]);
    }

    private static function formatLabel(VisitFormat|string $format): string
    {
        $format = $format instanceof VisitFormat ? $format : VisitFormat::tryFrom($format);

        return match ($format) {
            VisitFormat::Office => 'В клинике',
            VisitFormat::HomeVisit => 'Выезд на дом',
            VisitFormat::Online => 'Онлайн',
            default => 'Не указан',
        };
    }

    private static function statusLabel(BookingStatus|string $status): string
    {
        $status = $status instanceof BookingStatus ? $status : BookingStatus::tryFrom($status);

        return match ($status) {
            BookingStatus::Requested => 'Ожидает подтверждения',
            BookingStatus::PendingReview => 'На рассмотрении',
            BookingStatus::Confirmed => 'Подтверждена',
            BookingStatus::Rejected => 'Отклонена',
            BookingStatus::Cancelled => 'Отменена',
            BookingStatus::Completed => 'Завершена',
            BookingStatus::NoShow => 'Не состоялась',
            default => 'Не указан',
        };
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (BookingStatus::cases() as $status) {
            $options[$status->value] = self::statusLabel($status);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function visitFormatOptions(): array
    {
        $options = [];

        foreach (VisitFormat::cases() as $format) {
            $options[$format->value] = self::formatLabel($format);
        }

        return $options;
    }

    private static function organizationId(): int
    {
        return app(OrganizationContext::class)->id();
    }

    private static function organizationTimezone(): string
    {
        return app(OrganizationContext::class)->defaultTimezone();
    }
}
