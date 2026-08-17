<?php

namespace App\Filament\Resources\AiEvaluations\RelationManagers;

use App\Models\User;
use App\Modules\AI\Application\Actions\CreateEvalCase;
use App\Modules\AI\Application\Actions\UpdateEvalCase;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

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
                Radio::make('classification')
                    ->label('Классификация данных (обязательно)')
                    ->options([
                        'synthetic' => 'Синтетические данные (Synthetic)',
                    ])
                    ->descriptions([
                        'synthetic' => 'Полностью искусственно сгенерированные данные без связи с реальными людьми.',
                    ])
                    ->required()
                    ->helperText('В M10 разрешены только синтетические фикстуры; клинический и производственный материал запрещен.'),
                Textarea::make('test_inputs')
                    ->label('Входные параметры (JSON)')
                    ->rows(4)
                    ->required(),
                Textarea::make('expected_assertions')
                    ->label('Ожидаемые проверки (JSON, например: {"contains_text": "..."})')
                    ->rows(4)
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
                TextColumn::make('classification_label')
                    ->label('Классификация')
                    ->state(fn (AiEvalCase $record): string => $record->is_synthetic ? 'Синтетический' : ($record->is_deidentified ? 'Обезличенный' : 'Не указана'))
                    ->badge()
                    ->color(fn ($state) => $state === 'Синтетический' ? 'info' : 'warning'),
                TextColumn::make('is_active')->label('Статус')->formatStateUsing(fn ($state) => $state ? 'Активен' : 'Отключен'),
                TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y H:i'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Добавить тест-кейс')
                    ->using(function (array $data): AiEvalCase {
                        $actor = Auth::user();
                        if (! $actor instanceof User) {
                            throw new \LogicException('Authenticated user required.');
                        }

                        /** @var AiEvalSuite $suite */
                        $suite = $this->getOwnerRecord();
                        $organization = $suite->organization;

                        $inputs = [];
                        if (isset($data['test_inputs']) && is_string($data['test_inputs'])) {
                            $inputs = json_decode($data['test_inputs'], true) ?: ['query' => $data['test_inputs']];
                        } elseif (is_array($data['test_inputs'] ?? null)) {
                            $inputs = $data['test_inputs'];
                        }

                        $assertions = [];
                        if (isset($data['expected_assertions']) && is_string($data['expected_assertions'])) {
                            $assertions = json_decode($data['expected_assertions'], true) ?: [];
                        } elseif (is_array($data['expected_assertions'] ?? null)) {
                            $assertions = $data['expected_assertions'];
                        }

                        if (! array_key_exists('classification', $data)) {
                            throw new \InvalidArgumentException('Evaluation case classification is required.');
                        }

                        $classification = (string) $data['classification'];
                        $isSynthetic = $classification === 'synthetic';

                        /** @var CreateEvalCase $action */
                        $action = app(CreateEvalCase::class);

                        return $action->execute(
                            actor: $actor,
                            organization: $organization,
                            suiteId: $suite->id,
                            name: (string) $data['name'],
                            testInputs: $inputs,
                            expectedAssertions: $assertions,
                            isSynthetic: $isSynthetic,
                            isDeidentified: false,
                        );
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function (AiEvalCase $record, array $data): AiEvalCase {
                        $actor = Auth::user();
                        if (! $actor instanceof User) {
                            throw new \LogicException('Authenticated user required.');
                        }

                        $inputs = is_string($data['test_inputs'] ?? null)
                            ? (json_decode($data['test_inputs'], true) ?: ['query' => $data['test_inputs']])
                            : (array) ($data['test_inputs'] ?? []);

                        $assertions = is_string($data['expected_assertions'] ?? null)
                            ? (json_decode($data['expected_assertions'], true) ?: [])
                            : (array) ($data['expected_assertions'] ?? []);

                        if (! array_key_exists('classification', $data)) {
                            throw new \InvalidArgumentException('Evaluation case classification is required.');
                        }

                        $classification = (string) $data['classification'];
                        $isSynthetic = $classification === 'synthetic';

                        /** @var UpdateEvalCase $action */
                        $action = app(UpdateEvalCase::class);

                        return $action->execute(
                            actor: $actor,
                            case: $record,
                            data: [
                                'name' => (string) $data['name'],
                                'test_inputs' => $inputs,
                                'expected_assertions' => $assertions,
                                'is_synthetic' => $isSynthetic,
                                'is_deidentified' => false,
                                'is_active' => (bool) ($data['is_active'] ?? true),
                            ],
                        );
                    }),
            ]);
    }
}
