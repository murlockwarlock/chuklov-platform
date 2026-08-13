<?php

namespace App\Filament\Resources\Specialists\Schemas;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SpecialistForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('display_name')
                    ->label('Full name')
                    ->required()
                    ->maxLength(160),
                TextInput::make('timezone')
                    ->label('IANA timezone')
                    ->maxLength(64),
                Select::make('staff_user_id')
                    ->label('Linked staff User')
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
                Checkbox::make('acknowledge_impact')
                    ->label('Acknowledge impact on future bookings if shown')
                    ->default(false),
                TextInput::make('impact_digest')
                    ->label('Current impact preview digest')
                    ->maxLength(64),
            ]);
    }
}
