<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalKeyResolverInterface;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Models\MedicalClientSafetyMarker;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ClearMedicalTest18SafetyMarker
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private MedicalEncryptorInterface $encryptor,
        private MedicalKeyResolverInterface $keyResolver,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client, DateTimeInterface|string $clearedAt, string $reason): MedicalClientSafetyMarker
    {
        $organization = $this->authorization->authorizeManage($actor, $client);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Укажите причину снятия маркера длиной до 2000 символов.']);
        }
        if (is_string($clearedAt) && trim($clearedAt) === '') {
            throw ValidationException::withMessages(['cleared_at' => 'Укажите дату снятия маркера.']);
        }
        try {
            $clearedAt = $clearedAt instanceof DateTimeInterface
                ? Carbon::instance($clearedAt)
                : Carbon::parse($clearedAt);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['cleared_at' => 'Укажите корректную дату снятия маркера.']);
        }
        $organizationId = (int) $organization->getKey();
        $keyVersion = $this->keyResolver->getCurrentKeyVersion($organizationId);

        return DB::transaction(function () use ($actor, $client, $organization, $organizationId, $clearedAt, $reason, $keyVersion): MedicalClientSafetyMarker {
            $marker = MedicalClientSafetyMarker::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $client->getKey())
                ->where('marker_key', MedicalTest18SafetyMarker::MARKER_KEY)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if ($marker === null) {
                throw ValidationException::withMessages(['marker' => 'Активный маркер не найден.']);
            }

            $marker->forceFill([
                'is_active' => false,
                'cleared_at' => $clearedAt,
                'cleared_by' => $actor->getKey(),
                'clear_reason' => $this->encryptor->encryptField($organizationId, $reason, $keyVersion),
                'clear_reason_key_version' => $keyVersion,
            ])->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.session.updated',
                targetType: Client::class,
                targetId: (string) $client->getKey(),
                metadata: [
                    'source' => 'test_18',
                    'key_version' => $keyVersion,
                    'updated_fields' => 'safety_marker_cleared',
                ],
            );

            return $marker->refresh();
        });
    }
}
