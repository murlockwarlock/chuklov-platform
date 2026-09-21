<?php

namespace App\Filament\Support;

use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

abstract class LocalizedListRecords extends ListRecords
{
    public function getTitle(): string|Htmlable
    {
        $title = parent::getTitle();

        return $title instanceof Htmlable ? $title : __($title);
    }

    public function getBreadcrumb(): ?string
    {
        $breadcrumb = parent::getBreadcrumb();

        return $breadcrumb === null ? null : __($breadcrumb);
    }
}
