<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Modules\Analytics\Application\ClientSegmentQuery;
use App\Modules\Analytics\Domain\Enums\ClientSegment;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    protected static ?string $title = 'База клиентов';

    protected string $view = 'filament.resources.clients.pages.list-clients';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Добавить клиента')];
    }

    public function selectSegment(string $segment): void
    {
        $selected = ClientSegment::tryFrom($segment);
        if (! $selected instanceof ClientSegment) {
            return;
        }

        $filters = $this->tableFilters ?? [];
        if ($selected === ClientSegment::All) {
            unset($filters['segment']);
        } else {
            $filters['segment'] = ['value' => $selected->value];
        }

        $this->tableFilters = $filters === [] ? null : $filters;
        $this->getTableFiltersForm()->fill($this->tableFilters);
        $this->handleTableFilterUpdates();
    }

    public function selectedSegment(): string
    {
        return (string) data_get($this->tableFilters, 'segment.value', ClientSegment::All->value);
    }

    /** @return array<string, int> */
    public function getSegmentCountsProperty(): array
    {
        return app(ClientSegmentQuery::class)->summary($this->tableSearch);
    }

    /** @return array<string, string> */
    public function segmentLabels(): array
    {
        return ClientSegment::labels();
    }
}
