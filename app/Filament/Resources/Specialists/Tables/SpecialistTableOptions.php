<?php

namespace App\Filament\Resources\Specialists\Tables;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;

class SpecialistTableOptions
{
    /** @return array<int, string> */
    public static function staffUsers(): array
    {
        return User::query()
            ->whereHas('memberships', function ($query): void {
                $query
                    ->where('organization_id', app(OrganizationContext::class)->id())
                    ->where('is_active', true);
            })
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
