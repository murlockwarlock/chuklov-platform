<?php

namespace App\Filament\Resources\FeedbackSubmissions\Pages;

use App\Filament\Resources\FeedbackSubmissions\FeedbackSubmissionResource;
use App\Filament\Support\LocalizedListRecords;

final class ListFeedbackSubmissions extends LocalizedListRecords
{
    protected static string $resource = FeedbackSubmissionResource::class;

    protected static ?string $title = 'Обратная связь';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
