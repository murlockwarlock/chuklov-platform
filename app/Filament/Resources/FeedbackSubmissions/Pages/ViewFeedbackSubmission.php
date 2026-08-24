<?php

namespace App\Filament\Resources\FeedbackSubmissions\Pages;

use App\Filament\Resources\FeedbackSubmissions\FeedbackSubmissionResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewFeedbackSubmission extends ViewRecord
{
    protected static string $resource = FeedbackSubmissionResource::class;

    public function getTitle(): string
    {
        return 'Обратная связь';
    }
}
