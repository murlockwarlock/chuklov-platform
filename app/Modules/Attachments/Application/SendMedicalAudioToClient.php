<?php

namespace App\Modules\Attachments\Application;

use App\Models\User;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Attachments\Domain\Models\MedicalAttachmentDelivery;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\Exceptions\NotificationDeliveryException;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMedia;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class SendMedicalAudioToClient
{
    private const CHANNEL = 'telegram';

    private const CLAIM_TIMEOUT_MINUTES = 5;

    public function __construct(
        private AttachmentAuthorization $authorization,
        private AttachmentStorageInterface $storage,
        private NotificationChannelRegistry $channels,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, MedicalAttachment $attachment): MedicalAttachmentDelivery
    {
        $claim = DB::transaction(function () use ($actor, $attachment): array {
            /** @var MedicalAttachment|null $lockedAttachment */
            $lockedAttachment = MedicalAttachment::query()
                ->where('organization_id', $attachment->organization_id)
                ->whereKey($attachment->getKey())
                ->lockForUpdate()
                ->first();
            if (! $lockedAttachment instanceof MedicalAttachment) {
                throw ValidationException::withMessages(['audio' => 'Аудиозапись не найдена.']);
            }
            if ($lockedAttachment->attachment_type !== AttachmentType::AudioRecording || $lockedAttachment->client_id === null) {
                throw ValidationException::withMessages(['audio' => 'Отправить можно только аудиозапись клиента.']);
            }

            $client = Client::query()
                ->where('organization_id', $lockedAttachment->organization_id)
                ->whereKey($lockedAttachment->client_id)
                ->firstOrFail();
            $organization = $this->authorization->authorizeManage($actor, $client);
            $idempotencyKey = self::idempotencyKey((int) $organization->getKey(), (int) $lockedAttachment->getKey());
            $delivery = MedicalAttachmentDelivery::query()
                ->where('organization_id', $organization->getKey())
                ->where('medical_attachment_id', $lockedAttachment->getKey())
                ->where('channel', self::CHANNEL)
                ->lockForUpdate()
                ->first();

            if ($delivery instanceof MedicalAttachmentDelivery) {
                if (in_array((string) $delivery->status, ['delivered', 'unknown', 'permanent'], true)) {
                    return ['delivery' => $delivery->refresh(), 'should_send' => false, 'attachment' => $lockedAttachment, 'client' => $client];
                }
                if ((string) $delivery->status === 'claimed') {
                    $claimedAt = $delivery->getRawOriginal('claimed_at');
                    if ($claimedAt !== null && now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES)->lessThanOrEqualTo(Carbon::parse($claimedAt))) {
                        return ['delivery' => $delivery->refresh(), 'should_send' => false, 'attachment' => $lockedAttachment, 'client' => $client];
                    }
                    $delivery->forceFill(['status' => 'unknown', 'error_code' => 'delivery_claim_expired', 'failed_at' => now()])->save();

                    return ['delivery' => $delivery->refresh(), 'should_send' => false, 'attachment' => $lockedAttachment, 'client' => $client];
                }
            } else {
                $delivery = new MedicalAttachmentDelivery;
            }

            $delivery->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'medical_attachment_id' => $lockedAttachment->getKey(),
                'channel' => self::CHANNEL,
                'status' => 'claimed',
                'idempotency_key' => $idempotencyKey,
                'attempt_count' => ((int) $delivery->attempt_count) + 1,
                'provider_reference' => null,
                'error_code' => null,
                'claimed_at' => now(),
                'delivered_at' => null,
                'failed_at' => null,
                'fallback_url_expires_at' => null,
            ])->save();

            return ['delivery' => $delivery->refresh(), 'should_send' => true, 'attachment' => $lockedAttachment, 'client' => $client];
        });

        if (! $claim['should_send']) {
            return $claim['delivery'];
        }

        /** @var array{result: NotificationDeliveryResult, fallback_expires_at: Carbon|null} $sent */
        $sent = $this->send($claim['attachment'], $claim['client'], $claim['delivery']->idempotency_key);

        return $this->finalize($actor, $claim['delivery'], $sent['result'], $sent['fallback_expires_at']);
    }

    /** @return array{result: NotificationDeliveryResult, fallback_expires_at: Carbon|null} */
    private function send(MedicalAttachment $attachment, Client $client, string $idempotencyKey): array
    {
        $organizationId = (int) $attachment->organization_id;
        $identity = ClientChannelIdentity::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->where('channel', self::CHANNEL)
            ->where('verification_status', ChannelIdentityStatus::Verified->value)
            ->orderBy('id')
            ->first();
        $channel = $this->channels->get(self::CHANNEL);
        if (! $identity instanceof ClientChannelIdentity || trim((string) $identity->external_id) === '') {
            return ['result' => NotificationDeliveryResult::unavailable('verified_telegram_unavailable'), 'fallback_expires_at' => null];
        }
        if ($channel === null || ! $channel->capabilities()->supportsProactiveDelivery) {
            return ['result' => NotificationDeliveryResult::unavailable('telegram_channel_unavailable'), 'fallback_expires_at' => null];
        }

        try {
            $stream = $this->storage->readStream($attachment->storage_path);
        } catch (Throwable) {
            return ['result' => NotificationDeliveryResult::retryable('private_storage_unavailable'), 'fallback_expires_at' => null];
        }

        try {
            $result = $channel->send(new NotificationMessage(
                recipientExternalId: (string) $identity->external_id,
                body: 'Аудиозапись сеанса',
                subject: null,
                locale: (string) ($client->language ?: 'ru'),
                idempotencyKey: $idempotencyKey,
                requireKnownExternalOutcome: true,
                mode: NotificationMessageMode::Image,
                mediaItems: [new NotificationMedia(
                    type: 'document',
                    stream: $stream,
                    fileName: $attachment->original_filename,
                )],
                organizationId: $organizationId,
            ));
        } catch (NotificationDeliveryException $exception) {
            $result = $exception->externalSendStarted
                ? NotificationDeliveryResult::unknown('delivery_outcome_unknown')
                : NotificationDeliveryResult::permanentFailure('telegram_channel_error');
        } catch (Throwable) {
            $result = NotificationDeliveryResult::unknown('delivery_outcome_unknown');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($result->outcome !== NotificationDeliveryOutcome::PermanentFailure
            || $result->errorCode !== 'telegram_file_too_large') {
            return ['result' => $result, 'fallback_expires_at' => null];
        }

        $expiresAt = now()->addHour();
        $url = URL::temporarySignedRoute(
            'portal.medical-audio.download',
            $expiresAt,
            ['uuid' => $attachment->uuid],
        );
        try {
            $fallback = $channel->send(new NotificationMessage(
                recipientExternalId: (string) $identity->external_id,
                body: 'Аудиозапись доступна по защищённой ссылке: '.$url,
                subject: null,
                locale: (string) ($client->language ?: 'ru'),
                idempotencyKey: $idempotencyKey.':fallback',
                requireKnownExternalOutcome: true,
                organizationId: $organizationId,
            ));
        } catch (Throwable) {
            $fallback = NotificationDeliveryResult::unknown('delivery_outcome_unknown');
        }
        if ($fallback->outcome === NotificationDeliveryOutcome::Delivered) {
            return [
                'result' => new NotificationDeliveryResult(NotificationDeliveryOutcome::Delivered, $fallback->providerReference, 'telegram_file_too_large_fallback_link'),
                'fallback_expires_at' => $expiresAt,
            ];
        }

        return ['result' => $fallback, 'fallback_expires_at' => $expiresAt];
    }

    private function finalize(
        User $actor,
        MedicalAttachmentDelivery $claimed,
        NotificationDeliveryResult $result,
        ?Carbon $fallbackExpiresAt,
    ): MedicalAttachmentDelivery {
        return DB::transaction(function () use ($actor, $claimed, $result, $fallbackExpiresAt): MedicalAttachmentDelivery {
            $delivery = MedicalAttachmentDelivery::query()
                ->where('organization_id', $claimed->organization_id)
                ->whereKey($claimed->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ((string) $delivery->status !== 'claimed') {
                return $delivery;
            }

            $outcome = $result->outcome === NotificationDeliveryOutcome::InFlight
                ? NotificationDeliveryOutcome::Unknown
                : $result->outcome;
            $status = match ($outcome) {
                NotificationDeliveryOutcome::Delivered => 'delivered',
                NotificationDeliveryOutcome::Retryable => 'retryable',
                NotificationDeliveryOutcome::PermanentFailure => 'permanent',
                NotificationDeliveryOutcome::Unavailable => 'unavailable',
                NotificationDeliveryOutcome::Suppressed => 'unavailable',
                NotificationDeliveryOutcome::Unknown => 'unknown',
                NotificationDeliveryOutcome::InFlight => 'unknown',
            };
            $delivery->forceFill([
                'status' => $status,
                'provider_reference' => self::safeReference($result->providerReference),
                'error_code' => self::safeCode($result->errorCode),
                'delivered_at' => $status === 'delivered' ? now() : null,
                'failed_at' => $status === 'delivered' ? null : now(),
                'fallback_url_expires_at' => $fallbackExpiresAt,
            ])->save();

            $this->audit->handle(
                organization: $delivery->organization()->firstOrFail(),
                actor: $actor,
                action: 'medical.session.updated',
                targetType: MedicalAttachment::class,
                targetId: (string) $delivery->medical_attachment_id,
                metadata: [
                    'source' => 'crm',
                    'key_version' => null,
                    'updated_fields' => 'audio_delivery:'.$status,
                ],
            );

            return $delivery->refresh();
        });
    }

    private static function idempotencyKey(int $organizationId, int $attachmentId): string
    {
        return 'medical-audio:'.$organizationId.':'.$attachmentId.':'.self::CHANNEL;
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
        $value = preg_replace('/[^a-zA-Z0-9._:-]/', '_', substr(trim($value), 0, 255)) ?? '';

        return $value === '' ? null : $value;
    }
}
