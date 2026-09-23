<?php

namespace App\Filament\Resources\B2bLeads\Tables;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Specialists\SpecialistResource;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\CrmLabel;
use App\Modules\B2B\Domain\Enums\B2bLeadStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class B2bLeadsTable
{
    public static function configure(Table $table): Table
    {
        $canViewClients = ClientResource::canViewAny();
        $canViewSpecialists = SpecialistResource::canViewAny();

        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('client.full_name')
                    ->label(__('Клиент'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->url(fn (B2bLead $record): ?string => CrmEntityLinks::clientUrl($record->client, $canViewClients))
                    ->color(fn (B2bLead $record): ?string => CrmEntityLinks::clientUrl($record->client, $canViewClients) === null ? null : 'primary')
                    ->disabledClick(fn (B2bLead $record): bool => CrmEntityLinks::clientUrl($record->client, $canViewClients) === null),
                TextColumn::make('b2b_specialist_answer')->label(__('Сегмент'))->formatStateUsing(static fn (): string => __('#Массажист_B2B'))->badge(),
                TextColumn::make('status')->label(__('Статус'))->formatStateUsing(static fn ($state): string => self::status($state))->badge()->sortable(),
                TextColumn::make('salesCall.specialist.display_name')
                    ->label(__('Специалист'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->url(fn (B2bLead $record): ?string => CrmEntityLinks::specialistUrl($record->salesCall?->specialist, $canViewSpecialists))
                    ->color(fn (B2bLead $record): ?string => CrmEntityLinks::specialistUrl($record->salesCall?->specialist, $canViewSpecialists) === null ? null : 'primary')
                    ->disabledClick(fn (B2bLead $record): bool => CrmEntityLinks::specialistUrl($record->salesCall?->specialist, $canViewSpecialists) === null),
                TextColumn::make('salesCall.starts_at')->label(__('Разговор'))->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('salesCall.provider_sync_status')->label('Zoom')->formatStateUsing(static fn ($state): string => self::provider($state))->badge(),
                TextColumn::make('submitted_at')->label(__('Отправлено'))->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Статус'))->options([
                    B2bLeadStatus::New->value => CrmLabel::enum(B2bLeadStatus::New),
                    B2bLeadStatus::Contacted->value => CrmLabel::enum(B2bLeadStatus::Contacted),
                    B2bLeadStatus::ZoomScheduled->value => CrmLabel::enum(B2bLeadStatus::ZoomScheduled),
                    B2bLeadStatus::Closed->value => CrmLabel::enum(B2bLeadStatus::Closed),
                ]),
                SelectFilter::make('provider_sync_status')
                    ->label(__('Синхронизация Zoom'))
                    ->options([
                        VideoMeetingSyncStatus::Pending->value => CrmLabel::enum(VideoMeetingSyncStatus::Pending),
                        VideoMeetingSyncStatus::Ready->value => CrmLabel::enum(VideoMeetingSyncStatus::Ready),
                        VideoMeetingSyncStatus::Failed->value => CrmLabel::enum(VideoMeetingSyncStatus::Failed),
                        VideoMeetingSyncStatus::ReconciliationRequired->value => CrmLabel::enum(VideoMeetingSyncStatus::ReconciliationRequired),
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn ($query, string $value) => $query->whereHas(
                            'salesCall',
                            fn ($callQuery) => $callQuery->where('provider_sync_status', $value),
                        ),
                    )),
                SelectFilter::make('specialist_id')
                    ->label(__('Специалист'))
                    ->options(fn (): array => Specialist::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->orderBy('display_name')
                        ->limit(100)
                        ->pluck('display_name', 'id')
                        ->all())
                    ->query(fn ($query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn ($query, string $value) => $query->whereHas(
                            'salesCall',
                            fn ($callQuery) => $callQuery->where('specialist_id', (int) $value),
                        ),
                    )),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->recordActions([ViewAction::make()->label(__('Открыть'))])
            ->paginated([10, 25, 50]);
    }

    private static function status(mixed $state): string
    {
        $status = $state instanceof B2bLeadStatus ? $state : B2bLeadStatus::tryFrom((string) $state);

        return CrmLabel::enum($status) ?? '—';
    }

    private static function provider(mixed $state): string
    {
        $status = $state instanceof VideoMeetingSyncStatus ? $state : VideoMeetingSyncStatus::tryFrom((string) $state);

        return CrmLabel::enum($status) ?? '—';
    }
}
