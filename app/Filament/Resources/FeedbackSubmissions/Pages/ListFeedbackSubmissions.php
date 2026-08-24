<?php

namespace App\Filament\Resources\FeedbackSubmissions\Pages;

use App\Filament\Resources\FeedbackSubmissions\FeedbackSubmissionResource;
use Filament\Resources\Pages\ListRecords;

final class ListFeedbackSubmissions extends ListRecords
{
    protected static string $resource = FeedbackSubmissionResource::class;

    protected static ?string $title = 'Обратная связь';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
