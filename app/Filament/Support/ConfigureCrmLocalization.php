<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Filters\BaseFilter;

final class ConfigureCrmLocalization
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        Field::configureUsing(static fn (Field $component): Field => $component->translateLabel());
        Column::configureUsing(static fn (Column $component): Column => $component->translateLabel());
        ColumnGroup::configureUsing(static fn (ColumnGroup $component): ColumnGroup => $component->translateLabel());
        BaseFilter::configureUsing(static fn (BaseFilter $component): BaseFilter => $component->translateLabel());
        Action::configureUsing(static fn (Action $component): Action => $component->translateLabel());
        ActionGroup::configureUsing(static fn (ActionGroup $component): ActionGroup => $component->translateLabel());
        Entry::configureUsing(static fn (Entry $component): Entry => $component->translateLabel());
    }
}
