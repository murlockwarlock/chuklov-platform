<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\ValueObjects\NormalizedEmail;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class UpdateClientProfileFromCrm
{
    /** @var list<string> */
    private const FIELDS = [
        'full_name',
        'email',
        'phone',
        'language',
        'timezone',
    ];

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(User $actor, Client $client, array $attributes): Client
    {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);

        $normalized = $this->normalize($attributes);

        return DB::transaction(function () use ($actor, $client, $normalized, $organization): Client {
            $lockedClient = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (array_key_exists('email', $normalized)
                && $normalized['email'] !== $lockedClient->email
                && $lockedClient->email !== null
                && $this->hasVerifiedIdentity($lockedClient, 'email', $lockedClient->email)) {
                throw ValidationException::withMessages([
                    'email' => 'A verified email can only be changed through a new verification flow.',
                ]);
            }

            if (array_key_exists('phone', $normalized)
                && $normalized['phone'] !== $lockedClient->phone
                && $lockedClient->phone !== null
                && $this->hasVerifiedIdentity($lockedClient, 'phone', $lockedClient->phone)) {
                throw ValidationException::withMessages([
                    'phone' => 'A verified phone identity cannot be changed here.',
                ]);
            }

            $changedFields = [];

            foreach ($normalized as $field => $value) {
                if ($lockedClient->getAttribute($field) !== $value) {
                    $changedFields[] = $field;
                }
            }

            if ($changedFields !== []) {
                $lockedClient->forceFill($normalized);
                $lockedClient->save();

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'client.profile.updated',
                    targetType: Client::class,
                    targetId: (string) $lockedClient->getKey(),
                    metadata: [
                        'source' => 'crm',
                        'fields' => implode(',', $changedFields),
                    ],
                );
            }

            return $lockedClient->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    private function normalize(array $attributes): array
    {
        $unknownFields = array_diff(array_keys($attributes), self::FIELDS);

        if ($unknownFields !== []) {
            throw new InvalidArgumentException('The client profile contains an unsupported field.');
        }

        $normalized = [];

        foreach ($attributes as $field => $value) {
            if ($value !== null && ! is_string($value)) {
                throw new InvalidArgumentException('The client profile value is invalid.');
            }

            $value = $value === null ? null : trim($value);
            $value = $value === '' ? null : $value;

            if ($field === 'full_name' && ($value === null || mb_strlen($value) > 160)) {
                throw new InvalidArgumentException('The client name is invalid.');
            }

            if ($field === 'email' && $value !== null) {
                try {
                    $value = NormalizedEmail::from($value)->value;
                } catch (InvalidArgumentException) {
                    throw new InvalidArgumentException('The client email is invalid.');
                }
            }

            if ($field === 'phone' && $value !== null && mb_strlen($value) > 32) {
                throw new InvalidArgumentException('The client phone is invalid.');
            }

            if ($field === 'language' && $value !== null && preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $value) !== 1) {
                throw new InvalidArgumentException('The client language is invalid.');
            }

            if ($field === 'timezone' && $value !== null) {
                try {
                    $value = IanaTimezone::from($value)->value;
                } catch (InvalidArgumentException) {
                    throw new InvalidArgumentException('The client timezone must be an IANA timezone.');
                }
            }

            $normalized[$field] = $value;
        }

        return $normalized;
    }

    private function hasVerifiedIdentity(Client $client, string $channel, string $externalId): bool
    {
        return $client->channelIdentities()
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->where('verification_status', ChannelIdentityStatus::Verified)
            ->exists();
    }
}
