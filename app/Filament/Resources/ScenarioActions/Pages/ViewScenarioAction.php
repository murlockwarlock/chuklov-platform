<?php

namespace App\Filament\Resources\ScenarioActions\Pages;

use App\Filament\Resources\ScenarioActions\ScenarioActionResource;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewScenarioAction extends ViewRecord
{
    protected static string $resource = ScenarioActionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openDialog')
                ->label('Открыть диалог')
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->visible(fn (): bool => $this->dialogUrl() !== null)
                ->url(fn (): ?string => $this->dialogUrl()),
        ];
    }

    private function dialogUrl(): ?string
    {
        $record = $this->getRecord();
        if (! $record instanceof ScenarioAction) {
            return null;
        }

        $url = $record->render_context['companion']['crm_url'] ?? null;

        return is_string($url) && trim($url) !== '' ? $url : null;
    }
}
