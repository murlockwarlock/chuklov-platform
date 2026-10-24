<?php

namespace App\Filament\Support;

use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

abstract class LocalizedListRecords extends ListRecords
{
    public function getTitle(): string|Htmlable
    {
        $title = parent::getTitle();

        if ($title instanceof Htmlable) {
            return $title;
        }

        $translated = __($title);

        return is_string($translated) ? $translated : $title;
    }

    public function getBreadcrumb(): ?string
    {
        $breadcrumb = parent::getBreadcrumb();

        if ($breadcrumb === null) {
            return null;
        }

        $translated = __($breadcrumb);

        return is_string($translated) ? $translated : $breadcrumb;
    }
}
