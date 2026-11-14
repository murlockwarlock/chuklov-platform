<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalKeyResolverInterface;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Models\MedicalClientSafetyMarker;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionSafetyMarker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class MedicalTest18SafetyMarker
{
    public const MARKER_KEY = 'test_18_safety';

    public const DEFAULT_TEXT = 'Жёсткие манипуляции, особенно с шеей, запрещены';

    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private MedicalEncryptorInterface $encryptor,
        private MedicalKeyResolverInterface $keyResolver,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, MedicalSession $session, bool $hasYes, ?string $markerText = null): ?MedicalClientSafetyMarker
    {
        $organization = $this->authorization->authorizeManageSession($actor, $session);
        if (! $hasYes) {
            return MedicalClientSafetyMarker::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $session->client_id)
                ->where('marker_key', self::MARKER_KEY)
                ->where('is_active', true)
                ->first();
        }

        $organizationId = (int) $organization->getKey();
        $markerText = $this->normalizeText($markerText ?? self::DEFAULT_TEXT);
        $keyVersion = $this->keyResolver->getCurrentKeyVersion($organizationId);

        return DB::transaction(function () use ($actor, $organization, $organizationId, $session, $markerText, $keyVersion): MedicalClientSafetyMarker {
            $clientMarker = MedicalClientSafetyMarker::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $session->client_id)
                ->where('marker_key', self::MARKER_KEY)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if ($clientMarker === null) {
                $clientMarker = new MedicalClientSafetyMarker;
                $clientMarker->forceFill([
                    'organization_id' => $organizationId,
                    'client_id' => $session->client_id,
                    'marker_key' => self::MARKER_KEY,
                    'marker_text' => $this->encryptor->encryptField($organizationId, $markerText, $keyVersion),
                    'encryption_key_version' => $keyVersion,
                    'is_active' => true,
                    'source_session_id' => $session->getKey(),
                    'activated_at' => now(),
                    'activated_by' => $actor->getKey(),
                ])->save();
            }

            $sessionMarker = MedicalSessionSafetyMarker::query()
                ->where('organization_id', $organizationId)
                ->where('medical_session_id', $session->getKey())
                ->where('marker_key', self::MARKER_KEY)
                ->lockForUpdate()
                ->first();
            if ($sessionMarker === null) {
                $sessionMarker = new MedicalSessionSafetyMarker;
                $sessionMarker->forceFill([
                    'organization_id' => $organizationId,
                    'client_id' => $session->client_id,
                    'medical_session_id' => $session->getKey(),
                    'marker_key' => self::MARKER_KEY,
                    'marker_text' => $this->encryptor->encryptField($organizationId, $markerText, $keyVersion),
                    'encryption_key_version' => $keyVersion,
                ])->save();
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.session.updated',
                targetType: MedicalSession::class,
                targetId: (string) $session->getKey(),
                metadata: [
                    'source' => 'test_18',
                    'key_version' => $keyVersion,
                    'updated_fields' => 'safety_marker_activated',
                ],
            );

            return $clientMarker->refresh();
        });
    }

    /** @param array<string, mixed> $test18Result */
    public function handleFromResult(User $actor, MedicalSession $session, array $test18Result, ?string $markerText = null): ?MedicalClientSafetyMarker
    {
        return $this->handle($actor, $session, $this->containsYes($test18Result), $markerText);
    }

    private function normalizeText(string $text): string
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 2000) {
            throw ValidationException::withMessages(['marker_text' => 'Текст маркера должен быть заполнен и не длиннее 2000 символов.']);
        }

        return $text;
    }

    /** @param array<string, mixed> $payload */
    private function containsYes(array $payload): bool
    {
        $yesValues = ['yes', 'да', 'true', '1'];
        $walk = function (mixed $value) use (&$walk, $yesValues): bool {
            if (! is_array($value)) {
                return false;
            }
            if (($value['marked'] ?? false) === true) {
                return true;
            }
            if (isset($value['answer']) && in_array(mb_strtolower(trim((string) $value['answer'])), $yesValues, true)) {
                return true;
            }
            foreach ($value as $child) {
                if ($walk($child)) {
                    return true;
                }
            }

            return false;
        };

        return $walk($payload);
    }
}
