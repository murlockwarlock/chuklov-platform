<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

final class FullRenderModalAction extends Action
{
    public function toModalHtmlable(): Htmlable
    {
        return new HtmlString($this->renderModal()->render());
    }
}
