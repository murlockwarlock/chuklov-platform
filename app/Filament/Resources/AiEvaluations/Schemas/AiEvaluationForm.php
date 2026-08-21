<?php

namespace App\Filament\Resources\AiEvaluations\Schemas;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

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
                    ->label('Ключ набора')
                    ->helperText('Техническая идентичность набора. После создания изменить нельзя.')
                    ->required()
                    ->maxLength(80)
                    ->regex('/^[a-z0-9_\-]+$/')
                    ->disabled(fn (?AiEvalSuite $record): bool => $record !== null)
                    ->dehydrated(true),
                Select::make('capability')
                    ->label('Тестируемая возможность AI')
                    ->options(collect(AiCapability::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                    ->required(),
                Select::make('prompt_id')
                    ->label('Связанный промпт')
                    ->helperText('Можно выбрать промпт того же назначения или оставить поле пустым.')
                    ->placeholder('Без связанного промпта')
                    ->options(fn (Get $get): array => self::promptOptions($get('capability')))
                    ->getSearchResultsUsing(fn (string $search, Get $get): array => self::promptOptions($get('capability'), $search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::promptLabel($value))
                    ->optionsLimit(50)
                    ->searchable()
                    ->native(false),
                Textarea::make('description')
                    ->label('Описание набора тестов')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    /** @return array<int|string, string> */
    private static function promptOptions(mixed $capability, string $search = ''): array
    {
        $query = self::promptQuery($capability, $search)
            ->orderBy('name')
            ->limit(50);

        return $query
            ->get(['id', 'name', 'key', 'capability'])
            ->mapWithKeys(static fn (AiPrompt $prompt): array => [
                $prompt->getKey() => self::promptDisplayLabel($prompt),
            ])
            ->all();
    }

    private static function promptLabel(mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $prompt = self::promptQuery(null)
            ->whereKey((int) $value)
            ->first(['id', 'name', 'key', 'capability']);

        return $prompt instanceof AiPrompt
            ? self::promptDisplayLabel($prompt)
            : 'Сохранённый промпт недоступен';
    }

    /** @return Builder<AiPrompt> */
    private static function promptQuery(mixed $capability, string $search = ''): Builder
    {
        $query = AiPrompt::query()
            ->where('organization_id', app(OrganizationContext::class)->id());

        if (is_string($capability) && $capability !== '') {
            $query->where('capability', $capability);
        }

        $search = trim($search);
        if ($search !== '') {
            $query->where(function (Builder $nested) use ($search): void {
                $nested
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('key', 'like', '%'.$search.'%');
            });
        }

        return $query;
    }

    private static function promptDisplayLabel(AiPrompt $prompt): string
    {
        return $prompt->name.' · '.$prompt->key;
    }
}
