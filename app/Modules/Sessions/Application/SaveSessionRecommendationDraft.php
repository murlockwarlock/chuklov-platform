<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Application\DTOs\MedicalSessionRecommendationData;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SaveSessionRecommendationDraft
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private MedicalEncryptorInterface $encryptor,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        MedicalSession $session,
        Client $client,
        string $draft,
    ): MedicalSessionRecommendationData {
        $organization = $this->authorization->authorizeManageSession($actor, $session, $client);
        $draft = trim($draft);

        if ($draft === '') {
            throw ValidationException::withMessages([
                'recommendation_draft' => 'Введите текст рекомендации перед сохранением.',
            ]);
        }
        if (mb_strlen($draft) > MedicalSessionFieldLimits::MaxTextLength) {
            throw ValidationException::withMessages([
                'recommendation_draft' => 'Поле превышает максимальную длину в '.MedicalSessionFieldLimits::MaxTextLength.' символов.',
            ]);
        }

        $orgId = (int) $organization->getKey();
        $sessionId = (int) $session->getKey();

        return DB::transaction(function () use ($actor, $client, $draft, $organization, $orgId, $sessionId): MedicalSessionRecommendationData {
            /** @var MedicalSession|null $persisted */
            $persisted = MedicalSession::query()
                ->where('organization_id', $orgId)
                ->where('client_id', $client->getKey())
                ->whereKey($sessionId)
                ->lockForUpdate()
                ->first();

            if ($persisted === null) {
                throw ValidationException::withMessages(['session' => 'Медицинский сеанс не найден.']);
            }

            $keyVersion = max(
                1,
                (int) ($persisted->getAttribute('recommendation_key_version') ?: $persisted->getAttribute('encryption_key_version')),
            );
            $existingDraft = $this->decryptString(
                $orgId,
                $persisted->getRawOriginal('recommendation_draft'),
                $keyVersion,
                'recommendation_draft',
            );
            $revision = max(0, (int) $persisted->getAttribute('recommendation_revision'));

            if ($existingDraft !== $draft || $revision === 0) {
                $persisted->forceFill([
                    'recommendation_draft' => $this->encryptor->encryptField($orgId, $draft, $keyVersion),
                    'recommendation_revision' => $existingDraft === $draft ? max(1, $revision) : $revision + 1,
                    'recommendation_key_version' => $keyVersion,
                ])->save();

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'medical.session.updated',
                    targetType: MedicalSession::class,
                    targetId: (string) $persisted->getKey(),
                    metadata: [
                        'source' => 'crm',
                        'key_version' => $keyVersion,
                        'updated_fields' => 'recommendation_draft',
                    ],
                );
            }

            $generated = $this->decryptString(
                $orgId,
                $persisted->getRawOriginal('recommendation_generated'),
                $keyVersion,
                'recommendation_draft',
            );

            return new MedicalSessionRecommendationData(
                sessionId: (int) $persisted->getKey(),
                organizationId: $orgId,
                clientId: (int) $persisted->client_id,
                draft: $draft,
                revision: max(1, (int) $persisted->getAttribute('recommendation_revision')),
                generated: $generated,
                updatedAt: $persisted->updated_at,
            );
        });
    }

    private function decryptString(int $organizationId, mixed $ciphertext, int $keyVersion, string $field): ?string
    {
        if ($ciphertext === null) {
            return null;
        }

        if (! is_string($ciphertext)) {
            throw ValidationException::withMessages([$field => 'Сохранённая рекомендация имеет неверный формат.']);
        }

        return $this->encryptor->decryptField($organizationId, $ciphertext, $keyVersion);
    }
}
