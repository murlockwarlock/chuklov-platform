<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;

final class Login extends BaseLogin
{
    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        if (! ($user instanceof FilamentUser)) {
            return true;
        }

        $panel = Filament::getCurrentOrDefaultPanel();

        return $panel instanceof Panel
            && $user->canAccessPanel($panel)
            && $user instanceof User
            && $user->hasPermission(OrganizationPermission::ViewAdmin, app(OrganizationContext::class)->organization());
    }
}
