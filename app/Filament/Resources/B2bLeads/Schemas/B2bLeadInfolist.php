<?php

namespace App\Filament\Resources\B2bLeads\Schemas;

use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\CrmLabel;
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
            Section::make(__('Лид'))->schema([
                TextEntry::make('client.full_name')
                    ->label(__('Клиент'))
                    ->url(fn (B2bLead $record): ?string => CrmEntityLinks::clientUrl($record->client))
                    ->color(fn (B2bLead $record): ?string => CrmEntityLinks::clientUrl($record->client) === null ? null : 'primary'),
                TextEntry::make('client.email')->label('Email')->placeholder('—'),
                TextEntry::make('client.phone')->label(__('Телефон'))->placeholder('—'),
                TextEntry::make('b2b_specialist_answer')->label(__('Сегмент'))->formatStateUsing(static fn (): string => __('#Массажист_B2B')),
                TextEntry::make('status')->label(__('Статус лида'))->formatStateUsing(static fn ($state): string => self::leadStatus($state)),
                TextEntry::make('submitted_at')->label(__('Отправлено'))->dateTime('d.m.Y H:i')->timezone(fn (): string => app(OrganizationContext::class)->organization()->defaultTimezone()),
            ])->columns(2),
            Section::make(__('B2B-разговор'))->schema([
                TextEntry::make('salesCall.status')->label(__('Состояние разговора'))->formatStateUsing(static fn ($state): string => $state instanceof B2bSalesCallStatus ? (CrmLabel::enum($state) ?? (string) $state) : (string) $state),
                TextEntry::make('salesCall.specialist.display_name')
                    ->label(__('Специалист'))
                    ->placeholder('—')
                    ->url(fn (B2bLead $record): ?string => CrmEntityLinks::specialistUrl($record->salesCall?->specialist))
                    ->color(fn (B2bLead $record): ?string => CrmEntityLinks::specialistUrl($record->salesCall?->specialist) === null ? null : 'primary'),
                TextEntry::make('salesCall.starts_at')->label(__('Начало'))->dateTime('d.m.Y H:i')->timezone(fn (B2bLead $record): string => (string) $record->salesCall->schedule_timezone),
                TextEntry::make('salesCall.ends_at')->label(__('Окончание'))->dateTime('d.m.Y H:i')->timezone(fn (B2bLead $record): string => (string) $record->salesCall->schedule_timezone),
                TextEntry::make('salesCall.meeting_mode')->label(__('Режим'))->formatStateUsing(static fn ($state): string => $state instanceof VideoMeetingMode ? (CrmLabel::enum($state) ?? (string) $state) : (string) $state),
                TextEntry::make('salesCall.provider_sync_status')->label(__('Состояние ссылки'))->state(fn (B2bLead $record): string => self::meetingStatus($record)),
                TextEntry::make('salesCall.provider_join_url')
                    ->label(__('Ссылка клиента'))
                    ->state(fn (B2bLead $record): ?string => self::joinUrl($record))
                    ->url(fn (B2bLead $record): ?string => self::joinUrl($record))
                    ->openUrlInNewTab()
                    ->placeholder(__('Ссылка пока не готова')),
                TextEntry::make('salesCall.manual_meeting_url')->label(__('Ручная ссылка'))->url(fn (B2bLead $record): ?string => $record->salesCall->manual_meeting_url)->openUrlInNewTab()->placeholder('—'),
            ])->columns(2),
        ]);
    }

    private static function leadStatus(mixed $state): string
    {
        $status = $state instanceof B2bLeadStatus ? $state : B2bLeadStatus::tryFrom((string) $state);

        return CrmLabel::enum($status) ?? '—';
    }

    private static function meetingStatus(B2bLead $record): string
    {
        $call = $record->salesCall;
        if ($call->meeting_mode === VideoMeetingMode::Manual) {
            return $call->manual_meeting_url === null ? __('Ссылка пока не готова') : __('Используется ручная ссылка');
        }

        return match ($call->provider_sync_status) {
            VideoMeetingSyncStatus::Ready => __('Ссылка готова'),
            VideoMeetingSyncStatus::Pending => __('Создаём встречу…'),
            default => __('Ссылку нужно обновить'),
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
