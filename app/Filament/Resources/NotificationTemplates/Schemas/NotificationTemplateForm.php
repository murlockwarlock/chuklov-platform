<?php

namespace App\Filament\Resources\NotificationTemplates\Schemas;

use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class NotificationTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('template_key')
                    ->label('Template key')
                    ->required()
                    ->maxLength(120)
                    ->disabled(fn (string $operation): bool => $operation === 'edit'),
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(160),
                Select::make('locale')
                    ->options([
                        'en' => 'English',
                        'ru' => 'Russian',
                    ])
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit'),
                Select::make('purpose')
                    ->label('Communication purpose')
                    ->options([
                        ScenarioRulePurpose::Service->value => 'Service',
                        ScenarioRulePurpose::Transactional->value => 'Transactional',
                    ])
                    ->required(),
                Toggle::make('is_active')
                    ->label('Active')
                    ->required()
                    ->default(true),
                TextInput::make('subject')
                    ->maxLength(255)
                    ->helperText('Optional subject for channels that support one.'),
                Textarea::make('body')
                    ->label('Body')
                    ->required()
                    ->rows(12)
                    ->maxLength(100000)
                    ->helperText('Only allowlisted variables are rendered. Use the exact names below.'),
                TagsInput::make('variables')
                    ->label('Declared variables')
                    ->suggestions(ScenarioTemplateVariableCatalog::allowed())
                    ->required()
                    ->helperText('A template must declare every variable it uses.'),
            ]);
    }
}
