<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Application\DTOs\MedicalSessionRecommendationDeliveryData;
use App\Modules\Sessions\Application\DTOs\MedicalSessionRecommendationHistoryData;
use App\Modules\Sessions\Application\Models\MedicalSessionRecommendationDelivery;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Validation\ValidationException;

final readonly class ListClientSessionRecommendations
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private MedicalEncryptorInterface $encryptor,
    ) {}

    /** @return list<MedicalSessionRecommendationHistoryData> */
    public function handle(User $actor, Client $client): array
    {
        $organization = $this->authorization->authorizeViewClient($actor, $client);
        $orgId = (int) $organization->getKey();

        $sessions = MedicalSession::query()
            ->where('organization_id', $orgId)
            ->where('client_id', $client->getKey())
            ->whereNotNull('recommendation_draft')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'client_id',
                'recommendation_draft',
                'recommendation_revision',
                'recommendation_key_version',
                'encryption_key_version',
                'occurred_at',
            ]);

        if ($sessions->isEmpty()) {
            return [];
        }

        $sessionIds = $sessions->map(static fn (MedicalSession $session): int => (int) $session->getKey())->all();
        $deliveries = MedicalSessionRecommendationDelivery::query()
            ->where('organization_id', $orgId)
            ->whereIn('medical_session_id', $sessionIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('medical_session_id');

        $history = [];
        foreach ($sessions as $session) {
            $keyVersion = max(
                1,
                (int) ($session->getAttribute('recommendation_key_version') ?: $session->getAttribute('encryption_key_version')),
            );
            $ciphertext = $session->getRawOriginal('recommendation_draft');
            if (! is_string($ciphertext)) {
                throw ValidationException::withMessages([
                    'recommendations' => 'Сохранённая рекомендация имеет неверный формат.',
                ]);
            }

            $draft = $this->encryptor->decryptField($orgId, $ciphertext, $keyVersion);
            if (! is_string($draft) || trim($draft) === '') {
                continue;
            }

            $sessionDeliveries = $deliveries->get((int) $session->getKey(), collect());
            $history[] = new MedicalSessionRecommendationHistoryData(
                sessionId: (int) $session->getKey(),
                clientId: (int) $session->client_id,
                draft: $draft,
                revision: (int) $session->recommendation_revision,
                occurredAt: $session->occurred_at,
                deliveries: array_values($sessionDeliveries
                    ->map(static fn (MedicalSessionRecommendationDelivery $delivery): MedicalSessionRecommendationDeliveryData => MedicalSessionRecommendationDeliveryData::fromModel($delivery))
                    ->all()),
            );
        }

        return $history;
    }
}
