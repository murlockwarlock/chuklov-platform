<?php

namespace App\Filament\Support;

use Filament\Resources\Resource as BaseResource;

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

        return $label === null ? null : __($label);
    }

    public static function getPluralLabel(): ?string
    {
        $label = parent::getPluralLabel();

        return $label === null ? null : __($label);
    }

    public static function getBreadcrumb(): string
    {
        return __(parent::getBreadcrumb());
    }
}
