<?php

namespace App\Filament\Support;

use Filament\Pages\Dashboard;
use Illuminate\Contracts\Support\Htmlable;

abstract class LocalizedDashboard extends Dashboard
{
    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }

    public function getTitle(): string|Htmlable
    {
        $title = parent::getTitle();

        return $title instanceof Htmlable ? $title : __($title);
    }

    public function getHeading(): string|Htmlable|null
    {
        $heading = parent::getHeading();

        return $heading instanceof Htmlable || $heading === null ? $heading : __($heading);
    }

    public function getSubheading(): string|Htmlable|null
    {
        $subheading = parent::getSubheading();

        return $subheading instanceof Htmlable || $subheading === null ? $subheading : __($subheading);
    }
}
