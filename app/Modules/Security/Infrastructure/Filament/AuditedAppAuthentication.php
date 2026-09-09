<?php

namespace App\Modules\Security\Infrastructure\Filament;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use LogicException;
use PragmaRX\Google2FAQRCode\Google2FA;
use SensitiveParameter;

class AuditedAppAuthentication extends AppAuthentication
{
    public function __construct(
        Google2FA $google2FA,
        private readonly OrganizationContext $organizationContext,
        private readonly RecordAuditEvent $audit,
    ) {
        parent::__construct($google2FA);
    }

    public function saveSecret(HasAppAuthentication $user, #[SensitiveParameter] ?string $secret): void
    {
        $action = $secret === null
            ? 'privileged.mfa.disabled'
            : 'privileged.mfa.enabled';

        DB::transaction(function () use ($user, $secret, $action): void {
            parent::saveSecret($user, $secret);
            $this->record($user, $action);
        });
    }

    public function saveRecoveryCodes(HasAppAuthenticationRecovery $user, #[SensitiveParameter] ?array $codes): void
    {
        DB::transaction(function () use ($user, $codes): void {
            parent::saveRecoveryCodes($user, $codes);
            $this->record($user, 'privileged.mfa.recovery_codes.updated');
        });
    }

    private function record(object $user, string $action): void
    {
        $actor = Filament::auth()->user();

        if (! $user instanceof User || ! $actor instanceof User) {
            throw new LogicException('Privileged MFA auditing requires the application user model.');
        }

        $this->audit->handle(
            $this->organizationContext->organization(),
            $actor,
            $action,
            User::class,
            (string) $user->getKey(),
        );
    }
}
