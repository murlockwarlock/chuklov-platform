<?php

namespace App\Filament\Resources\FeedbackSubmissions\Pages;

use App\Filament\Resources\FeedbackSubmissions\FeedbackSubmissionResource;
use App\Filament\Support\LocalizedCreateRecord;
use App\Models\User;
use App\Modules\Feedback\Application\RecordNpsSubmission;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CreateFeedbackSubmission extends LocalizedCreateRecord
{
    protected static string $resource = FeedbackSubmissionResource::class;

    protected static ?string $title = 'Добавить обратную связь';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $client = Client::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->findOrFail((int) ($data['client_id'] ?? 0));

        return app(RecordNpsSubmission::class)->handleForStaff(
            actor: $actor,
            client: $client,
            score: (int) ($data['score'] ?? -1),
            internalFeedback: isset($data['internal_feedback']) ? (string) $data['internal_feedback'] : null,
            idempotencyKey: 'crm:feedback:'.Str::uuid()->toString(),
            themeIds: is_array($data['theme_ids'] ?? null) ? $data['theme_ids'] : [],
        );
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('Обратная связь сохранена');
    }
}
