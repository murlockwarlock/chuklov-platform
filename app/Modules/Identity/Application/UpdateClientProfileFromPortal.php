<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\ValueObjects\NormalizedEmail;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class UpdateClientProfileFromPortal
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
        private readonly OrganizationFeatureGate $features,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $confirmedFields
     */
    public function handle(Client $client, array $attributes, array $confirmedFields): Client
    {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $this->features->authorize($organization, OrganizationFeature::ClientRecords);

        $confirmedFields = array_values(array_intersect($confirmedFields, self::FIELDS));
        $normalized = $this->normalize($attributes);

        if (array_key_exists('email', $normalized)
            && $this->hasVerifiedEmailIdentity($client)
            && $normalized['email'] !== $client->email) {
            throw ValidationException::withMessages([
                'email' => 'A verified email can only be changed through a new verification flow.',
            ]);
        }

        foreach ($normalized as $field => $value) {
            $current = $client->getAttribute($field);

            if ($this->hasKnownValue($current) && ! in_array($field, $confirmedFields, true)) {
                throw ValidationException::withMessages([
                    $field => 'Please confirm the existing value before saving it.',
                ]);
            }

        }

        return DB::transaction(function () use ($client, $normalized, $organization): Client {
            $changedFields = [];

            foreach ($normalized as $field => $value) {
                if ($client->getAttribute($field) !== $value) {
                    $changedFields[] = $field;
                }
            }

            if ($changedFields !== []) {
                $client->forceFill($normalized);
                $client->save();

                $this->audit->handle(
                    organization: $organization,
                    actor: null,
                    action: 'client.profile.updated',
                    targetType: Client::class,
                    targetId: (string) $client->getKey(),
                    metadata: [
                        'source' => 'portal',
                        'fields' => implode(',', $changedFields),
                    ],
                );
            }

            return $client->refresh();
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

            if ($field === 'full_name' && $value !== null && mb_strlen($value) > 160) {
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

    private function hasKnownValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private function hasVerifiedEmailIdentity(Client $client): bool
    {
        if (! $this->hasKnownValue($client->email)) {
            return false;
        }

        try {
            $email = NormalizedEmail::from((string) $client->email)->value;
        } catch (InvalidArgumentException) {
            return false;
        }

        return $client->channelIdentities()
            ->where('channel', 'email')
            ->where('external_id', $email)
            ->where('verification_status', ChannelIdentityStatus::Verified)
            ->exists();
    }
}
