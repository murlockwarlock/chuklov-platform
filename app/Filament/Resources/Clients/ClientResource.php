<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\Resources\Sessions\Pages\ManageClientSessions;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Клиенты';

    protected static ?string $modelLabel = 'клиент';

    protected static ?string $pluralModelLabel = 'клиенты';

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('full_name')->label('Имя и фамилия'),
                TextEntry::make('email')->label('Email')->placeholder('Не указан'),
                TextEntry::make('phone')->label('Телефон')->placeholder('Не указан'),
                TextEntry::make('language')
                    ->label('Язык')
                    ->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Русский' : 'Английский'),
                TextEntry::make('timezone')
                    ->label('Часовой пояс')
                    ->formatStateUsing(fn (?string $state): string => TimezoneOptions::label($state)),
                TextEntry::make('lead_source')->label('Источник обращения')->placeholder('Не указан'),
                TextEntry::make('referral_code')->label('Код рекомендации')->placeholder('Не указан'),
                TextEntry::make('channel_identities_count')->label('Способы связи'),
                TextEntry::make('channel_identity_summary')
                    ->label('Статус способов связи')
                    ->state(fn (Client $record): string => $record->channelIdentities
                        ->map(fn (ClientChannelIdentity $identity): string => self::channelLabel($identity))
                        ->implode(', '))
                    ->placeholder('Нет подключённых способов связи'),
                TextEntry::make('activeBookingRestriction.reason')
                    ->label('Ограничение самостоятельной записи')
                    ->placeholder('Ограничений нет'),
                TextEntry::make('anamnesis')
                    ->label('Анамнез')
                    ->state(fn (Client $record): ?string => ($actor = auth()->user()) instanceof User ? app(GetMedicalProfile::class)->handle($actor, $record)?->anamnesis : null)
                    ->placeholder('Не указан')
                    ->columnSpanFull(),
                TextEntry::make('complaints_goals')
                    ->label('Жалобы и цели')
                    ->state(fn (Client $record): ?string => ($actor = auth()->user()) instanceof User ? app(GetMedicalProfile::class)->handle($actor, $record)?->complaintsGoals : null)
                    ->placeholder('Не указаны')
                    ->columnSpanFull(),
                TextEntry::make('operations_injuries')
                    ->label('Операции и травмы')
                    ->state(fn (Client $record): ?string => ($actor = auth()->user()) instanceof User ? app(GetMedicalProfile::class)->handle($actor, $record)?->operationsInjuries : null)
                    ->placeholder('Не указаны')
                    ->columnSpanFull(),
                TextEntry::make('medicines')
                    ->label('Лекарственные препараты')
                    ->state(fn (Client $record): ?string => ($actor = auth()->user()) instanceof User ? app(GetMedicalProfile::class)->handle($actor, $record)?->medicines : null)
                    ->placeholder('Не указаны')
                    ->columnSpanFull(),
                TextEntry::make('supplements')
                    ->label('Биологически активные добавки')
                    ->state(fn (Client $record): ?string => ($actor = auth()->user()) instanceof User ? app(GetMedicalProfile::class)->handle($actor, $record)?->supplements : null)
                    ->placeholder('Не указаны')
                    ->columnSpanFull(),
                TextEntry::make('medical_attachments_summary')
                    ->label('Медицинские документы и файлы')
                    ->state(function (Client $record): string {
                        $actor = auth()->user();
                        if (! $actor instanceof User) {
                            return 'Требуется авторизация';
                        }

                        $attachments = MedicalAttachment::query()
                            ->where('organization_id', $record->organization_id)
                            ->where('client_id', $record->getKey())
                            ->orderByDesc('id')
                            ->get();

                        if ($attachments->isEmpty()) {
                            return 'Документов пока нет';
                        }

                        return $attachments->map(function (MedicalAttachment $att) use ($actor): string {
                            $baseInfo = $att->original_filename.' ('.$att->attachment_type->label().', '.round($att->size_bytes / 1024).' КБ) — '.$att->scan_status->label();

                            if ($att->isAvailable()) {
                                try {
                                    $url = app(GetTemporaryAttachmentUrl::class)->handle($actor, $att, 15);

                                    return $baseInfo."\n  Временная ссылка: ".$url;
                                } catch (\Throwable) {
                                    return $baseInfo;
                                }
                            }

                            return $baseInfo;
                        })->implode("\n\n");
                    })
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            ViewClient::class,
            EditClient::class,
            ManageClientSessions::class,
        ]);
    }

    private static function channelLabel(ClientChannelIdentity $identity): string
    {
        $channel = match ($identity->channel) {
            'telegram' => 'Telegram',
            'email' => 'Email',
            default => 'Другой способ связи',
        };
        $status = match ($identity->verification_status->value) {
            'verified' => 'подтверждён',
            'revoked' => 'отключён',
            default => 'не подтверждён',
        };

        return $channel.' — '.$status;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with('activeBookingRestriction')
            ->with('channelIdentities')
            ->withCount('channelIdentities');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
            'sessions' => ManageClientSessions::route('/{record}/sessions'),
        ];
    }
}
