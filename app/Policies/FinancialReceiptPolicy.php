<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Models\FinancialReceipt;
use App\Modules\Organizations\Application\OrganizationContext;

final class FinancialReceiptPolicy
{
    public function view(User $user, FinancialReceipt $receipt): bool
    {
        return app(FinanceAuthorization::class)->allowsView($user)
            && (int) $receipt->organization_id === app(OrganizationContext::class)->id();
    }
}
