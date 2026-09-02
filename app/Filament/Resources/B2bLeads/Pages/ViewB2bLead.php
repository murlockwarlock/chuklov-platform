<?php

namespace App\Filament\Resources\B2bLeads\Pages;

use App\Filament\Resources\B2bLeads\Actions\B2bLeadActions;
use App\Filament\Resources\B2bLeads\B2bLeadResource;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Resources\Pages\ViewRecord;

final class ViewB2bLead extends ViewRecord
{
    protected static string $resource = B2bLeadResource::class;

    protected static ?string $title = 'B2B-лид';

    protected function getHeaderActions(): array
    {
        return B2bLeadActions::for($this->lead(), function (): void {
            $record = $this->getRecord();
            $fresh = B2bLead::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->whereKey($record->getKey())
                ->with(['client', 'salesCall.specialist'])
                ->firstOrFail();
            $record->setRawAttributes($fresh->getAttributes());
            $record->setRelations($fresh->getRelations());
            $record->syncOriginal();
        });
    }

    private function lead(): B2bLead
    {
        abort_unless($this->record instanceof B2bLead, 404);

        return B2bLead::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey($this->record->getKey())
            ->with(['client', 'salesCall.specialist'])
            ->firstOrFail();
    }
}
