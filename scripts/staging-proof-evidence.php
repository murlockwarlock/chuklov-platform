<?php

use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use Illuminate\Support\Facades\DB;

function syntheticEvidenceCheck(int $userId, int $clientId): void
{
    bootstrapApplication(false);
    requireStagingProofTarget();
    [$organization, , $client] = smokeIdentity($userId, $clientId);
    if (! is_string($client->email) || ! preg_match('/^system-proof-20261009-[a-z0-9]{12}@example\.test$/', $client->email)) {
        fail('SYNTHETIC EVIDENCE', 'only this task synthetic client is allowed');
    }
    $organizationId = $organization->getKey();
    $bookings = Booking::query()->where('organization_id', $organizationId)->where('client_id', $clientId)
        ->latest('id')->limit(20)->get();
    $scenarioEvents = ScenarioEvent::query()->where('organization_id', $organizationId)
        ->where('aggregate_type', Booking::class)->whereIn('aggregate_id', $bookings->modelKeys())
        ->orderBy('id')->limit(100)->get();
    echo 'SYNTHETIC_EVIDENCE='.json_encode([
        'database' => DB::connection()->getDriverName(),
        'client_id' => $clientId,
        'profile_name_saved' => $client->full_name === 'Synthetic acceptance 20261009',
        'consents' => ClientConsent::query()->where('organization_id', $organizationId)->where('client_id', $clientId)
            ->orderBy('id')->get(['subject', 'version', 'granted', 'is_required'])->toArray(),
        'bookings' => $bookings->map(static fn ($booking): array => [
            'id' => $booking->getKey(), 'status' => $booking->status->value, 'party_size' => $booking->party_size,
            'event_version' => $booking->event_version, 'source' => $booking->source->value,
            'starts_at' => $booking->starts_at->toIso8601String(), 'ends_at' => $booking->ends_at->toIso8601String(),
            'blocking_ends_at' => $booking->blocking_ends_at->toIso8601String(),
            'events' => BookingEvent::query()->where('organization_id', $organizationId)->where('booking_id', $booking->getKey())
                ->orderBy('id')->get(['event_type', 'actor_type'])->toArray(),
        ])->all(),
        'obligation_count' => FinancialObligation::query()->where('organization_id', $organizationId)->where('client_id', $clientId)->count(),
        'scenario_events' => $scenarioEvents->map(static fn ($event): array => [
            'id' => $event->getKey(), 'event' => $event->event_name->value, 'status' => $event->status->value,
        ])->all(),
        'scenario_actions' => ScenarioAction::query()->where('organization_id', $organizationId)
            ->whereIn('scenario_event_id', $scenarioEvents->modelKeys())->orderBy('id')->limit(100)
            ->get(['id', 'recipient_type', 'status'])->toArray(),
        'conversations' => Conversation::query()->where('organization_id', $organizationId)->where('client_id', $clientId)
            ->get(['id', 'automation_state'])->toArray(),
        'turns' => CompanionTurn::query()->where('organization_id', $organizationId)->where('client_id', $clientId)
            ->orderBy('id')->limit(20)->get(['id', 'status', 'failure_code', 'outbound_message_id'])->toArray(),
        'escalations' => CompanionEscalation::query()->where('organization_id', $organizationId)->where('client_id', $clientId)
            ->orderBy('id')->limit(20)->get(['id', 'reason', 'status'])->toArray(),
        'messages' => ConversationMessage::query()->where('organization_id', $organizationId)->where('client_id', $clientId)
            ->orderBy('id')->limit(40)->get()->map(static fn ($message): array => [
                'id' => $message->getKey(), 'author' => $message->author_type->value,
                'plaintext_absent' => $message->body === null, 'encrypted_body_present' => $message->encrypted_body !== null,
            ])->all(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
    ok('SYNTHETIC EVIDENCE', 'read only; no plaintext message, contact, token, credential or private content output');
}
