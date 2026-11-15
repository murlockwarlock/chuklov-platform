<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\Exceptions\NotificationDeliveryException;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Application\DTOs\MedicalSessionRecommendationDeliveryData;
use App\Modules\Sessions\Application\Models\MedicalSessionRecommendationDelivery;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Support\SupportedLocale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class SendSessionRecommendation
{
    private const CHANNEL = 'telegram';

    private const CLAIM_TIMEOUT_MINUTES = 5;

    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private MedicalEncryptorInterface $encryptor,
        private NotificationChannelRegistry $channels,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        MedicalSession $session,
        Client $client,
    ): MedicalSessionRecommendationDeliveryData {
        $organization = $this->authorization->authorizeManageSession($actor, $session, $client);
        $orgId = (int) $organization->getKey();
        $sessionId = (int) $session->getKey();

        /** @var array{delivery: MedicalSessionRecommendationDelivery, should_send: bool, draft: string, revision: int, idempotency_key: string} $claim */
        $claim = DB::transaction(function () use ($client, $orgId, $sessionId): array {
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
            $draft = $this->decryptString(
                $orgId,
                $persisted->getRawOriginal('recommendation_draft'),
                $keyVersion,
            );
            if (! filled($draft)) {
                throw ValidationException::withMessages([
                    'recommendation_draft' => 'Сначала сохраните текст рекомендации специалиста.',
                ]);
            }

            $revision = (int) $persisted->getAttribute('recommendation_revision');
            if ($revision < 1) {
                $revision = 1;
                $persisted->forceFill(['recommendation_revision' => $revision])->save();
            }

            $idempotencyKey = self::idempotencyKey($orgId, $sessionId, $revision);
            $delivery = MedicalSessionRecommendationDelivery::query()
                ->where('organization_id', $orgId)
                ->where('medical_session_id', $sessionId)
                ->where('recommendation_revision', $revision)
                ->where('channel', self::CHANNEL)
                ->lockForUpdate()
                ->first();

            if ($delivery instanceof MedicalSessionRecommendationDelivery) {
                if ((string) $delivery->status === 'claimed'
                    && $delivery->getRawOriginal('claimed_at') !== null
                    && now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES)->greaterThan(
                        Carbon::parse((string) $delivery->getRawOriginal('claimed_at')),
                    )) {
                    $delivery->forceFill([
                        'status' => 'unknown',
                        'error_code' => 'delivery_claim_expired',
                        'failed_at' => now(),
                    ])->save();
                }

                return [
                    'delivery' => $delivery->refresh(),
                    'should_send' => false,
                    'draft' => $draft,
                    'revision' => $revision,
                    'idempotency_key' => $idempotencyKey,
                ];
            }

            $delivery = new MedicalSessionRecommendationDelivery;
            $delivery->forceFill([
                'organization_id' => $orgId,
                'client_id' => $client->getKey(),
                'medical_session_id' => $sessionId,
                'recommendation_revision' => $revision,
                'channel' => self::CHANNEL,
                'status' => 'claimed',
                'idempotency_key' => $idempotencyKey,
                'content_hash' => hash('sha256', $draft),
                'claimed_at' => now(),
            ])->save();

            return [
                'delivery' => $delivery->refresh(),
                'should_send' => true,
                'draft' => $draft,
                'revision' => $revision,
                'idempotency_key' => $idempotencyKey,
            ];
        });

        if (! $claim['should_send']) {
            return MedicalSessionRecommendationDeliveryData::fromModel($claim['delivery']);
        }

        $result = $this->send(
            organizationId: $orgId,
            client: $client,
            draft: $claim['draft'],
            idempotencyKey: $claim['idempotency_key'],
        );

        return $this->finalize($organization, $actor, $claim['delivery'], $result);
    }

    private function send(int $organizationId, Client $client, string $draft, string $idempotencyKey): NotificationDeliveryResult
    {
        $identity = ClientChannelIdentity::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->where('channel', self::CHANNEL)
            ->where('verification_status', ChannelIdentityStatus::Verified->value)
            ->orderBy('id')
            ->first();
        $channel = $this->channels->get(self::CHANNEL);

        if (! $identity instanceof ClientChannelIdentity || trim((string) $identity->external_id) === '') {
            return NotificationDeliveryResult::unavailable('verified_telegram_unavailable');
        }
        if ($channel === null || ! $channel->capabilities()->supportsProactiveDelivery) {
            return NotificationDeliveryResult::unavailable('telegram_channel_unavailable');
        }

        try {
            return $channel->send(new NotificationMessage(
                recipientExternalId: (string) $identity->external_id,
                body: $draft,
                subject: null,
                locale: SupportedLocale::normalize($client->language),
                idempotencyKey: $idempotencyKey,
                requireKnownExternalOutcome: true,
                organizationId: $organizationId,
            ));
        } catch (NotificationDeliveryException $exception) {
            return $exception->externalSendStarted
                ? NotificationDeliveryResult::unknown('delivery_outcome_unknown')
                : NotificationDeliveryResult::permanentFailure('channel_error');
        } catch (Throwable) {
            return NotificationDeliveryResult::unknown('delivery_outcome_unknown');
        }
    }

    private function finalize(
        Organization $organization,
        User $actor,
        MedicalSessionRecommendationDelivery $claimed,
        NotificationDeliveryResult $result,
    ): MedicalSessionRecommendationDeliveryData {
        return DB::transaction(function () use ($actor, $claimed, $organization, $result): MedicalSessionRecommendationDeliveryData {
            $delivery = MedicalSessionRecommendationDelivery::query()
                ->where('organization_id', $claimed->organization_id)
                ->whereKey($claimed->getKey())
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                throw ValidationException::withMessages(['recommendation' => 'Запись доставки рекомендации не найдена.']);
            }
            if ((string) $delivery->status !== 'claimed') {
                return MedicalSessionRecommendationDeliveryData::fromModel($delivery);
            }

            $outcome = $result->outcome === NotificationDeliveryOutcome::InFlight
                ? NotificationDeliveryOutcome::Unknown
                : $result->outcome;
            $status = match ($outcome) {
                NotificationDeliveryOutcome::Delivered => 'delivered',
                NotificationDeliveryOutcome::Unknown => 'unknown',
                NotificationDeliveryOutcome::Unavailable => 'unavailable',
                NotificationDeliveryOutcome::Suppressed => 'suppressed',
                NotificationDeliveryOutcome::Retryable, NotificationDeliveryOutcome::PermanentFailure => 'failed',
            };
            $errorCode = $outcome === NotificationDeliveryOutcome::Unknown
                ? 'delivery_outcome_unknown'
                : self::safeCode($result->errorCode);
            $now = now();

            $delivery->forceFill([
                'status' => $status,
                'provider_reference' => self::safeReference($result->providerReference),
                'error_code' => $errorCode,
                'delivered_at' => $outcome === NotificationDeliveryOutcome::Delivered ? $now : null,
                'failed_at' => $outcome === NotificationDeliveryOutcome::Delivered ? null : $now,
            ])->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.session.updated',
                targetType: MedicalSession::class,
                targetId: (string) $delivery->medical_session_id,
                metadata: [
                    'source' => 'crm',
                    'key_version' => null,
                    'updated_fields' => 'recommendation_delivery:'.$status,
                ],
            );

            return MedicalSessionRecommendationDeliveryData::fromModel($delivery, $outcome->value);
        });
    }

    private function decryptString(int $organizationId, mixed $ciphertext, int $keyVersion): ?string
    {
        if ($ciphertext === null) {
            return null;
        }
        if (! is_string($ciphertext)) {
            throw ValidationException::withMessages([
                'recommendation_draft' => 'Сохранённая рекомендация имеет неверный формат.',
            ]);
        }

        return $this->encryptor->decryptField($organizationId, $ciphertext, $keyVersion);
    }

    private static function idempotencyKey(int $organizationId, int $sessionId, int $revision): string
    {
        return 'medical-recommendation:'.$organizationId.':'.$sessionId.':'.$revision.':'.self::CHANNEL;
    }

    private static function safeCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        return preg_match('/^[a-z0-9_.:-]{1,120}$/', $value) === 1 ? $value : 'provider_error';
    }

    private static function safeReference(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = substr(trim($value), 0, 255);
        $value = preg_replace('/[^a-zA-Z0-9._:-]/', '_', $value) ?? '';

        return $value === '' ? null : $value;
    }
}
