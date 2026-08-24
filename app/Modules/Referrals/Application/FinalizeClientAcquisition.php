<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Attribution\Domain\Models\PreAuthAttribution;
use App\Modules\Attribution\Domain\ValueObjects\AttributionData;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientAcquisitionRegistration;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Enums\ReferralEstablishmentMethod;
use App\Modules\Referrals\Domain\Models\ClientReferralIdentity;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

final class FinalizeClientAcquisition
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly EnsureReferralIdentity $ensureIdentity,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        Client $client,
        string $sessionId,
        ?int $telegramAuthenticationRequestId = null,
    ): Client {
        $organization = $this->context->organization();
        abort_unless((int) $client->organization_id === (int) $organization->getKey(), 404);
        $this->ensureIdentity->handle($client);
        $sessionHash = hash('sha256', trim($sessionId));

        return DB::transaction(function () use (
            $organization,
            $client,
            $sessionHash,
            $telegramAuthenticationRequestId,
        ): Client {
            $lockedClient = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $registration = ClientAcquisitionRegistration::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $lockedClient->getKey())
                ->where(function ($query) use ($sessionHash, $telegramAuthenticationRequestId): void {
                    $query->where('session_hash', $sessionHash);

                    if ($telegramAuthenticationRequestId !== null) {
                        $query->orWhere('telegram_authentication_request_id', $telegramAuthenticationRequestId);
                    }
                })
                ->lockForUpdate()
                ->first();

            if (! $registration instanceof ClientAcquisitionRegistration) {
                return $lockedClient;
            }

            if ($registration->finalized_at !== null) {
                return $lockedClient;
            }

            $preAuth = PreAuthAttribution::query()
                ->where('organization_id', $organization->getKey())
                ->where('session_hash', $sessionHash)
                ->lockForUpdate()
                ->first();

            if ($preAuth instanceof PreAuthAttribution
                && $preAuth->consumed_at === null
                && ! $preAuth->expires_at->isPast()) {
                $data = $preAuth->attributionData();
                $acceptedReferral = $this->acceptReferral(
                    organizationId: (int) $organization->getKey(),
                    client: $lockedClient,
                    data: $data,
                );
                $acceptedData = $this->acceptedData($data, $acceptedReferral);

                if ($acceptedData instanceof AttributionData
                    && ! ClientAttribution::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('client_id', $lockedClient->getKey())
                        ->exists()) {
                    $attribution = new ClientAttribution;
                    $attribution->forceFill([
                        'organization_id' => $organization->getKey(),
                        'client_id' => $lockedClient->getKey(),
                        ...$acceptedData->toArray(),
                        'capture_channel' => $preAuth->capture_channel,
                        'capture_context' => $preAuth->capture_context,
                        'captured_at' => $preAuth->captured_at,
                        'accepted_at' => now(),
                    ]);
                    $attribution->save();
                    $this->audit->handle(
                        organization: $organization,
                        actor: null,
                        action: 'attribution.accepted',
                        targetType: ClientAttribution::class,
                        targetId: (string) $attribution->getKey(),
                        metadata: [
                            'source_type' => $acceptedData->sourceType,
                            'capture_channel' => $preAuth->capture_channel,
                            'has_referral' => $acceptedData->referralCode !== null,
                            'has_utm' => $this->hasUtm($acceptedData),
                        ],
                    );

                    if (trim((string) $lockedClient->lead_source) === '') {
                        $lockedClient->forceFill([
                            'lead_source' => $acceptedData->source ?? $acceptedData->sourceType,
                        ])->save();
                    }
                }

                $preAuth->forceFill([
                    'consumed_at' => now(),
                    'consumed_client_id' => $lockedClient->getKey(),
                    'updated_at' => now(),
                ])->save();
            }

            $registration->forceFill([
                'finalized_at' => now(),
                'updated_at' => now(),
            ])->save();

            return $lockedClient->refresh();
        });
    }

    private function acceptReferral(
        int $organizationId,
        Client $client,
        AttributionData $data,
    ): ?ClientReferralIdentity {
        if ($data->referralCode === null) {
            return null;
        }

        $identity = ClientReferralIdentity::query()
            ->where('organization_id', $organizationId)
            ->where('public_code', $data->referralCode)
            ->first();

        if (! $identity instanceof ClientReferralIdentity
            || (int) $identity->client_id === (int) $client->getKey()) {
            return null;
        }

        $firstTouch = ClientAttribution::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->lockForUpdate()
            ->first();

        if ($firstTouch?->source_type === 'referral') {
            $firstTouchIdentity = ClientReferralIdentity::query()
                ->where('organization_id', $organizationId)
                ->where('public_code', $firstTouch->referral_code)
                ->first();

            if (! $firstTouchIdentity instanceof ClientReferralIdentity
                || (int) $firstTouchIdentity->client_id !== (int) $identity->client_id) {
                return null;
            }
        }

        $existing = ReferralRelationship::query()
            ->where('organization_id', $organizationId)
            ->where('referred_client_id', $client->getKey())
            ->lockForUpdate()
            ->first();

        if ($existing instanceof ReferralRelationship) {
            return null;
        }

        $relationship = new ReferralRelationship;
        $relationship->forceFill([
            'organization_id' => $organizationId,
            'referrer_client_id' => $identity->client_id,
            'referred_client_id' => $client->getKey(),
            'establishment_method' => ReferralEstablishmentMethod::AutomaticReferralLink,
            'registered_at' => now(),
        ]);
        $relationship->save();
        $this->audit->handle(
            organization: $this->context->organization(),
            actor: null,
            action: 'referral.relationship.created',
            targetType: ReferralRelationship::class,
            targetId: (string) $relationship->getKey(),
            metadata: [
                'referrer_client_id' => $identity->client_id,
                'referred_client_id' => $client->getKey(),
                'establishment_method' => ReferralEstablishmentMethod::AutomaticReferralLink->value,
            ],
        );

        return $identity;
    }

    private function acceptedData(AttributionData $data, ?ClientReferralIdentity $identity): ?AttributionData
    {
        if ($data->referralCode === null || $identity instanceof ClientReferralIdentity) {
            return $data;
        }

        if ($data->source !== null) {
            return new AttributionData('source', $data->source, null, $data->utmSource, $data->utmMedium, $data->utmCampaign, $data->utmContent, $data->utmTerm);
        }

        if ($this->hasUtm($data)) {
            return new AttributionData('utm', null, null, $data->utmSource, $data->utmMedium, $data->utmCampaign, $data->utmContent, $data->utmTerm);
        }

        return null;
    }

    private function hasUtm(AttributionData $data): bool
    {
        return $data->utmSource !== null
            || $data->utmMedium !== null
            || $data->utmCampaign !== null
            || $data->utmContent !== null
            || $data->utmTerm !== null;
    }
}
