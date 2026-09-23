<?php

namespace App\Filament\Support;

use Filament\Resources\Pages\ManageRelatedRecords;
use Illuminate\Contracts\Support\Htmlable;

abstract class LocalizedManageRelatedRecords extends ManageRelatedRecords
{
    public function getTitle(): string|Htmlable
    {
        $title = parent::getTitle();

        return $title instanceof Htmlable ? $title : __($title);
    }

    public function getBreadcrumb(): string
    {
        return __(parent::getBreadcrumb());
    }
}
