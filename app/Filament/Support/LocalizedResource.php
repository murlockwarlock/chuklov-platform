<?php

namespace App\Filament\Support;

use Filament\Resources\Resource as BaseResource;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model = Model
 *
 * @extends BaseResource<TModel>
 */
abstract class LocalizedResource extends BaseResource
{
    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }

    public static function getModelLabel(): string
    {
        return __(parent::getModelLabel());
    }

    public static function getPluralModelLabel(): string
    {
        return __(parent::getPluralModelLabel());
    }

    public static function getLabel(): ?string
    {
        $label = parent::getLabel();

        if ($label === null) {
            return null;
        }

        $translated = __($label);

        return is_string($translated) ? $translated : $label;
    }

    public static function getPluralLabel(): ?string
    {
        $label = parent::getPluralLabel();

        if ($label === null) {
            return null;
        }

        $translated = __($label);

        return is_string($translated) ? $translated : $label;
    }

    public static function getBreadcrumb(): string
    {
        return __(parent::getBreadcrumb());
    }
}
