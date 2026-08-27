<?php

namespace App\Filament\Resources\B2bLeads\Schemas;

use App\Filament\Resources\Clients\ClientResource;
use App\Modules\B2B\Domain\Enums\B2bLeadStatus;
use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class B2bLeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Лид')->schema([
                TextEntry::make('client.full_name')
                    ->label('Клиент')
                    ->url(fn (B2bLead $record): string => ClientResource::getUrl('view', ['record' => $record->client_id])),
                TextEntry::make('client.email')->label('Email')->placeholder('—'),
                TextEntry::make('client.phone')->label('Телефон')->placeholder('—'),
                TextEntry::make('b2b_specialist_answer')->label('Сегмент')->formatStateUsing(static fn (): string => '#Массажист_B2B'),
                TextEntry::make('status')->label('Статус лида')->formatStateUsing(static fn ($state): string => self::leadStatus($state)),
                TextEntry::make('submitted_at')->label('Отправлено')->dateTime('d.m.Y H:i')->timezone(fn (): string => app(OrganizationContext::class)->organization()->defaultTimezone()),
            ])->columns(2),
            Section::make('Sales call')->schema([
                TextEntry::make('salesCall.status')->label('Состояние разговора')->formatStateUsing(static fn ($state): string => $state instanceof B2bSalesCallStatus ? ($state === B2bSalesCallStatus::Scheduled ? 'Запланирован' : 'Отменён') : (string) $state),
                TextEntry::make('salesCall.specialist.display_name')->label('Специалист')->placeholder('—'),
                TextEntry::make('salesCall.starts_at')->label('Начало')->dateTime('d.m.Y H:i')->timezone(fn (B2bLead $record): string => (string) $record->salesCall->schedule_timezone),
                TextEntry::make('salesCall.ends_at')->label('Окончание')->dateTime('d.m.Y H:i')->timezone(fn (B2bLead $record): string => (string) $record->salesCall->schedule_timezone),
                TextEntry::make('salesCall.meeting_mode')->label('Режим')->formatStateUsing(static fn ($state): string => $state instanceof VideoMeetingMode && $state === VideoMeetingMode::Manual ? 'Ручная ссылка' : 'Zoom'),
                TextEntry::make('salesCall.provider_sync_status')->label('Синхронизация')->formatStateUsing(static fn ($state): string => self::providerStatus($state)),
                TextEntry::make('salesCall.provider_join_url')
                    ->label('Ссылка клиента')
                    ->state(fn (B2bLead $record): ?string => self::joinUrl($record))
                    ->url(fn (B2bLead $record): ?string => self::joinUrl($record))
                    ->openUrlInNewTab()
                    ->placeholder('Ссылка пока не готова'),
                TextEntry::make('salesCall.manual_meeting_url')->label('Ручная ссылка')->url(fn (B2bLead $record): ?string => $record->salesCall->manual_meeting_url)->openUrlInNewTab()->placeholder('—'),
            ])->columns(2),
        ]);
    }

    private static function leadStatus(mixed $state): string
    {
        $status = $state instanceof B2bLeadStatus ? $state : B2bLeadStatus::tryFrom((string) $state);

        return match ($status) {
            B2bLeadStatus::New => 'Новый',
            B2bLeadStatus::Contacted => 'Связались',
            B2bLeadStatus::ZoomScheduled => 'Разговор запланирован',
            B2bLeadStatus::Closed => 'Закрыт',
            default => '—',
        };
    }

    private static function providerStatus(mixed $state): string
    {
        $status = $state instanceof VideoMeetingSyncStatus ? $state : VideoMeetingSyncStatus::tryFrom((string) $state);

        return match ($status) {
            VideoMeetingSyncStatus::NotRequired => 'Не требуется',
            VideoMeetingSyncStatus::Pending => 'Ожидает синхронизации',
            VideoMeetingSyncStatus::Ready => 'Готово',
            VideoMeetingSyncStatus::Failed => 'Ошибка',
            VideoMeetingSyncStatus::CancellationPending => 'Отмена ожидает синхронизации',
            VideoMeetingSyncStatus::ReconciliationRequired => 'Требуется сверка',
            default => '—',
        };
    }

    private static function joinUrl(B2bLead $record): ?string
    {
        $call = $record->salesCall;
        if ($call->status !== B2bSalesCallStatus::Scheduled) {
            return null;
        }

        if ($call->provider_sync_status !== VideoMeetingSyncStatus::Ready && $call->meeting_mode !== VideoMeetingMode::Manual) {
            return null;
        }

        return $call->meeting_mode === VideoMeetingMode::Manual ? $call->manual_meeting_url : $call->provider_join_url;
    }
}
