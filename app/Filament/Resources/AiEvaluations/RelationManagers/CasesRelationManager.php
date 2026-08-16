<?php

namespace App\Filament\Resources\AiEvaluations\RelationManagers;

use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CasesRelationManager extends RelationManager
{
    protected static string $relationship = 'cases';

    protected static ?string $title = 'Тест-кейсы (синтетические сценарии)';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClipboardDocumentList;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название тест-кейса')
                    ->required()
                    ->maxLength(200),
                Textarea::make('test_inputs')
                    ->label('Входные параметры (JSON)')
                    ->rows(4)
                    ->default('{"query": "Синтетический тестовый запрос"}')
                    ->required(),
                Textarea::make('expected_assertions')
                    ->label('Ожидаемые проверки (JSON, например: {"contains_text": "..."})')
                    ->rows(4)
                    ->default('{"contains_text": ""}')
                    ->required(),
                Toggle::make('is_active')
                    ->label('Тест-кейс активен')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('is_active')->label('Статус')->formatStateUsing(fn ($state) => $state ? 'Активен' : 'Отключен'),
                TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y H:i'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Добавить тест-кейс')
                    ->using(function (array $data): AiEvalCase {
                        $orgId = app(OrganizationContext::class)->id();
                        /** @var AiEvalSuite $suite */
                        $suite = $this->getOwnerRecord();

                        $inputs = [];
                        if (isset($data['test_inputs']) && is_string($data['test_inputs'])) {
                            $inputs = json_decode($data['test_inputs'], true) ?: ['query' => $data['test_inputs']];
                        }

                        $assertions = [];
                        if (isset($data['expected_assertions']) && is_string($data['expected_assertions'])) {
                            $assertions = json_decode($data['expected_assertions'], true) ?: [];
                        }

                        $case = new AiEvalCase([
                            'organization_id' => $orgId,
                            'eval_suite_id' => $suite->id,
                            'name' => (string) $data['name'],
                            'test_inputs' => $inputs,
                            'expected_assertions' => $assertions,
                            'is_active' => (bool) ($data['is_active'] ?? true),
                        ]);
                        $case->save();

                        return $case;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function (AiEvalCase $record, array $data): AiEvalCase {
                        $inputs = is_string($data['test_inputs'] ?? null)
                            ? (json_decode($data['test_inputs'], true) ?: ['query' => $data['test_inputs']])
                            : (array) ($data['test_inputs'] ?? []);

                        $assertions = is_string($data['expected_assertions'] ?? null)
                            ? (json_decode($data['expected_assertions'], true) ?: [])
                            : (array) ($data['expected_assertions'] ?? []);

                        $record->update([
                            'name' => (string) $data['name'],
                            'test_inputs' => $inputs,
                            'expected_assertions' => $assertions,
                            'is_active' => (bool) ($data['is_active'] ?? true),
                        ]);

                        return $record;
                    }),
            ]);
    }
}
