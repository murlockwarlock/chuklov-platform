<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Schemas;

use App\Models\User;
use App\Modules\MedicalProfiles\Application\MedicalComplaintCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;

final class MedicalComplaintSelect
{
    public static function make(): Select
    {
        return Select::make('complaint_definition_id')
            ->label(__('Жалоба из справочника'))
            ->placeholder(__('Выберите жалобу или введите свою ниже'))
            ->options(fn (): array => self::catalog()->options(app(OrganizationContext::class)->id()))
            ->getSearchResultsUsing(fn (string $search): array => self::catalog()->search(app(OrganizationContext::class)->id(), $search))
            ->getOptionLabelUsing(fn (mixed $value): ?string => self::optionLabel($value))
            ->searchable()
            ->live()
            ->afterStateUpdated(function (Set $set, mixed $state): void {
                $name = self::optionName($state);
                if ($name !== null) {
                    $set('name', $name);
                }
            })
            ->createOptionForm([
                TextInput::make('name')->label(__('Название жалобы'))->required()->maxLength(255),
                TextInput::make('group_name')->label(__('Группа'))->placeholder(__('Боль, дискомфорт или состояние'))->maxLength(120),
            ])
            ->createOptionUsing(function (array $data): int {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                return self::catalog()->createForSession(
                    $actor,
                    (string) ($data['name'] ?? ''),
                    isset($data['group_name']) ? (string) $data['group_name'] : null,
                );
            })
            ->columnSpanFull();
    }

    private static function catalog(): MedicalComplaintCatalog
    {
        return app(MedicalComplaintCatalog::class);
    }

    private static function optionLabel(mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        return self::catalog()->options(app(OrganizationContext::class)->id(), [(int) $value])[(int) $value] ?? null;
    }

    private static function optionName(mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        return self::catalog()->name(app(OrganizationContext::class)->id(), (int) $value);
    }
}
