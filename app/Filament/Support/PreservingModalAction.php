<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Drawer\Utils;

final class PreservingModalAction extends Action
{
    public function toModalHtmlable(): Htmlable
    {
        return new HtmlString(Utils::insertAttributesIntoHtmlRoot($this->renderModal()->render(), [
            'wire:partial' => "action-modals.{$this->getNestingIndex()}",
            'wire:ignore.self' => '',
        ]));
    }
}
