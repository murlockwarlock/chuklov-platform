<?php

namespace App\Modules\Security\Infrastructure\Filament;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
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

    public function verifyRecoveryCode(#[SensitiveParameter] string $recoveryCode, ?HasAppAuthenticationRecovery $user = null): bool
    {
        $user ??= Filament::auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $organization = $this->preAuthenticationOrganization($user);
        if (! $organization instanceof Organization) {
            return false;
        }

        return DB::transaction(function () use ($recoveryCode, $user, $organization): bool {
            try {
                $verified = parent::verifyRecoveryCode($recoveryCode, $user);
            } catch (LogicException) {
                return false;
            }
            if (! $verified) {
                return false;
            }

            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'privileged.mfa.recovery_code.used',
                targetType: User::class,
                targetId: (string) $user->getKey(),
            );

            return true;
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

    private function preAuthenticationOrganization(User $user): ?Organization
    {
        try {
            $organization = $this->organizationContext->organization();
        } catch (LogicException) {
            return null;
        }

        if ((int) config('tenancy.default_organization_id') !== (int) $organization->getKey()) {
            return null;
        }

        $memberships = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->active()
            ->limit(2)
            ->get();

        return $memberships->count() === 1 ? $organization : null;
    }
}
