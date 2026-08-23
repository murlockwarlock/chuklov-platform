<?php

namespace App\Filament\Resources\AiEvaluations\RelationManagers;

use App\Models\User;
use App\Modules\AI\Application\Actions\CreateEvalCase;
use App\Modules\AI\Application\Actions\UpdateEvalCase;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class CasesRelationManager extends RelationManager
{
    protected static string $relationship = 'cases';

    protected static ?string $title = 'Примеры проверки';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClipboardDocumentList;

    protected function canCreate(): bool
    {
        $actor = Auth::user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ManageAiPrompts,
        );
    }

    protected function canEdit(Model $record): bool
    {
        return self::canCreate();
    }

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
                Section::make('Проверки качества')
                    ->description('Опишите результат понятными словами. Каждое требование укажите с новой строки.')
                    ->schema([
                        Textarea::make('required_texts')
                            ->label('Что обязательно должно быть в ответе')
                            ->helperText('Например: «рекомендована консультация специалиста».')
                            ->rows(3)
                            ->dehydrated(false),
                        Textarea::make('forbidden_texts')
                            ->label('Чего не должно быть в ответе')
                            ->helperText('Например: конкретный диагноз без подтверждения специалиста.')
                            ->rows(3)
                            ->dehydrated(false),
                        Textarea::make('required_fields')
                            ->label('Обязательные поля структурированного ответа')
                            ->helperText('Укажите пути полей через точку, например: summary или risks.level.')
                            ->rows(3)
                            ->dehydrated(false),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Расширенные настройки')
                    ->description('Нужны только для схемы JSON, значений полей и проверок источников базы знаний.')
                    ->collapsed()
                    ->schema([
                        Textarea::make('expected_assertions')
                            ->label('Дополнительные проверки (JSON)')
                            ->helperText('Технический раздел для сложных проверок. Не используйте исполняемый код.')
                            ->formatStateUsing(static fn (mixed $state): string => is_array($state)
                                ? (json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '')
                                : (string) $state)
                            ->rows(6),
                        Textarea::make('expected_output_schema')
                            ->label('Ожидаемая структура ответа (JSON Schema)')
                            ->helperText('Ограниченная схема объекта или списка; применяется к структурированному ответу AI.')
                            ->formatStateUsing(static fn (mixed $state): string => is_array($state)
                                ? (json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '')
                                : (string) $state)
                            ->rows(6),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
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
                        $assertions = self::mergeAssertions(
                            self::assertionValues($data['expected_assertions'] ?? null),
                            $data['required_texts'] ?? null,
                            $data['forbidden_texts'] ?? null,
                            $data['required_fields'] ?? null,
                        );

                        /** @var CreateEvalCase $action */
                        $action = app(CreateEvalCase::class);

                        return $action->execute(
                            actor: $actor,
                            organization: $organization,
                            suiteId: $suite->id,
                            name: (string) $data['name'],
                            testInputs: $inputs,
                            expectedAssertions: $assertions,
                            expectedOutputSchema: self::schemaValues($data['expected_output_schema'] ?? null),
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
                        $assertions = self::mergeAssertions(
                            self::assertionValues($data['expected_assertions'] ?? null),
                            $data['required_texts'] ?? null,
                            $data['forbidden_texts'] ?? null,
                            $data['required_fields'] ?? null,
                        );

                        /** @var UpdateEvalCase $action */
                        $action = app(UpdateEvalCase::class);

                        return $action->execute(
                            actor: $actor,
                            case: $record,
                            data: [
                                'name' => (string) $data['name'],
                                'test_inputs' => $inputs,
                                'expected_assertions' => $assertions,
                                'expected_output_schema' => self::schemaValues($data['expected_output_schema'] ?? null),
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

    /** @return array<string|int, mixed> */
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
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
            throw new InvalidArgumentException('Расширенные проверки должны быть корректным JSON.');
        }

        return [['type' => 'required_text', 'value' => $value]];
    }

    /**
     * @param  array<int|string, mixed>  $advanced
     * @return array<int|string, mixed>
     */
    private static function mergeAssertions(array $advanced, mixed $required, mixed $forbidden, mixed $fields): array
    {
        $assertions = [];
        foreach (self::lines($required) as $value) {
            $assertions[] = ['type' => 'required_text', 'value' => $value];
        }
        foreach (self::lines($forbidden) as $value) {
            $assertions[] = ['type' => 'forbidden_text', 'value' => $value];
        }
        foreach (self::lines($fields) as $path) {
            $assertions[] = ['type' => 'required_field', 'path' => $path];
        }

        return [...$assertions, ...$advanced];
    }

    /** @return list<string> */
    private static function lines(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), preg_split('/\R/u', $value) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /** @return array<string, mixed>|null */
    private static function schemaValues(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new InvalidArgumentException('Ожидаемая структура ответа должна быть корректным JSON.');
        }

        return $decoded;
    }
}
