<?php

namespace App\Filament\Resources\AiProviders\Schemas;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AiProviderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('provider_name')
                    ->label('Ключ провайдера (openai, anthropic и т.д.)')
                    ->required()
                    ->maxLength(64)
                    ->regex('/^[a-z0-9_\-]+$/'),
                TextInput::make('display_name')
                    ->label('Отображаемое название')
                    ->required()
                    ->maxLength(120),
                Select::make('credential_id')
                    ->label('Учетные данные (API Key из Security)')
                    ->options(function () {
                        $orgId = app(OrganizationContext::class)->id();

                        return OrganizationCredential::query()
                            ->where('organization_id', $orgId)
                            ->pluck('credential_name', 'id');
                    })
                    ->searchable(),
                Toggle::make('is_enabled')
                    ->label('Провайдер включен')
                    ->default(true),
            ]);
    }
}
