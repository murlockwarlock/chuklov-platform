<?php

namespace App\Filament\Resources\AiEvaluations\Schemas;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AiEvaluationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название набора тестов')
                    ->required()
                    ->maxLength(200),
                TextInput::make('key')
                    ->label('Ключ набора (уникальный)')
                    ->required()
                    ->maxLength(80)
                    ->regex('/^[a-z0-9_\-]+$/'),
                Select::make('capability')
                    ->label('Тестируемая возможность AI')
                    ->options(collect(AiCapability::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                    ->required(),
                Select::make('prompt_id')
                    ->label('Связанный промпт')
                    ->options(function () {
                        $orgId = app(OrganizationContext::class)->id();

                        return AiPrompt::query()
                            ->where('organization_id', $orgId)
                            ->pluck('name', 'id');
                    })
                    ->searchable(),
                Textarea::make('description')
                    ->label('Описание набора тестов')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
