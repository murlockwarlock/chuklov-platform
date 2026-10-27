<?php

namespace App\Modules\B2B\Application;

use App\Models\User;
use App\Modules\B2B\Domain\Enums\B2bFormConfigurationStatus;
use App\Modules\B2B\Domain\Models\B2bFormConfiguration;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

final readonly class SaveB2bFormDraft
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private ValidateB2bFormDefinition $validator,
        private RecordAuditEvent $audit,
    ) {}

    /** @param array<int, mixed> $fields */
    public function handle(User $actor, array $fields): B2bFormConfiguration
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        $fields = $this->validator->handle($fields);

        return DB::transaction(function () use ($actor, $organization, $fields): B2bFormConfiguration {
            $draft = B2bFormConfiguration::query()
                ->where('organization_id', $organization->getKey())
                ->where('status', B2bFormConfigurationStatus::Draft->value)
                ->latest('version')
                ->lockForUpdate()
                ->first();
            if ($draft === null) {
                $draft = new B2bFormConfiguration([
                    'organization_id' => $organization->getKey(),
                    'version' => (int) B2bFormConfiguration::query()->where('organization_id', $organization->getKey())->max('version') + 1,
                    'status' => B2bFormConfigurationStatus::Draft,
                    'created_by_user_id' => $actor->getKey(),
                ]);
            }
            $draft->forceFill(['fields' => $fields])->save();
            $this->audit->handle($organization, $actor, 'b2b.form.draft_saved', B2bFormConfiguration::class, (string) $draft->getKey(), [
                'version' => $draft->version,
                'field_count' => count($fields),
            ]);

            return $draft->refresh();
        });
    }
}
