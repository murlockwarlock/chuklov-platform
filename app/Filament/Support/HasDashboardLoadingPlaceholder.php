<?php

namespace App\Filament\Support;

use Illuminate\Contracts\View\View;

trait HasDashboardLoadingPlaceholder
{
    public function placeholder(): View
    {
        return view('filament.widgets.dashboard-loading-placeholder', [
            'columnSpan' => $this->getColumnSpan(),
            'columnStart' => $this->getColumnStart(),
        ]);
    }
}
