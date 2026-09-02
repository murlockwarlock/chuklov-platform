<?php

namespace App\Filament\Resources\B2bLeads\Pages;

use App\Filament\Resources\B2bLeads\B2bLeadResource;
use App\Models\User;
use App\Modules\B2B\Application\SubmitB2bLead;
use App\Modules\B2B\Domain\Enums\B2bLeadSource;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CreateB2bLead extends CreateRecord
{
    protected static string $resource = B2bLeadResource::class;

    protected static ?string $title = 'Новый B2B-лид';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $context = app(OrganizationContext::class);
        $organizationId = $context->id();
        $client = Client::query()->where('organization_id', $organizationId)->findOrFail((int) $data['client_id']);
        $specialist = Specialist::query()->where('organization_id', $organizationId)->findOrFail((int) $data['specialist_id']);
        $startsAt = $data['starts_at'] instanceof DateTimeInterface
            ? CarbonImmutable::instance($data['starts_at'])
            : CarbonImmutable::parse((string) $data['starts_at'], $context->organization()->defaultTimezone());

        return app(SubmitB2bLead::class)->handle(
            actor: $actor,
            client: $client,
            specialist: $specialist,
            startsAt: $startsAt,
            requestedTimezone: (string) ($data['requested_timezone'] ?? $context->organization()->defaultTimezone()),
            idempotencyKey: 'crm:'.Str::uuid(),
            source: B2bLeadSource::Crm,
            meetingMode: VideoMeetingMode::from((string) ($data['meeting_mode'] ?? VideoMeetingMode::Automatic->value)),
            manualMeetingUrl: isset($data['manual_meeting_url']) ? (string) $data['manual_meeting_url'] : null,
        );
    }
}
