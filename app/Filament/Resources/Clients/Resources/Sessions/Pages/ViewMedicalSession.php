<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Pages;

use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewMedicalSession extends ViewRecord
{
    protected static string $resource = MedicalSessionResource::class;

    protected static ?string $title = 'Сеанс';

    protected static ?string $breadcrumb = 'Сеанс';
}
