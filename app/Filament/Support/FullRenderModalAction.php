<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Drawer\Utils;

final class FullRenderModalAction extends Action
{
    public function toModalHtmlable(): Htmlable
    {
        $modal = str_replace(
            'x-show="isWindowVisible"',
            'x-show="isOpen || isWindowVisible"',
            $this->renderModal()->render(),
        );

        return new HtmlString(Utils::insertAttributesIntoHtmlRoot($modal, [
            'wire:partial' => "action-modals.{$this->getNestingIndex()}",
        ]));
    }
}
