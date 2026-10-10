<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

abstract class LocalizedCreateRecord extends CreateRecord
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

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Сохранить'));
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()->label(__('Сохранить и создать ещё'));
    }
}
