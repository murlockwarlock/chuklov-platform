<?php

namespace App\Filament\Resources\B2bLeads\Schemas;

use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class B2bLeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('B2B-запрос')->schema([
                Select::make('client_id')
                    ->label('Клиент')
                    ->options(fn (): array => Client::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->whereExists(fn ($query) => $query
                            ->from('broadcast_client_profiles')
                            ->whereColumn('broadcast_client_profiles.client_id', 'clients.id')
                            ->whereColumn('broadcast_client_profiles.organization_id', 'clients.organization_id')
                            ->where('b2b_specialist_answer', 'yes'))
                        ->orderBy('full_name')
                        ->limit(200)
                        ->pluck('full_name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->optionsLimit(50)
                    ->required()
                    ->helperText('Клиент должен иметь сохранённый ответ «Да» на вопрос о специалисте.'),
                Select::make('specialist_id')
                    ->label('Специалист')
                    ->options(fn (): array => Specialist::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->where('is_active', true)
                        ->orderBy('display_name')
                        ->limit(100)
                        ->pluck('display_name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->optionsLimit(50)
                    ->required(),
                DateTimePicker::make('starts_at')
                    ->label('Дата и время разговора')
                    ->timezone(fn (): string => app(OrganizationContext::class)->organization()->defaultTimezone())
                    ->seconds(false)
                    ->required(),
                Select::make('meeting_mode')
                    ->label('Режим встречи')
                    ->options([
                        VideoMeetingMode::Automatic->value => 'Zoom автоматически',
                        VideoMeetingMode::Manual->value => 'Ручная ссылка',
                    ])
                    ->default(VideoMeetingMode::Automatic->value)
                    ->live()
                    ->required(),
                TextInput::make('manual_meeting_url')
                    ->label('HTTPS-ссылка на встречу')
                    ->url()
                    ->maxLength(2000)
                    ->visible(fn (Get $get): bool => $get('meeting_mode') === VideoMeetingMode::Manual->value)
                    ->required(fn (Get $get): bool => $get('meeting_mode') === VideoMeetingMode::Manual->value)
                    ->helperText('Вставьте ссылку, по которой клиент присоединится к разговору.'),
                Hidden::make('requested_timezone')
                    ->default(fn (): string => app(OrganizationContext::class)->organization()->defaultTimezone()),
            ])->columns(2)->columnSpanFull(),
        ]);
    }
}
