<?php

namespace App\Filament\Support;

use Filament\Resources\Pages\ManageRelatedRecords;
use Illuminate\Contracts\Support\Htmlable;

abstract class LocalizedManageRelatedRecords extends ManageRelatedRecords
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

    public function getBreadcrumb(): string
    {
        $breadcrumb = parent::getBreadcrumb();
        $translated = __($breadcrumb);

        return is_string($translated) ? $translated : $breadcrumb;
    }
}
