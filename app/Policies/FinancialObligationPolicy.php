<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Organizations\Application\OrganizationContext;

final class FinancialObligationPolicy
{
    public function viewAny(User $user): bool
    {
        return app(FinanceAuthorization::class)->allowsView($user);
    }

    public function view(User $user, FinancialObligation $obligation): bool
    {
        return app(FinanceAuthorization::class)->allowsView($user)
            && (int) $obligation->organization_id === app(OrganizationContext::class)->id();
    }

    public function update(User $user, FinancialObligation $obligation): bool
    {
        return false;
    }

    public function delete(User $user, FinancialObligation $obligation): bool
    {
        return false;
    }
}
