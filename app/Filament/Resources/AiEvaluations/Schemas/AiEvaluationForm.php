<?php

namespace App\Filament\Resources\AiEvaluations\Schemas;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AiEvaluationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основная информация')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название проверки')
                            ->required()
                            ->maxLength(200),
                        Select::make('capability')
                            ->label('Что проверяем')
                            ->options(collect(AiCapability::cases())->mapWithKeys(fn (AiCapability $capability): array => [$capability->value => $capability->label()]))
                            ->live()
                            ->required(),
                        Select::make('prompt_id')
                            ->label('Связанный промпт')
                            ->placeholder('Без привязанного промпта')
                            ->options(fn (Get $get): array => self::promptOptions($get('capability')))
                            ->getSearchResultsUsing(fn (string $search, Get $get): array => self::promptOptions($get('capability'), $search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => self::promptLabel($value))
                            ->optionsLimit(50)
                            ->searchable()
                            ->native(false),
                        Textarea::make('description')
                            ->label('Описание')
                            ->helperText('Опишите, какой результат должна подтвердить эта проверка.')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Технические детали')
                    ->description('Техническое имя создаётся автоматически из названия и остаётся неизменным. Оно нужно только для надёжного хранения и интеграций.')
                    ->collapsed()
                    ->schema([
                        TextInput::make('key')
                            ->label('Техническое имя')
                            ->helperText('Оставьте пустым, если не нужно сохранить уже существующее имя.')
                            ->maxLength(80)
                            ->regex('/^[a-z0-9_\-]+$/')
                            ->disabled(fn (?AiEvalSuite $record): bool => $record !== null)
                            ->dehydrated(true),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /** @return array<int|string, string> */
    private static function promptOptions(mixed $capability, string $search = ''): array
    {
        if (! is_string($capability) || AiCapability::tryFrom($capability) === null) {
            return [];
        }

        $query = AiPrompt::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('capability', $capability)
            ->when(trim($search) !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested
                        ->where('name', 'like', '%'.trim($search).'%')
                        ->orWhere('key', 'like', '%'.trim($search).'%');
                });
            })
            ->orderBy('name')
            ->limit(50);

        return $query
            ->get(['id', 'organization_id', 'name', 'key'])
            ->mapWithKeys(static fn (AiPrompt $prompt): array => [$prompt->getKey() => $prompt->name.' · '.$prompt->key])
            ->all();
    }

    private static function promptLabel(mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $prompt = AiPrompt::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey((int) $value)
            ->first(['id', 'organization_id', 'name', 'key']);

        return $prompt instanceof AiPrompt ? $prompt->name.' · '.$prompt->key : 'Сохранённый промпт недоступен';
    }
}
