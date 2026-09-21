<?php

namespace App\Filament\Resources\Bookings\Tables;

use App\Filament\Resources\Bookings\Actions\BookingLifecycleActions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Support\BookingLocalDateRange;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Specialists\SpecialistResource;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\CrmLabel;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\BookingNeedsAttention;
use App\Modules\Scheduling\Application\ResolveSpecialistViewerTimezone;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingsTable
{
    public static function configure(Table $table, bool $includeAttention = true, bool $includeClient = true): Table
    {
        $canViewClients = $includeClient && ClientResource::canViewAny();
        $canViewSpecialists = SpecialistResource::canViewAny();
        $canManageScheduling = BookingResource::canCreate();
        $columns = [
            TextColumn::make('specialist.display_name')
                ->label(__('Специалист'))
                ->sortable()
                ->wrap()
                ->url(fn (Booking $record): ?string => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists))
                ->color(fn (Booking $record): ?string => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists) === null ? null : 'primary')
                ->disabledClick(fn (Booking $record): bool => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists) === null)
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('service.name')->label(__('Услуга'))->sortable()->wrap(),
            TextColumn::make('starts_at')
                ->label(fn (): string => __('Дата и время (').self::viewerTimezone().')')
                ->dateTime('d.m.Y H:i')
                ->timezone(fn (): string => self::viewerTimezone())
                ->sortable(),
            TextColumn::make('visit_format')
                ->label(__('Формат'))
                ->formatStateUsing(fn (VisitFormat|string $state): string => self::formatLabel($state)),
            TextColumn::make('location')
                ->label(__('Место'))
                ->state(fn (Booking $record): string => self::locationLabel($record))
                ->wrap()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('status')
                ->label(__('Статус'))
                ->badge()
                ->formatStateUsing(fn (BookingStatus|string $state): string => self::statusLabel($state))
                ->sortable()
                ->wrap(),
        ];

        if ($includeClient) {
            array_unshift($columns, TextColumn::make('client.full_name')
                ->label(__('Клиент'))
                ->searchable()
                ->sortable()
                ->wrap()
                ->url(fn (Booking $record): ?string => CrmEntityLinks::clientUrl($record->client, $canViewClients))
                ->color(fn (Booking $record): ?string => CrmEntityLinks::clientUrl($record->client, $canViewClients) === null ? null : 'primary')
                ->disabledClick(fn (Booking $record): bool => CrmEntityLinks::clientUrl($record->client, $canViewClients) === null));
        }

        if ($includeAttention) {
            $columns[] = TextColumn::make('needs_attention')
                ->label(__('Проверка времени'))
                ->badge()
                ->state(fn (Booking $record): string => app(BookingNeedsAttention::class)->handle($record) ? __('Требует внимания') : __('В порядке'))
                ->color(fn (string $state): string => $state === __('Требует внимания') ? 'danger' : 'success')
                ->toggleable(isToggledHiddenByDefault: true);
        }

        $filters = [
            Filter::make('period')
                ->label(__('Период'))
                ->schema([
                    DatePicker::make('from')->label(__('С')),
                    DatePicker::make('until')->label(__('По')),
                ])
                ->query(function (Builder $query, array $data): void {
                    BookingLocalDateRange::apply(
                        $query,
                        $data['from'] ?? null,
                        $data['until'] ?? null,
                        self::viewerTimezone(),
                    );
                }),
            SelectFilter::make('status')
                ->label(__('Статус'))
                ->options(self::statusOptions()),
            SelectFilter::make('specialist')
                ->label(__('Специалист'))
                ->relationship(
                    'specialist',
                    'display_name',
                    fn (Builder $query): Builder => $query->where('organization_id', self::organizationId()),
                )
                ->searchable()
                ->preload()
                ->optionsLimit(50),
            SelectFilter::make('service')
                ->label(__('Услуга'))
                ->relationship(
                    'service',
                    'name',
                    fn (Builder $query): Builder => $query->where('organization_id', self::organizationId()),
                )
                ->searchable()
                ->preload()
                ->optionsLimit(50),
            SelectFilter::make('visit_format')
                ->label(__('Формат визита'))
                ->options(self::visitFormatOptions()),
        ];

        if ($includeClient) {
            $filters[] = SelectFilter::make('client')
                ->label(__('Клиент'))
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
            ->recordActionsPosition(RecordActionsPosition::BeforeColumns)
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('created_at')
                ->orderByDesc('id'))
            ->columns($columns)
            ->filters($filters)
            ->recordActions([
                ViewAction::make()
                    ->label(__('Открыть'))
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip(__('Открыть запись'))
                    ->modalHeading(__('Просмотр записи на приём'))
                    ->modalWidth('5xl'),
                ActionGroup::make(BookingLifecycleActions::all())
                    ->label(__('Действия'))
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->button()
                    ->color('gray')
                    ->size('sm'),
            ]);
    }

    private static function formatLabel(VisitFormat|string $format): string
    {
        $format = $format instanceof VisitFormat ? $format : VisitFormat::tryFrom($format);

        return CrmLabel::enum($format) ?? __('Не указан');
    }

    private static function statusLabel(BookingStatus|string $status): string
    {
        $status = $status instanceof BookingStatus ? $status : BookingStatus::tryFrom($status);

        return CrmLabel::enum($status) ?? __('Не указан');
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

    private static function viewerTimezone(): string
    {
        $actor = auth()->user();

        return $actor instanceof User
            ? app(ResolveSpecialistViewerTimezone::class)->forUser($actor)
            : app(OrganizationContext::class)->defaultTimezone();
    }

    private static function locationLabel(Booking $booking): string
    {
        $snapshot = $booking->locationSnapshot();

        return match ($booking->visit_format) {
            VisitFormat::Office => trim(implode(' · ', array_filter([
                $snapshot['name'] ?? null,
                $snapshot['address'] ?? $booking->location,
            ]))) ?: __('Кабинет'),
            VisitFormat::HomeVisit => __('Выезд').($booking->location_area !== null ? ' · '.$booking->location_area : ''),
            VisitFormat::Online => __('Онлайн'),
        };
    }
}
