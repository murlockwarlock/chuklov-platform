<?php

namespace App\Filament\Resources\Specialists\Schemas;

use App\Filament\Support\ScheduleImpactPreview;
use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SpecialistForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('display_name')
                    ->label('Имя специалиста')
                    ->required()
                    ->maxLength(160),
                Select::make('timezone')
                    ->label('Часовой пояс специалиста')
                    ->options(fn (Get $get): array => TimezoneOptions::options(
                        current: $get('timezone'),
                        organization: app(OrganizationContext::class)->organization()->defaultTimezone(),
                    ))
                    ->searchable()
                    ->nullable()
                    ->helperText('Если не выбрать, используется часовой пояс организации.'),
                Select::make('staff_user_id')
                    ->label('Сотрудник CRM')
                    ->options(fn (): array => User::query()
                        ->whereHas('memberships', function ($query): void {
                            $query
                                ->where('organization_id', app(OrganizationContext::class)->id())
                                ->where('is_active', true);
                        })
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->nullable(),
                Toggle::make('is_active')
                    ->required()
                    ->default(true),
                ...ScheduleImpactPreview::components(),
            ]);
    }
}
