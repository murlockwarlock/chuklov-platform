<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalKeyResolverInterface;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Models\MedicalAnthropometricMeasurement;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final readonly class SaveMedicalAnthropometryMeasurement
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private MedicalMeasurementCalculator $calculator,
        private MedicalEncryptorInterface $encryptor,
        private MedicalKeyResolverInterface $keyResolver,
        private RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $values */
    public function handle(
        User $actor,
        Client $client,
        array $values,
        ?DateTimeInterface $measuredAt = null,
    ): MedicalAnthropometricMeasurement {
        $organization = $this->authorization->authorizeManage($actor, $client);
        $orgId = (int) $organization->getKey();
        $normalized = $this->calculator->normalizeAnthropometry($values);
        $keyVersion = $this->keyResolver->getCurrentKeyVersion($orgId);
        $measuredAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($actor, $client, $organization, $orgId, $normalized, $keyVersion, $measuredAt): MedicalAnthropometricMeasurement {
            $measurement = new MedicalAnthropometricMeasurement;
            $measurement->forceFill([
                'organization_id' => $orgId,
                'client_id' => $client->getKey(),
                'measured_by_user_id' => $actor->getKey(),
                'payload' => $this->encryptor->encryptField(
                    $orgId,
                    json_encode($normalized, JSON_THROW_ON_ERROR),
                    $keyVersion,
                ),
                'encryption_key_version' => $keyVersion,
                'measured_at' => CarbonImmutable::instance($measuredAt),
            ])->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.session.updated',
                targetType: MedicalAnthropometricMeasurement::class,
                targetId: (string) $measurement->getKey(),
                metadata: [
                    'source' => 'crm',
                    'key_version' => $keyVersion,
                    'updated_fields' => 'anthropometry',
                ],
            );

            return $measurement;
        });
    }
}
