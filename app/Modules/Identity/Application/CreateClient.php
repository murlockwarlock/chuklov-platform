<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateClient
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        string $fullName,
        ?string $email = null,
        ?string $phone = null,
        string $language = 'en',
        string $timezone = 'UTC',
        ?string $leadSource = null,
        ?string $referralCode = null,
    ): Client {
        $organization = $this->context->organization();
        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);

        $fullName = trim($fullName);
        $language = trim($language);
        $timezone = trim($timezone);

        if ($fullName === '' || mb_strlen($fullName) > 160) {
            throw new InvalidArgumentException('The client name is invalid.');
        }

        if ($email !== null && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 320)) {
            throw new InvalidArgumentException('The client email is invalid.');
        }

        if ($phone !== null && (trim($phone) === '' || mb_strlen($phone) > 32)) {
            throw new InvalidArgumentException('The client phone is invalid.');
        }

        if (preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $language) !== 1) {
            throw new InvalidArgumentException('The client language is invalid.');
        }

        $timezone = IanaTimezone::from($timezone)->value;

        return DB::transaction(function () use (
            $organization,
            $actor,
            $fullName,
            $email,
            $phone,
            $language,
            $timezone,
            $leadSource,
            $referralCode,
        ): Client {
            $client = new Client;
            $client->forceFill([
                'organization_id' => $organization->getKey(),
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'language' => $language,
                'timezone' => $timezone,
                'timezone_source' => 'manual',
                'lead_source' => $leadSource,
                'referral_code' => $referralCode,
            ]);
            $client->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'client.created',
                targetType: Client::class,
                targetId: (string) $client->getKey(),
                metadata: ['source' => 'application'],
            );

            return $client->refresh();
        });
    }
}
