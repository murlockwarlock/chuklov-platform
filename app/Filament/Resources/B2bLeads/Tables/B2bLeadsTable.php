<?php

namespace App\Filament\Resources\B2bLeads\Tables;

use App\Modules\B2B\Domain\Enums\B2bLeadStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
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
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('client.full_name')->label('Клиент')->searchable()->sortable()->wrap(),
                TextColumn::make('b2b_specialist_answer')->label('Сегмент')->formatStateUsing(static fn (): string => '#Массажист_B2B')->badge(),
                TextColumn::make('status')->label('Статус')->formatStateUsing(static fn ($state): string => self::status($state))->badge()->sortable(),
                TextColumn::make('salesCall.specialist.display_name')->label('Специалист')->searchable()->sortable()->wrap(),
                TextColumn::make('salesCall.starts_at')->label('Разговор')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('salesCall.provider_sync_status')->label('Zoom')->formatStateUsing(static fn ($state): string => self::provider($state))->badge(),
                TextColumn::make('submitted_at')->label('Отправлено')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options([
                    B2bLeadStatus::New->value => 'Новый',
                    B2bLeadStatus::Contacted->value => 'Связались',
                    B2bLeadStatus::ZoomScheduled->value => 'Разговор запланирован',
                    B2bLeadStatus::Closed->value => 'Закрыт',
                ]),
                SelectFilter::make('provider_sync_status')
                    ->label('Синхронизация Zoom')
                    ->options([
                        VideoMeetingSyncStatus::Pending->value => 'Ожидает',
                        VideoMeetingSyncStatus::Ready->value => 'Готово',
                        VideoMeetingSyncStatus::Failed->value => 'Ошибка',
                        VideoMeetingSyncStatus::ReconciliationRequired->value => 'Требуется сверка',
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn ($query, string $value) => $query->whereHas(
                            'salesCall',
                            fn ($callQuery) => $callQuery->where('provider_sync_status', $value),
                        ),
                    )),
                SelectFilter::make('specialist_id')
                    ->label('Специалист')
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
            ->recordActions([ViewAction::make()->label('Открыть')])
            ->paginated([10, 25, 50]);
    }

    private static function status(mixed $state): string
    {
        $status = $state instanceof B2bLeadStatus ? $state : B2bLeadStatus::tryFrom((string) $state);

        return match ($status) {
            B2bLeadStatus::New => 'Новый',
            B2bLeadStatus::Contacted => 'Связались',
            B2bLeadStatus::ZoomScheduled => 'Запланирован',
            B2bLeadStatus::Closed => 'Закрыт',
            default => '—',
        };
    }

    private static function provider(mixed $state): string
    {
        $status = $state instanceof VideoMeetingSyncStatus ? $state : VideoMeetingSyncStatus::tryFrom((string) $state);

        return match ($status) {
            VideoMeetingSyncStatus::NotRequired => 'Не требуется',
            VideoMeetingSyncStatus::Pending => 'Ожидает',
            VideoMeetingSyncStatus::Ready => 'Готово',
            VideoMeetingSyncStatus::Failed => 'Ошибка',
            VideoMeetingSyncStatus::CancellationPending => 'Отмена',
            VideoMeetingSyncStatus::ReconciliationRequired => 'Сверка',
            default => '—',
        };
    }
}
