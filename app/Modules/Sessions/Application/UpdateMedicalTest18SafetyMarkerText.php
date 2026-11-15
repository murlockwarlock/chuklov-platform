<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalKeyResolverInterface;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Models\MedicalClientSafetyMarker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class UpdateMedicalTest18SafetyMarkerText
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private MedicalEncryptorInterface $encryptor,
        private MedicalKeyResolverInterface $keyResolver,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client, string $text): MedicalClientSafetyMarker
    {
        $organization = $this->authorization->authorizeManage($actor, $client);
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 2000) {
            throw ValidationException::withMessages(['marker_text' => 'Текст маркера должен быть заполнен и не длиннее 2000 символов.']);
        }
        $organizationId = (int) $organization->getKey();
        $keyVersion = $this->keyResolver->getCurrentKeyVersion($organizationId);

        return DB::transaction(function () use ($actor, $client, $organization, $organizationId, $text, $keyVersion): MedicalClientSafetyMarker {
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
                'marker_text' => $this->encryptor->encryptField($organizationId, $text, $keyVersion),
                'encryption_key_version' => $keyVersion,
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
                    'updated_fields' => 'safety_marker_text',
                ],
            );

            return $marker->refresh();
        });
    }
}
