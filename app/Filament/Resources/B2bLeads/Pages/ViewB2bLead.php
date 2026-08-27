<?php

namespace App\Filament\Resources\B2bLeads\Pages;

use App\Filament\Resources\B2bLeads\Actions\B2bLeadActions;
use App\Filament\Resources\B2bLeads\B2bLeadResource;
use App\Modules\B2B\Domain\Models\B2bLead;
use Filament\Resources\Pages\ViewRecord;

final class ViewB2bLead extends ViewRecord
{
    protected static string $resource = B2bLeadResource::class;

    protected static ?string $title = 'B2B-лид';

    protected function getHeaderActions(): array
    {
        return B2bLeadActions::for($this->lead(), function (array $fields): void {
            $this->refreshFormData($fields);
        });
    }

    private function lead(): B2bLead
    {
        abort_unless($this->record instanceof B2bLead, 404);

        return $this->record->refresh()->load(['client', 'salesCall.specialist']);
    }
}
