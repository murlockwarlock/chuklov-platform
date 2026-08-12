<?php

namespace App\Models;

use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Specialists\Domain\Models\Specialist;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property-read Collection<int, OrganizationMembership> $memberships
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @return HasMany<OrganizationMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /** @return HasMany<Specialist, $this> */
    public function specialists(): HasMany
    {
        return $this->hasMany(Specialist::class, 'staff_user_id');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->hasPermission(OrganizationPermission::ViewAdmin);
    }

    public function membershipFor(Organization|int $organization): ?OrganizationMembership
    {
        $organizationId = $organization instanceof Organization
            ? $organization->getKey()
            : $organization;

        return $this->memberships()
            ->active()
            ->where('organization_id', $organizationId)
            ->first();
    }

    public function hasPermission(OrganizationPermission $permission, ?Organization $organization = null): bool
    {
        $memberships = $this->memberships()->active();

        if ($organization instanceof Organization) {
            $memberships->where('organization_id', $organization->getKey());
        }

        return $memberships->get()->contains(
            fn (OrganizationMembership $membership): bool => $membership->role->allows($permission),
        );
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
