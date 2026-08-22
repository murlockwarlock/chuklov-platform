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
use Filament\Forms\Components\Hidden;
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

    protected static ?string $title = 'Примеры проверки';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClipboardDocumentList;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название примера')
                    ->required()
                    ->maxLength(200),
                Hidden::make('is_synthetic')->default(true),
                Hidden::make('is_deidentified')->default(false),
                Textarea::make('test_inputs')
                    ->label('Пример запроса к AI')
                    ->helperText('Можно написать обычным текстом. Для сложного сценария допустим JSON.')
                    ->rows(4)
                    ->required(),
                Textarea::make('expected_assertions')
                    ->label('Что должен содержать ответ')
                    ->helperText('Напишите ожидаемый результат обычным текстом или задайте расширенную проверку в JSON.')
                    ->rows(4)
                    ->required(),
                Toggle::make('is_active')
                    ->label('Использовать в проверке')
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
            ->emptyStateHeading('Примеров пока нет')
            ->emptyStateDescription('Добавьте примеры, чтобы проверить, что новая настройка AI работает ожидаемо.')
            ->headerActions([
                CreateAction::make()
                    ->label('Добавить пример')
                    ->using(function (array $data): AiEvalCase {
                        $actor = Auth::user();
                        if (! $actor instanceof User) {
                            throw new \LogicException('Authenticated user required.');
                        }

                        /** @var AiEvalSuite $suite */
                        $suite = $this->getOwnerRecord();
                        $organization = $suite->organization;

                        $inputs = self::inputValues($data['test_inputs'] ?? null);
                        $assertions = self::assertionValues($data['expected_assertions'] ?? null);

                        /** @var CreateEvalCase $action */
                        $action = app(CreateEvalCase::class);

                        return $action->execute(
                            actor: $actor,
                            organization: $organization,
                            suiteId: $suite->id,
                            name: (string) $data['name'],
                            testInputs: $inputs,
                            expectedAssertions: $assertions,
                            isSynthetic: (bool) ($data['is_synthetic'] ?? true),
                            isDeidentified: (bool) ($data['is_deidentified'] ?? false),
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

                        $inputs = self::inputValues($data['test_inputs'] ?? null);
                        $assertions = self::assertionValues($data['expected_assertions'] ?? null);

                        /** @var UpdateEvalCase $action */
                        $action = app(UpdateEvalCase::class);

                        return $action->execute(
                            actor: $actor,
                            case: $record,
                            data: [
                                'name' => (string) $data['name'],
                                'test_inputs' => $inputs,
                                'expected_assertions' => $assertions,
                                'is_synthetic' => (bool) ($data['is_synthetic'] ?? $record->is_synthetic),
                                'is_deidentified' => (bool) ($data['is_deidentified'] ?? $record->is_deidentified),
                                'is_active' => (bool) ($data['is_active'] ?? true),
                            ],
                        );
                    }),
            ]);
    }

    /** @return array<string, mixed> */
    private static function inputValues(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : ['query' => $value];
    }

    /** @return array<string, mixed> */
    private static function assertionValues(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : ['contains_text' => $value];
    }
}
