<?php

namespace App\Modules\Security\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Events\OrganizationCredentialReplaced;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReplaceOrganizationCredential
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<array-key, mixed> $credentials */
    public function handle(
        User $actor,
        string $provider,
        string $credentialName,
        array $credentials,
        CredentialStatus $status = CredentialStatus::Active,
    ): OrganizationCredential {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageCredentials);

        $provider = trim($provider);
        $credentialName = trim($credentialName);

        if ($provider === '' || mb_strlen($provider) > 64 || preg_match('/^[a-z0-9._-]+$/', $provider) !== 1) {
            throw new InvalidArgumentException('The credential provider is invalid.');
        }

        if ($credentialName === '' || mb_strlen($credentialName) > 100) {
            throw new InvalidArgumentException('The credential name is invalid.');
        }

        if ($credentials === []) {
            throw new InvalidArgumentException('Credential values cannot be empty.');
        }

        $this->assertSerializable($credentials);

        return DB::transaction(function () use ($organization, $actor, $provider, $credentialName, $credentials, $status): OrganizationCredential {
            $credential = OrganizationCredential::query()
                ->where('organization_id', $organization->getKey())
                ->where('provider', $provider)
                ->where('credential_name', $credentialName)
                ->first() ?? new OrganizationCredential;

            $oldRevisionId = $credential->revision_id;
            $newRevisionId = (string) Str::uuid();

            $credential->forceFill([
                'organization_id' => $organization->getKey(),
                'provider' => $provider,
                'credential_name' => $credentialName,
                'credentials' => $credentials,
                'status' => $status,
                'revision_id' => $newRevisionId,
                'last_rotated_at' => now(),
                'rotated_by_user_id' => $actor->getKey(),
            ]);
            $credential->save();

            event(new OrganizationCredentialReplaced(
                organizationId: (int) $organization->getKey(),
                provider: $provider,
                credentialId: (int) $credential->getKey(),
                revisionId: $newRevisionId,
            ));

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'organization.credential.replaced',
                targetType: OrganizationCredential::class,
                targetId: (string) $credential->getKey(),
                metadata: [
                    'provider' => $provider,
                    'credential_name' => $credentialName,
                    'status' => $status->value,
                    'old_revision_id' => $oldRevisionId,
                    'new_revision_id' => $newRevisionId,
                ],
            );

            return $credential->refresh();
        });
    }

    /** @param array<array-key, mixed> $values */
    private function assertSerializable(array $values): void
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                $this->assertSerializable($value);

                continue;
            }

            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('Credential values must be scalar or nested arrays.');
            }
        }
    }
}
