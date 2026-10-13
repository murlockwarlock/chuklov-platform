<?php

namespace App\Filament\Support;

use Filament\Pages\Dashboard;
use Illuminate\Contracts\Support\Htmlable;

abstract class LocalizedDashboard extends Dashboard
{
    public static function getNavigationLabel(): string
    {
        $label = parent::getNavigationLabel();
        $translated = __($label);

        return is_string($translated) ? $translated : $label;
    }

    public function getTitle(): string|Htmlable
    {
        $title = parent::getTitle();

        if ($title instanceof Htmlable) {
            return $title;
        }

        $translated = __($title);

        return is_string($translated) ? $translated : $title;
    }

    public function getHeading(): string|Htmlable|null
    {
        $heading = parent::getHeading();

        if ($heading === null || $heading instanceof Htmlable) {
            return $heading;
        }

        $translated = __($heading);

        return is_string($translated) ? $translated : $heading;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $subheading = parent::getSubheading();

        if ($subheading === null || $subheading instanceof Htmlable) {
            return $subheading;
        }

        $translated = __($subheading);

        return is_string($translated) ? $translated : $subheading;
    }
}
