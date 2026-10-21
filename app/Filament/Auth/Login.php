<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\Authenticatable;

final class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        $component = parent::getEmailFormComponent();

        if (! $component instanceof TextInput) {
            return $component;
        }

        return $component
            ->autocomplete('username')
            ->extraInputAttributes(['name' => 'email']);
    }

    protected function getPasswordFormComponent(): Component
    {
        $component = parent::getPasswordFormComponent();

        if (! $component instanceof TextInput) {
            return $component;
        }

        return $component->extraInputAttributes(['name' => 'password']);
    }

    public function getFormContentComponent(): Component
    {
        return parent::getFormContentComponent()->extraAttributes([
            'x-on:submit.capture' => <<<'JS'
                $wire.data.email = $el.querySelector('input[name=email]')?.value ?? ''
                $wire.data.password = $el.querySelector('input[name=password]')?.value ?? ''
            JS,
        ]);
    }

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
