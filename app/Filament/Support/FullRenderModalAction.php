<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Livewire\Component;
use Livewire\Drawer\Utils;

final class FullRenderModalAction extends Action
{
    public function toModalHtmlable(): Htmlable
    {
        $modal = $this->renderModal()->render();
        $livewire = $this->getLivewire();

        if (! $livewire instanceof Component) {
            return new HtmlString($modal);
        }

        $modalId = "fi-{$livewire->getId()}-action-{$this->getNestingIndex()}";

        return new HtmlString(Utils::insertAttributesIntoHtmlRoot($modal, [
            'wire:partial' => "action-modals.{$this->getNestingIndex()}",
            'x-on:open-modal.window.capture' => 'if ($event.detail.id === '.Js::from($modalId).') isWindowVisible = true',
        ]));
    }
}
