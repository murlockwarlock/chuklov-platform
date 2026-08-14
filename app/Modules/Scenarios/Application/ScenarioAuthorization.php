<?php

namespace App\Modules\Scenarios\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Scenarios\Domain\Enums\ScenarioAudienceType;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRecipientStrategy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ScenarioAuthorization
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function organization(): Organization
    {
        return $this->context->organization();
    }

    public function authorizeView(User $actor): Organization
    {
        $organization = $this->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewScenarios);

        return $organization;
    }

    public function authorizeManage(User $actor): Organization
    {
        $organization = $this->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScenarios);

        return $organization;
    }

    public function assertOwned(Model $model): void
    {
        try {
            if ((int) $model->getAttribute('organization_id') !== $this->context->id()) {
                throw new AuthorizationException('The scenario record is outside the current organization.');
            }
        } catch (LogicException) {
            throw new AuthorizationException('The scenario organization is not resolved.');
        }
    }

    public function assertRecipientStrategy(ScenarioRecipientStrategy $strategy): void
    {
        if ($strategy->type !== ScenarioAudienceType::Members) {
            return;
        }

        $memberIds = [];

        foreach ($strategy->values as $value) {
            if (is_int($value) || is_string($value)) {
                $memberIds[] = (int) $value;
            }
        }
        $activeMembers = OrganizationMembership::query()
            ->where('organization_id', $this->context->id())
            ->active()
            ->whereIn('user_id', $memberIds)
            ->count();

        if ($activeMembers !== count($memberIds)) {
            throw ValidationException::withMessages([
                'recipient_strategy' => 'Выбранный сотрудник не состоит в организации или неактивен.',
            ]);
        }
    }
}
