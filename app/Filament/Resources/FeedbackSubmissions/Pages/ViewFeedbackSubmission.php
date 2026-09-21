<?php

namespace App\Filament\Resources\FeedbackSubmissions\Pages;

use App\Filament\Resources\FeedbackSubmissions\FeedbackSubmissionResource;
use App\Filament\Support\LocalizedViewRecord;

final class ViewFeedbackSubmission extends LocalizedViewRecord
{
    protected static string $resource = FeedbackSubmissionResource::class;

    public function getTitle(): string
    {
        return __('Обратная связь');
    }
}
